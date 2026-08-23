<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Prune;

use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletionQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTargetQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexDeleter;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices. Covers the three guards that matter
 * most: the currently-aliased index is refused (AliasManager's own job), the active rollout's target
 * index is refused too, and so is a flagged pending rollback target -- the two ways a manual delete can
 * pull an index out from under `search-index-alias:deploy-flip`.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Prune
 * @group IndexDeleterTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class IndexDeleterTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_deleter_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected IndexDeleter $indexDeleter;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
        $this->indexDeleter = new IndexDeleter(
            $this->aliasManager,
            new SearchIndexAliasEntityManager(),
            new SearchIndexAliasRepository(),
        );
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }

        SpySearchIndexRolloutQuery::create()->filterBySourceIdentifier(static::TEST_PREFIX . '%', Criteria::LIKE)->delete();
        SpySearchIndexDeletionQuery::create()->filterBySourceIdentifier(static::TEST_PREFIX . '%', Criteria::LIKE)->delete();
        SpySearchIndexDeployRollbackTargetQuery::create()->filterBySourceIdentifier(static::TEST_PREFIX . '%', Criteria::LIKE)->delete();
    }

    public function testDeleteIndexDeletesAnUnaliasedIndexAndRecordsIt(): void
    {
        $aliasName = static::TEST_PREFIX . 'ok';
        $indexName = $this->createIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        $this->indexDeleter->deleteIndex($searchIndexScopeTransfer, $indexName, 'phpunit-user');

        $this->assertFalse($this->aliasManager->indexExists($indexName));

        $deletionHistory = (new SearchIndexAliasRepository())->getDeletionHistoryForScope(
            static::TEST_PREFIX . 'source',
            'DE',
        );
        $this->assertCount(1, $deletionHistory);
        $this->assertSame($indexName, $deletionHistory[0]->getIndexName());
        $this->assertSame('phpunit-user', $deletionHistory[0]->getTriggeredByUser());
    }

    public function testDeleteIndexRefusesTheActiveRolloutsTargetIndex(): void
    {
        $aliasName = static::TEST_PREFIX . 'active';
        $indexName = $this->createIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        (new SearchIndexAliasEntityManager())->createRollout(
            (new SearchIndexRolloutTransfer())
                ->setSearchIndexScope($searchIndexScopeTransfer)
                ->setStatus(SearchIndexAliasConfig::STATUS_READY)
                ->setTargetIndexName($indexName),
        );

        $this->expectException(AliasOperationFailedException::class);

        $this->indexDeleter->deleteIndex($searchIndexScopeTransfer, $indexName);
    }

    public function testDeleteIndexRefusesAPendingRollbackTargetIndex(): void
    {
        // Real incident this reproduces: an index flagged via the Overview page's "Flag for next
        // deploy" (rollback row) got manually deleted afterward, leaving deploy-flip to discover the
        // dangling flag only when it actually ran.
        $aliasName = static::TEST_PREFIX . 'rollback';
        $indexName = $this->createIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        (new SearchIndexAliasEntityManager())->savePendingRollbackTarget(
            (new SearchIndexDeployRollbackTargetTransfer())
                ->setSearchIndexScope($searchIndexScopeTransfer)
                ->setTargetIndexName($indexName)
                ->setTriggeredByUser('zed-gui'),
        );

        $this->expectException(AliasOperationFailedException::class);

        $this->indexDeleter->deleteIndex($searchIndexScopeTransfer, $indexName);
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_PREFIX . 'source')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }

    protected function createIndex(string $aliasName): string
    {
        $indexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($indexName)->create();

        return $indexName;
    }
}
