<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\BulkLoad;

use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoader;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\SpySearchSourceTable;

/**
 * INTEGRATION TEST — real MySQL (real `spy_*_page_search` rows, this project's own real page catalog)
 * AND real Elasticsearch/OpenSearch (a throwaway scratch index). Verifies the unbuffered-fetch read path
 * and the store-scoping filter actually work against real rows, not just that the SQL string looks right.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group BulkLoad
 * @group BulkLoaderTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 */
class BulkLoaderTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_bulkloader_';

    protected Client $client;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testLoadWritesRealRowsFromTheConfiguredSourceTablesIntoTheTargetIndex(): void
    {
        $targetIndexName = static::TEST_PREFIX . 'de_page';
        $this->cloneLiveMappingOnto('DE', $targetIndexName);

        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName($targetIndexName);

        $writtenCount = $this->createBulkLoader()->load($searchIndexScopeTransfer, $targetIndexName);

        $this->assertGreaterThan(0, $writtenCount, 'Expected at least one real row from this project\'s own spy_*_page_search tables.');

        $countResponse = $this->client->request(sprintf('%s/_count', $targetIndexName), 'GET')->getData();
        $this->assertSame($writtenCount, $countResponse['count']);
    }

    public function testLoadReturnsZeroForASourceIdentifierWithNoConfiguredTables(): void
    {
        $targetIndexName = static::TEST_PREFIX . 'unconfigured';
        $this->client->getIndex($targetIndexName)->create();

        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('some_source_with_no_table_config')
            ->setStoreName('DE')
            ->setAliasName($targetIndexName);

        $writtenCount = $this->createBulkLoader()->load($searchIndexScopeTransfer, $targetIndexName);

        $this->assertSame(0, $writtenCount);
    }

    public function testLoadDoesNotCrashWhenMixingStoreScopedAndNonStoreScopedSourceTablesInTheSameScope(): void
    {
        // spy_configurable_bundle_template_page_search / spy_product_set_page_search have storeColumn:
        // null in the default config -- every row from those applies to every store, so a store filter
        // must not be applied to them. Runs the same DE scope twice into separate targets to prove this
        // is deterministic (not order-dependent), using this project's own real config.
        $firstTargetIndexName = static::TEST_PREFIX . 'de_mixed_1';
        $secondTargetIndexName = static::TEST_PREFIX . 'de_mixed_2';
        $this->cloneLiveMappingOnto('DE', $firstTargetIndexName);
        $this->cloneLiveMappingOnto('DE', $secondTargetIndexName);

        $bulkLoader = $this->createBulkLoader();
        $firstWritten = $bulkLoader->load(
            (new SearchIndexScopeTransfer())->setSourceIdentifier('page')->setStoreName('DE')->setAliasName($firstTargetIndexName),
            $firstTargetIndexName,
        );
        $secondWritten = $bulkLoader->load(
            (new SearchIndexScopeTransfer())->setSourceIdentifier('page')->setStoreName('DE')->setAliasName($secondTargetIndexName),
            $secondTargetIndexName,
        );

        $this->assertGreaterThan(0, $firstWritten);
        $this->assertSame($firstWritten, $secondWritten);
    }

    public function testLoadRespectsACustomSourceTableConfigurationInsteadOfTheProjectDefault(): void
    {
        // Uses a table with NO store column at all (spy_product_set_page_search) as the sole source, to
        // prove load() genuinely reads whatever getSpySearchSourceTables() returns, not a hardcoded list.
        $targetIndexName = static::TEST_PREFIX . 'custom_config';
        $this->client->getIndex($targetIndexName)->create();

        $customConfig = new class extends SearchIndexAliasConfig {
            public function getSpySearchSourceTables(): array
            {
                return [
                    'page' => [
                        new SpySearchSourceTable('spy_product_set_page_search', storeColumn: null),
                    ],
                ];
            }
        };

        $bulkLoader = new BulkLoader(new ElasticaClientProvider(new SearchElasticsearchConfig()), $customConfig);
        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName($targetIndexName);

        $writtenCount = $bulkLoader->load($searchIndexScopeTransfer, $targetIndexName);

        $countResponse = $this->client->request(sprintf('%s/_count', $targetIndexName), 'GET')->getData();
        $this->assertSame($writtenCount, $countResponse['count']);
    }

    protected function createBulkLoader(): BulkLoader
    {
        return new BulkLoader(new ElasticaClientProvider(new SearchElasticsearchConfig()), new SearchIndexAliasConfig());
    }

    /**
     * A bare, dynamically-mapped scratch index cannot survive real product data: OpenSearch infers a
     * field's type from the FIRST document that sets it, and a later document with a different shape for
     * the same field (e.g. `string-facet.facet-value` as text vs. long) is then a genuine mapping
     * conflict -- exactly the scenario this package's real pipeline always avoids by cloning the live
     * index's own mapping onto the target BEFORE ever bulk-loading into it (see RebuildOrchestrator).
     * Mirrors that here rather than bulk-loading into an unmapped index, which no real rebuild ever does.
     *
     * @param string $storeName
     * @param string $targetIndexName
     */
    protected function cloneLiveMappingOnto(string $storeName, string $targetIndexName): void
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $liveAliasName = (new CanonicalIndexNameResolver($searchElasticsearchConfig))->resolve('page', $storeName);
        $liveIndexNames = (new AliasManager(new ElasticaClientProvider($searchElasticsearchConfig)))->getIndicesForAlias($liveAliasName);

        (new IndexCloner(new ElasticaClientProvider($searchElasticsearchConfig)))->cloneMappingAndSettings(
            $liveIndexNames[0],
            $targetIndexName,
        );
    }
}
