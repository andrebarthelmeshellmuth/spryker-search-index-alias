<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rollback;

use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollback;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real database AND real Elasticsearch/OpenSearch. The one behavior most worth
 * protecting live: the atomic alias switch to an already-existing (not newly-built) index, and that the
 * three pure-input-rejection paths never persist a rollout row (see the class's own doc block for why).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rollback
 * @group AliasRollbackTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 */
class AliasRollbackTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_rollback_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected AliasRollback $aliasRollback;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
        $repository = new SearchIndexAliasRepository();
        $entityManager = new SearchIndexAliasEntityManager();
        $this->aliasRollback = new AliasRollback(
            new RolloutStarter(new RolloutGuard($repository), $this->aliasManager, $entityManager),
            new RolloutFinisher($entityManager),
            $this->aliasManager,
            new RolloutGuard($repository),
        );

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }

    public function testRollbackToIndexAtomicallySwitchesTheAliasToTheGivenExistingIndex(): void
    {
        $aliasName = static::TEST_PREFIX . 'switch';
        $currentIndexName = $aliasName . '_20260102_120000';
        $olderIndexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($currentIndexName)->create();
        $this->client->getIndex($olderIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $currentIndexName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        $searchIndexRolloutTransfer = $this->aliasRollback->rollbackToIndex($searchIndexScopeTransfer, $olderIndexName);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FLIPPED, $searchIndexRolloutTransfer->getStatus());
        $this->assertNotNull($searchIndexRolloutTransfer->getIdSearchIndexRollout());
        $this->assertSame([$olderIndexName], $this->aliasManager->getIndicesForAlias($aliasName));
        $this->assertStringContainsString('rolled back', (string)$searchIndexRolloutTransfer->getOutcome());
    }

    public function testRollbackToIndexRejectsAScopeWithNoLiveIndexWithoutPersistingARolloutRow(): void
    {
        $searchIndexScopeTransfer = $this->createScope(static::TEST_PREFIX . 'never_adopted');

        $searchIndexRolloutTransfer = $this->aliasRollback->rollbackToIndex($searchIndexScopeTransfer, static::TEST_PREFIX . 'never_adopted_20260101_120000');

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FAILED, $searchIndexRolloutTransfer->getStatus());
        $this->assertNull($searchIndexRolloutTransfer->getIdSearchIndexRollout());
        $this->assertStringContainsString('not been adopted', (string)$searchIndexRolloutTransfer->getFailureReason());
    }

    public function testRollbackToIndexRejectsTargetingTheAlreadyLiveIndexWithoutPersistingARolloutRow(): void
    {
        $aliasName = static::TEST_PREFIX . 'already_live';
        $liveIndexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($liveIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        $searchIndexRolloutTransfer = $this->aliasRollback->rollbackToIndex($searchIndexScopeTransfer, $liveIndexName);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FAILED, $searchIndexRolloutTransfer->getStatus());
        $this->assertNull($searchIndexRolloutTransfer->getIdSearchIndexRollout());
        $this->assertStringContainsString('already the live index', (string)$searchIndexRolloutTransfer->getFailureReason());
    }

    public function testRollbackToIndexRejectsATargetThatNoLongerExistsWithoutPersistingARolloutRow(): void
    {
        $aliasName = static::TEST_PREFIX . 'pruned_target';
        $liveIndexName = $aliasName . '_20260102_120000';
        $this->client->getIndex($liveIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);

        $searchIndexRolloutTransfer = $this->aliasRollback->rollbackToIndex($searchIndexScopeTransfer, $aliasName . '_never_existed');

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FAILED, $searchIndexRolloutTransfer->getStatus());
        $this->assertNull($searchIndexRolloutTransfer->getIdSearchIndexRollout());
        $this->assertStringContainsString('no longer exists', (string)$searchIndexRolloutTransfer->getFailureReason());
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_PREFIX . 'source')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }
}
