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
use GuzzleHttp\Client as GuzzleClient;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTargetQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Client\Queue\QueueClient;
use Spryker\Client\RabbitMq\RabbitMqConfig as ClientRabbitMqConfig;
use Spryker\Zed\RabbitMq\RabbitMqConfig;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoader;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\DeployFlipRunner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifier;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestrator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollback;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunityTest\Zed\SearchIndexAlias\Business\Deploy\Fixture\FakeIndexEnumerator;

/**
 * FULL-PIPELINE INTEGRATION TEST — real database, real Elasticsearch/OpenSearch, AND real RabbitMQ, same
 * shape as RebuildOrchestratorTest (which this class's `flipAllPending()` ultimately delegates to). Uses
 * a hand-written IndexEnumeratorInterface fake (not a mocking library -- this suite uses none) rather
 * than the real project's IndexEnumerator, since `findPendingFlipCandidates()`'s own filtering logic
 * (READY + flip-pending, nothing else) is what's worth isolating here, deliberately independent of
 * whatever scopes this project happens to have configured today.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Deploy
 * @group DeployFlipRunnerTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 * @group NeedsBroker
 */
class DeployFlipRunnerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_deployflip_';

    protected ElasticaClient $elasticaClient;

    protected AliasManager $aliasManager;

    protected IndexCloner $indexCloner;

    protected BrokerConnectionProvider $brokerConnectionProvider;

    protected function _before(): void
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $this->elasticaClient = (new ElasticaClientProvider($searchElasticsearchConfig))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider($searchElasticsearchConfig));
        $this->indexCloner = new IndexCloner(new ElasticaClientProvider($searchElasticsearchConfig));
        $this->brokerConnectionProvider = new BrokerConnectionProvider(new ClientRabbitMqConfig());

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
        SpySearchIndexDeployRollbackTargetQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }

    protected function _after(): void
    {
        foreach ($this->elasticaClient->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->elasticaClient->getIndex($row['index'])->delete();
        }

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
        SpySearchIndexDeployRollbackTargetQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }

    public function testFindPendingFlipCandidatesOnlyReturnsReadyAndFlipPendingRollouts(): void
    {
        $repository = new SearchIndexAliasRepository();
        $entityManager = new SearchIndexAliasEntityManager();
        $rolloutFinisher = new RolloutFinisher($entityManager);

        // Distinct STORE names, not just alias suffixes -- the concurrency guard's active_scope_key is
        // derived from (sourceIdentifier, storeName), so scopes sharing both would collide on the same
        // key the moment more than one has a non-terminal rollout at once.
        $neverBuiltScope = $this->createScope('never_built', 'DE1');
        $readyNotPendingScope = $this->createScope('ready_not_pending', 'DE2');
        $readyPendingScope = $this->createScope('ready_pending', 'DE3');

        $entityManager->createRollout($this->buildRollout($readyNotPendingScope, SharedSearchIndexAliasConfig::STATUS_READY));
        $readyPendingRollout = $rolloutFinisher->markFlipPending(
            $entityManager->createRollout($this->buildRollout($readyPendingScope, SharedSearchIndexAliasConfig::STATUS_READY)),
        );

        $deployFlipRunner = new DeployFlipRunner(
            new FakeIndexEnumerator([$neverBuiltScope, $readyNotPendingScope, $readyPendingScope]),
            $repository,
            $this->createOrchestrator(),
            $this->createAliasRollback(),
            $this->createPendingRollbackTargetManager(),
        );

        $candidates = $deployFlipRunner->findPendingFlipCandidates();

        $this->assertCount(1, $candidates);
        $this->assertSame($readyPendingRollout->getIdSearchIndexRollout(), $candidates[0]->getIdSearchIndexRollout());
    }

    public function testFlipAllPendingFlipsEveryPendingScopeAndClearsTheFlag(): void
    {
        $aliasName = static::TEST_PREFIX . 'flipall';
        $liveIndexName = $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope('flipall');
        $repository = new SearchIndexAliasRepository();
        $rebuildOrchestrator = $this->createOrchestrator();
        $rolloutFinisher = new RolloutFinisher(new SearchIndexAliasEntityManager());

        $rolloutAfterStart = $rebuildOrchestrator->start($searchIndexScopeTransfer, fromSchema: false);
        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_READY, $rolloutAfterStart->getStatus());
        $rolloutFinisher->markFlipPending($rolloutAfterStart);

        $deployFlipRunner = new DeployFlipRunner(
            new FakeIndexEnumerator([$searchIndexScopeTransfer]),
            $repository,
            $rebuildOrchestrator,
            $this->createAliasRollback(),
            $this->createPendingRollbackTargetManager(),
        );

        $results = $deployFlipRunner->flipAllPending();

        $this->assertCount(1, $results);
        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FLIPPED, $results[0]->getStatus());
        $this->assertFalse($results[0]->getFlipPending());
        $this->assertSame([$rolloutAfterStart->getTargetIndexNameOrFail()], $this->aliasManager->getIndicesForAlias($aliasName));
        $this->assertNotSame($liveIndexName, $rolloutAfterStart->getTargetIndexNameOrFail());

        // A second call finds nothing left to flip -- the flag was cleared as part of the first flip.
        $this->assertSame([], $deployFlipRunner->findPendingFlipCandidates());
    }

    public function testFlipAllPendingExecutesAPendingRollbackTargetAndClearsTheFlag(): void
    {
        $aliasName = static::TEST_PREFIX . 'rollback';
        $liveIndexName = $this->fakeLiveIndex($aliasName);
        $olderIndexName = $aliasName . '_older';
        $this->indexCloner->cloneMappingAndSettings($liveIndexName, $olderIndexName);
        $searchIndexScopeTransfer = $this->createScope('rollback');
        $pendingRollbackTargetManager = $this->createPendingRollbackTargetManager();
        $pendingRollbackTargetManager->mark($searchIndexScopeTransfer, $olderIndexName, 'phpunit');

        $deployFlipRunner = new DeployFlipRunner(
            new FakeIndexEnumerator([$searchIndexScopeTransfer]),
            new SearchIndexAliasRepository(),
            $this->createOrchestrator(),
            $this->createAliasRollback(),
            $pendingRollbackTargetManager,
        );

        $candidatesBeforeFlip = $deployFlipRunner->findPendingFlipCandidates();
        $this->assertCount(1, $candidatesBeforeFlip);
        $this->assertSame($olderIndexName, $candidatesBeforeFlip[0]->getTargetIndexName());
        // Still live -- flagging alone never touches the alias.
        $this->assertSame([$liveIndexName], $this->aliasManager->getIndicesForAlias($aliasName));

        $results = $deployFlipRunner->flipAllPending();

        $this->assertCount(1, $results);
        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FLIPPED, $results[0]->getStatus());
        $this->assertSame([$olderIndexName], $this->aliasManager->getIndicesForAlias($aliasName));
        $this->assertNull($pendingRollbackTargetManager->findFor($searchIndexScopeTransfer));
    }

    protected function buildRollout(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $status): SearchIndexRolloutTransfer
    {
        return (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($searchIndexScopeTransfer)
            ->setStatus($status)
            ->setTargetIndexName($searchIndexScopeTransfer->getAliasNameOrFail() . '_20260101_120000');
    }

    /**
     * Fakes a "live" index for a throwaway alias by cloning THIS project's own real live "page"/DE
     * mapping onto it -- see RebuildOrchestratorTest's identical helper for why a bare, dynamically-mapped
     * scratch index cannot survive real product data.
     */
    protected function fakeLiveIndex(string $aliasName): string
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $realLiveAliasName = (new CanonicalIndexNameResolver($searchElasticsearchConfig))->resolve('page', 'DE');
        $realLiveIndexNames = $this->aliasManager->getIndicesForAlias($realLiveAliasName);

        $liveIndexName = $aliasName . '_live';
        $this->indexCloner->cloneMappingAndSettings($realLiveIndexNames[0], $liveIndexName);
        $this->aliasManager->createAlias($aliasName, $liveIndexName);

        return $liveIndexName;
    }

    protected function createScope(string $aliasSuffix, string $storeName = 'DE'): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_PREFIX . 'source')
            ->setStoreName($storeName)
            ->setAliasName(static::TEST_PREFIX . $aliasSuffix);
    }

    /**
     * Same construction as RebuildOrchestratorTest::createOrchestrator() -- see its own doc block for why
     * the throwaway sourceIdentifier is routed to the real "page" source tables/sync exchange.
     */
    protected function createOrchestrator(): RebuildOrchestrator
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $searchIndexAliasConfig = new class extends SearchIndexAliasConfig {
            public function getSpySearchSourceTables(): array
            {
                $pageTables = parent::getSpySearchSourceTables()['page'] ?? [];

                return ['phpunit_deployflip_source' => $pageTables];
            }

            public function getSyncExchangeNames(): array
            {
                return ['phpunit_deployflip_source' => parent::getSyncExchangeNames()['page']];
            }
        };
        $repository = new SearchIndexAliasRepository();
        $entityManager = new SearchIndexAliasEntityManager();
        $rolloutGuard = new RolloutGuard($repository);
        $rolloutStarter = new RolloutStarter($rolloutGuard, $this->aliasManager, $entityManager);
        $rolloutFinisher = new RolloutFinisher($entityManager);
        $mirrorQueueBinder = new MirrorQueueBinder(
            new RabbitMqManagementClient(new GuzzleClient(), new RabbitMqConfig(), new BrokerConnectionProvider(new ClientRabbitMqConfig())),
            $searchIndexAliasConfig,
        );
        $mirrorQueueDrain = new MirrorQueueDrain($this->brokerConnectionProvider, new ElasticaClientProvider($searchElasticsearchConfig));
        $bulkLoader = new BulkLoader(new ElasticaClientProvider($searchElasticsearchConfig), $searchIndexAliasConfig);

        return new RebuildOrchestrator(
            $rolloutStarter,
            $rolloutFinisher,
            new IndexNameBuilder($searchIndexAliasConfig),
            $this->indexCloner,
            new MappingDiffClassifier(),
            $bulkLoader,
            $mirrorQueueBinder,
            $mirrorQueueDrain,
            $this->aliasManager,
            $entityManager,
            new RebuildRequestPublisher(new SearchIndexAliasToQueueClientBridge(new QueueClient()), $searchIndexAliasConfig),
            false,
        );
    }

    protected function createAliasRollback(): AliasRollback
    {
        $repository = new SearchIndexAliasRepository();
        $entityManager = new SearchIndexAliasEntityManager();
        $rolloutGuard = new RolloutGuard($repository);

        return new AliasRollback(
            new RolloutStarter($rolloutGuard, $this->aliasManager, $entityManager),
            new RolloutFinisher($entityManager),
            $this->aliasManager,
            $rolloutGuard,
        );
    }

    protected function createPendingRollbackTargetManager(): PendingRollbackTargetManager
    {
        $entityManager = new SearchIndexAliasEntityManager();

        return new PendingRollbackTargetManager(
            $this->aliasManager,
            new SearchIndexAliasRepository(),
            $entityManager,
            new RolloutFinisher($entityManager),
        );
    }
}
