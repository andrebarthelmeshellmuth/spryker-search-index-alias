<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Index;

use ArrayObject;
use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexLister;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\ScopeIndexOverview;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real database AND real Elasticsearch/OpenSearch. The one behavior most worth
 * protecting live: correlating physical indices (from the cluster) with rollout rows (from the database)
 * by target index name, and resolving the right `status` for each (current/replaced/skipped/unknown).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Index
 * @group ScopeIndexOverviewTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 */
class ScopeIndexOverviewTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_overview_';

    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_overview_source';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    public function testGetIndicesForScopeMarksTheCurrentlyAliasedIndexAsCurrent(): void
    {
        $aliasName = static::TEST_PREFIX . 'current';
        $indexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($indexName)->create();
        $this->aliasManager->createAlias($aliasName, $indexName);

        $collection = $this->createScopeIndexOverview()->getIndicesForScope($this->createScope($aliasName));

        $physicalIndexTransfers = $collection->getSearchIndexPhysicalIndices();
        $this->assertCount(1, $physicalIndexTransfers);
        $this->assertTrue($physicalIndexTransfers[0]->getIsCurrentAlias());
        $this->assertSame(SharedSearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_CURRENT, $physicalIndexTransfers[0]->getStatus());
    }

    public function testGetIndicesForScopeMarksAnIndexThatARolloutFlippedAwayFromAsReplaced(): void
    {
        $aliasName = static::TEST_PREFIX . 'replaced';
        $oldIndexName = $aliasName . '_20260101_120000';
        $newIndexName = $aliasName . '_20260102_120000';
        $this->client->getIndex($oldIndexName)->create();
        $this->client->getIndex($newIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $newIndexName);

        $this->seedRollout($aliasName, $oldIndexName, SharedSearchIndexAliasConfig::STATUS_FLIPPED);

        $collection = $this->createScopeIndexOverview()->getIndicesForScope($this->createScope($aliasName));

        $oldRow = $this->findRowByIndexName($collection->getSearchIndexPhysicalIndices(), $oldIndexName);
        $this->assertNotNull($oldRow);
        $this->assertFalse($oldRow->getIsCurrentAlias());
        $this->assertSame(SharedSearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_REPLACED, $oldRow->getStatus());
    }

    public function testGetIndicesForScopeMarksAnAbortedBuildsIndexAsSkipped(): void
    {
        $aliasName = static::TEST_PREFIX . 'skipped';
        $abandonedIndexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($abandonedIndexName)->create();

        $this->seedRollout($aliasName, $abandonedIndexName, SharedSearchIndexAliasConfig::STATUS_ABORTED);

        $collection = $this->createScopeIndexOverview()->getIndicesForScope($this->createScope($aliasName));

        $row = $this->findRowByIndexName($collection->getSearchIndexPhysicalIndices(), $abandonedIndexName);
        $this->assertNotNull($row);
        $this->assertSame(SharedSearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_SKIPPED, $row->getStatus());
    }

    public function testGetIndicesForScopeMarksAnIndexWithNoCorrelatedRolloutAsUnknown(): void
    {
        $aliasName = static::TEST_PREFIX . 'orphan';
        $orphanIndexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($orphanIndexName)->create();

        $collection = $this->createScopeIndexOverview()->getIndicesForScope($this->createScope($aliasName));

        $row = $this->findRowByIndexName($collection->getSearchIndexPhysicalIndices(), $orphanIndexName);
        $this->assertNotNull($row);
        $this->assertSame(SharedSearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_UNKNOWN, $row->getStatus());
    }

    public function testGetIndicesForScopeReturnsAnEmptyCollectionWhenNoPhysicalIndexExistsYet(): void
    {
        $collection = $this->createScopeIndexOverview()->getIndicesForScope($this->createScope(static::TEST_PREFIX . 'never_created'));

        $this->assertCount(0, $collection->getSearchIndexPhysicalIndices());
    }

    /**
     * @param \ArrayObject<int, \Generated\Shared\Transfer\SearchIndexPhysicalIndexTransfer> $physicalIndexTransfers
     * @param string $indexName
     */
    protected function findRowByIndexName(ArrayObject $physicalIndexTransfers, string $indexName): ?object
    {
        foreach ($physicalIndexTransfers as $physicalIndexTransfer) {
            if ($physicalIndexTransfer->getIndexName() === $indexName) {
                return $physicalIndexTransfer;
            }
        }

        return null;
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->setStoreName('PHPUNIT')
            ->setAliasName($aliasName);
    }

    protected function seedRollout(string $aliasName, string $targetIndexName, string $status): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($this->createScope($aliasName))
            ->setStatus($status)
            ->setTargetIndexName($targetIndexName);

        return (new SearchIndexAliasEntityManager())->createRollout($searchIndexRolloutTransfer);
    }

    protected function createScopeIndexOverview(): ScopeIndexOverview
    {
        $searchIndexAliasConfig = new SearchIndexAliasConfig();

        return new ScopeIndexOverview(
            new PhysicalIndexLister(new ElasticaClientProvider(new SearchElasticsearchConfig()), new IndexNameBuilder($searchIndexAliasConfig)),
            new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig())),
            new IndexCloner(new ElasticaClientProvider(new SearchElasticsearchConfig())),
            new SearchIndexAliasRepository(),
        );
    }
}
