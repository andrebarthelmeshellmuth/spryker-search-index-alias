<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Prune;

use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexLister;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexPruner;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices. The one behavior most worth protecting
 * live: the currently-aliased index is never a deletion candidate, and the oldest UNALIASED indices
 * beyond the keep count are the ones actually deleted (sorted by name, which sorts by embedded timestamp).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Prune
 * @group IndexPrunerTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class IndexPrunerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_pruner_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testPruneScopeDeletesOnlyTheOldestUnaliasedIndicesBeyondTheKeepCount(): void
    {
        $aliasName = static::TEST_PREFIX . 'page';
        // 4 old (unaliased) indices + 1 current (aliased) -- keep count of 2 should delete the 2 oldest
        // unaliased ones, leaving 2 unaliased + the 1 aliased current index untouched.
        $oldest = $this->createIndex($aliasName, '20260101_120000');
        $second = $this->createIndex($aliasName, '20260102_120000');
        $third = $this->createIndex($aliasName, '20260103_120000');
        $newest = $this->createIndex($aliasName, '20260104_120000');
        $current = $this->createIndex($aliasName, '20260105_120000');
        $this->aliasManager->createAlias($aliasName, $current);

        $deleted = $this->createPruner(2)->pruneScope($this->createScope($aliasName));

        $this->assertSame([$oldest, $second], $deleted);
        $this->assertFalse($this->aliasManager->indexExists($oldest));
        $this->assertFalse($this->aliasManager->indexExists($second));
        $this->assertTrue($this->aliasManager->indexExists($third));
        $this->assertTrue($this->aliasManager->indexExists($newest));
        $this->assertTrue($this->aliasManager->indexExists($current), 'The currently-aliased index must never be a deletion candidate.');
    }

    public function testPruneScopeDeletesNothingWhenTheCandidateCountIsAtOrBelowTheKeepCount(): void
    {
        $aliasName = static::TEST_PREFIX . 'small';
        $first = $this->createIndex($aliasName, '20260101_120000');
        $current = $this->createIndex($aliasName, '20260102_120000');
        $this->aliasManager->createAlias($aliasName, $current);

        $deleted = $this->createPruner(3)->pruneScope($this->createScope($aliasName));

        $this->assertSame([], $deleted);
        $this->assertTrue($this->aliasManager->indexExists($first));
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }

    protected function createIndex(string $aliasName, string $timestampSuffix): string
    {
        $indexName = $aliasName . '_' . $timestampSuffix;
        $this->client->getIndex($indexName)->create();

        return $indexName;
    }

    protected function createPruner(int $keepIndicesCount): IndexPruner
    {
        $searchIndexAliasConfig = new class ($keepIndicesCount) extends SearchIndexAliasConfig {
            public function __construct(protected int $keepIndicesCount)
            {
            }

            public function getKeepIndicesCount(): int
            {
                return $this->keepIndicesCount;
            }
        };

        return new IndexPruner(
            new PhysicalIndexLister(new ElasticaClientProvider(new SearchElasticsearchConfig()), new IndexNameBuilder($searchIndexAliasConfig)),
            new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig())),
            $searchIndexAliasConfig,
        );
    }
}
