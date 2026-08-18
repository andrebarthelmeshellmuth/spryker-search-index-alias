<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Deploy;

use Codeception\Test\Unit;
use Elastica\Client as ElasticaClient;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTargetQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real database AND real Elasticsearch/OpenSearch. The two behaviors most worth
 * protecting: the two pre-flight rejections (target doesn't exist, target is already live), and the
 * mutual exclusion with a pending rebuild-flip on the same scope (see this class's own doc block).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Deploy
 * @group PendingRollbackTargetManagerTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 */
class PendingRollbackTargetManagerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_pendingrollback_';

    protected ElasticaClient $elasticaClient;

    protected AliasManager $aliasManager;

    protected PendingRollbackTargetManager $pendingRollbackTargetManager;

    protected function _before(): void
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $this->elasticaClient = (new ElasticaClientProvider($searchElasticsearchConfig))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider($searchElasticsearchConfig));
        $entityManager = new SearchIndexAliasEntityManager();
        $this->pendingRollbackTargetManager = new PendingRollbackTargetManager(
            $this->aliasManager,
            new SearchIndexAliasRepository(),
            $entityManager,
            new RolloutFinisher($entityManager),
        );

        $this->cleanUp();
    }

    protected function _after(): void
    {
        foreach ($this->elasticaClient->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->elasticaClient->getIndex($row['index'])->delete();
        }

        $this->cleanUp();
    }

    public function testMarkRejectsATargetThatDoesNotExist(): void
    {
        $this->expectException(RollbackTargetNotApplicableException::class);

        $this->pendingRollbackTargetManager->mark($this->createScope('missing'), static::TEST_PREFIX . 'never_existed');
    }

    public function testMarkRejectsTheAlreadyLiveIndex(): void
    {
        $aliasName = static::TEST_PREFIX . 'already_live';
        $liveIndexName = $aliasName . '_20260101_120000';
        $this->elasticaClient->getIndex($liveIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);

        $this->expectException(RollbackTargetNotApplicableException::class);

        $this->pendingRollbackTargetManager->mark($this->createScope('already_live'), $liveIndexName);
    }

    public function testMarkPersistsAndFindForReturnsTheTarget(): void
    {
        $aliasName = static::TEST_PREFIX . 'persist';
        $liveIndexName = $aliasName . '_live';
        $olderIndexName = $aliasName . '_older';
        $this->elasticaClient->getIndex($liveIndexName)->create();
        $this->elasticaClient->getIndex($olderIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);
        $searchIndexScopeTransfer = $this->createScope('persist', $aliasName);

        $this->pendingRollbackTargetManager->mark($searchIndexScopeTransfer, $olderIndexName, 'phpunit');

        $this->assertSame($olderIndexName, $this->pendingRollbackTargetManager->findFor($searchIndexScopeTransfer));
    }

    public function testUnmarkDeletesThePendingTarget(): void
    {
        $aliasName = static::TEST_PREFIX . 'unmark';
        $liveIndexName = $aliasName . '_live';
        $olderIndexName = $aliasName . '_older';
        $this->elasticaClient->getIndex($liveIndexName)->create();
        $this->elasticaClient->getIndex($olderIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);
        $searchIndexScopeTransfer = $this->createScope('unmark', $aliasName);
        $this->pendingRollbackTargetManager->mark($searchIndexScopeTransfer, $olderIndexName);

        $this->pendingRollbackTargetManager->unmark($searchIndexScopeTransfer);

        $this->assertNull($this->pendingRollbackTargetManager->findFor($searchIndexScopeTransfer));
    }

    public function testMarkClearsAnyExistingFlipPendingOnTheActiveReadyRollout(): void
    {
        $aliasName = static::TEST_PREFIX . 'mutex';
        $liveIndexName = $aliasName . '_live';
        $olderIndexName = $aliasName . '_older';
        $this->elasticaClient->getIndex($liveIndexName)->create();
        $this->elasticaClient->getIndex($olderIndexName)->create();
        $this->aliasManager->createAlias($aliasName, $liveIndexName);
        $searchIndexScopeTransfer = $this->createScope('mutex', $aliasName);

        $entityManager = new SearchIndexAliasEntityManager();
        $rolloutFinisher = new RolloutFinisher($entityManager);
        $readyRollout = $entityManager->createRollout(
            (new SearchIndexRolloutTransfer())
                ->setSearchIndexScope($searchIndexScopeTransfer)
                ->setStatus(SharedSearchIndexAliasConfig::STATUS_READY)
                ->setTargetIndexName($liveIndexName),
        );
        $pendingRollout = $rolloutFinisher->markFlipPending($readyRollout);
        $this->assertTrue($pendingRollout->getFlipPending());

        $this->pendingRollbackTargetManager->mark($searchIndexScopeTransfer, $olderIndexName);

        $refetchedRollout = (new SearchIndexAliasRepository())->findRolloutById($pendingRollout->getIdSearchIndexRolloutOrFail());
        $this->assertNotNull($refetchedRollout);
        $this->assertFalse($refetchedRollout->getFlipPending());
    }

    protected function createScope(string $aliasSuffix, ?string $aliasName = null): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_PREFIX . 'source')
            ->setStoreName('DE')
            ->setAliasName($aliasName ?? static::TEST_PREFIX . $aliasSuffix);
    }

    protected function cleanUp(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
        SpySearchIndexDeployRollbackTargetQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }
}
