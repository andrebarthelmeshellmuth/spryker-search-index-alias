<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rebuild;

use Codeception\Test\Unit;
use Elastica\Client as ElasticaClient;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use GuzzleHttp\Client as GuzzleClient;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
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
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifier;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestrator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin\TargetIndexSettingsExpanderPluginInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * FULL-PIPELINE INTEGRATION TEST — real database, real Elasticsearch/OpenSearch, AND real RabbitMQ.
 * This is the class every other Business-layer class in this package exists to serve; the one behavior
 * genuinely worth protecting end-to-end is that a BUILDING rollout against a real (faked-live) scope
 * converges to a real, queryable target index without ever writing to the real live index, and that
 * `flip()`/`abort()` leave the cluster and the rollout row in the state their own doc blocks promise.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rebuild
 * @group RebuildOrchestratorTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 * @group NeedsBroker
 */
class RebuildOrchestratorTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_orchestrator_';

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
    }

    protected function _after(): void
    {
        foreach ($this->elasticaClient->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->elasticaClient->getIndex($row['index'])->delete();
        }

        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_PREFIX . 'source')
            ->delete();
    }

    public function testStartBuildsARealTargetAndReachesReadyThenFlipMakesItTheRealLiveIndex(): void
    {
        $scopeSuffix = 'flip';
        $aliasName = static::TEST_PREFIX . $scopeSuffix;
        $liveIndexName = $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $rebuildOrchestrator = $this->createOrchestrator(false);

        $rolloutAfterStart = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_READY, $rolloutAfterStart->getStatus());
        $this->assertNotNull($rolloutAfterStart->getTargetIndexName());
        $this->assertGreaterThan(0, $rolloutAfterStart->getActualDocumentCount());
        $this->assertTrue($this->elasticaClient->getIndex($rolloutAfterStart->getTargetIndexNameOrFail())->exists());
        // Still live -- BUILDING never touches the alias.
        $this->assertSame([$liveIndexName], $this->aliasManager->getIndicesForAlias($aliasName));

        $rolloutAfterFlip = $rebuildOrchestrator->flip($rolloutAfterStart);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FLIPPED, $rolloutAfterFlip->getStatus());
        $this->assertSame([$rolloutAfterStart->getTargetIndexNameOrFail()], $this->aliasManager->getIndicesForAlias($aliasName));
    }

    public function testStartFailsWithNoLiveIndexWhenTheScopeHasNeverBeenAdopted(): void
    {
        $searchIndexScopeTransfer = $this->createScope(static::TEST_PREFIX . 'never_adopted');
        $rebuildOrchestrator = $this->createOrchestrator(false);

        $searchIndexRolloutTransfer = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FAILED, $searchIndexRolloutTransfer->getStatus());
        $this->assertStringContainsString('no live index', (string)$searchIndexRolloutTransfer->getFailureReason());
    }

    public function testAbortLeavesTheLiveIndexUntouchedAndMarksTheRolloutAborted(): void
    {
        $aliasName = static::TEST_PREFIX . 'abort';
        $liveIndexName = $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $rebuildOrchestrator = $this->createOrchestrator(false);
        $rolloutAfterStart = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $rolloutAfterAbort = $rebuildOrchestrator->abort($rolloutAfterStart, 'operator cancelled');

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_ABORTED, $rolloutAfterAbort->getStatus());
        $this->assertSame([$liveIndexName], $this->aliasManager->getIndicesForAlias($aliasName));
        // Target is left in place for inspection -- see this class's own doc block.
        $this->assertTrue($this->elasticaClient->getIndex($rolloutAfterStart->getTargetIndexNameOrFail())->exists());
    }

    public function testStartWithAutoFlipEnabledReachesFlippedWithoutAnExplicitFlipCall(): void
    {
        $aliasName = static::TEST_PREFIX . 'autoflip';
        $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $rebuildOrchestrator = $this->createOrchestrator(true);

        $searchIndexRolloutTransfer = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_FLIPPED, $searchIndexRolloutTransfer->getStatus());
        $this->assertSame([$searchIndexRolloutTransfer->getTargetIndexNameOrFail()], $this->aliasManager->getIndicesForAlias($aliasName));
    }

    public function testRequestRebuildAsyncPublishesToTheRealQueueAndExecuteQueuedRebuildCompletesIt(): void
    {
        $aliasName = static::TEST_PREFIX . 'async';
        $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $rebuildOrchestrator = $this->createOrchestrator(false);

        $rolloutAfterRequest = $rebuildOrchestrator->requestRebuildAsync($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_BUILDING, $rolloutAfterRequest->getStatus());

        $queueName = (new SearchIndexAliasConfig())->getRebuildRequestQueueName();
        $payload = $this->consumeOneRawMessage($queueName);
        $this->assertNotNull($payload);
        $this->assertSame($rolloutAfterRequest->getIdSearchIndexRollout(), $payload['idSearchIndexRollout']);

        $rolloutAfterExecute = $rebuildOrchestrator->executeQueuedRebuild($rolloutAfterRequest, $searchIndexScopeTransfer, null, false);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_READY, $rolloutAfterExecute->getStatus());
    }

    public function testTargetIndexSettingsExpanderPluginsAreAppliedToTheRebuiltTargetIndexSettings(): void
    {
        $aliasName = static::TEST_PREFIX . 'expander';
        $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $expanderPlugin = new class implements TargetIndexSettingsExpanderPluginInterface {
            /**
             * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $searchIndexScopeTransfer is mandated by TargetIndexSettingsExpanderPluginInterface.
             *
             * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
             * @param array<string, mixed> $settings
             *
             * @return array<string, mixed>
             */
            public function expand(SearchIndexScopeTransfer $searchIndexScopeTransfer, array $settings): array
            {
                $settings['refresh_interval'] = '30s';

                return $settings;
            }
        };
        $rebuildOrchestrator = $this->createOrchestrator(false, [$expanderPlugin]);

        $searchIndexRolloutTransfer = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_READY, $searchIndexRolloutTransfer->getStatus());
        $targetIndexName = $searchIndexRolloutTransfer->getTargetIndexNameOrFail();
        $targetSettings = $this->elasticaClient->request(sprintf('%s/_settings', $targetIndexName), 'GET')->getData();
        $this->assertSame('30s', $targetSettings[$targetIndexName]['settings']['index']['refresh_interval']);
    }

    public function testAnEmptyTargetIndexSettingsExpanderPluginStackLeavesTargetSettingsAnUnmodifiedCloneOfLive(): void
    {
        $aliasName = static::TEST_PREFIX . 'no_expander';
        $this->fakeLiveIndex($aliasName);
        $searchIndexScopeTransfer = $this->createScope($aliasName);
        $rebuildOrchestrator = $this->createOrchestrator(false);

        $searchIndexRolloutTransfer = $rebuildOrchestrator->start($searchIndexScopeTransfer);

        $this->assertSame(SharedSearchIndexAliasConfig::STATUS_READY, $searchIndexRolloutTransfer->getStatus());
        $targetIndexName = $searchIndexRolloutTransfer->getTargetIndexNameOrFail();
        $targetSettings = $this->elasticaClient->request(sprintf('%s/_settings', $targetIndexName), 'GET')->getData();
        $this->assertArrayNotHasKey('refresh_interval', $targetSettings[$targetIndexName]['settings']['index']);
    }

    /**
     * Fakes a "live" index for a throwaway alias by cloning THIS project's own real live "page"/DE
     * mapping onto it -- a bare, dynamically-mapped scratch index cannot survive real product data (see
     * BulkLoaderTest's own doc block for the confirmed live mapping-conflict failure mode this avoids).
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

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_PREFIX . 'source')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }

    /**
     * @param bool $isAutoFlipEnabled
     * @param array<\SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin\TargetIndexSettingsExpanderPluginInterface> $targetIndexSettingsExpanderPlugins
     */
    protected function createOrchestrator(bool $isAutoFlipEnabled, array $targetIndexSettingsExpanderPlugins = []): RebuildOrchestrator
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        // This test's scope deliberately uses a throwaway sourceIdentifier (to avoid colliding with the
        // real "page" rollout history), so the config is overridden to route that throwaway identifier
        // to the same real page source tables/sync exchange `getSpySearchSourceTables()['page']` and
        // `getSyncExchangeNames()['page']` already point at -- used everywhere below instead of a plain
        // `new SearchIndexAliasConfig()`.
        $searchIndexAliasConfig = new class extends SearchIndexAliasConfig {
            public function getSpySearchSourceTables(): array
            {
                $pageTables = parent::getSpySearchSourceTables()['page'] ?? [];

                return ['phpunit_orchestrator_source' => $pageTables];
            }

            public function getSyncExchangeNames(): array
            {
                return ['phpunit_orchestrator_source' => parent::getSyncExchangeNames()['page']];
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
            new RebuildRequestPublisher($this->brokerConnectionProvider, $searchIndexAliasConfig),
            $isAutoFlipEnabled,
            $targetIndexSettingsExpanderPlugins,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function consumeOneRawMessage(string $queueName): ?array
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();

        try {
            $channel->queue_declare($queueName, false, true, false, false);
            $amqpMessage = $channel->basic_get($queueName, false);

            if ($amqpMessage === null) {
                return null;
            }

            $channel->basic_ack((int)$amqpMessage->getDeliveryTag());
            $decoded = json_decode($amqpMessage->getBody(), true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            $channel->close();
            $connection->close();
        }
    }
}
