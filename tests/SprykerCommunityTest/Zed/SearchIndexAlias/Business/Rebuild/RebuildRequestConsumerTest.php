<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rebuild;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use PhpAmqpLib\Message\AMQPMessage;
use Spryker\Client\Queue\QueueClient;
use Spryker\Client\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestConsumer;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real RabbitMQ broker AND real database (a real rollout row must exist for
 * `processPayload()` to look up). `RebuildOrchestratorInterface` itself is stubbed here on purpose: the
 * orchestrator's own real behavior belongs to its own dedicated test coverage, not this class's -- what
 * this class alone is responsible for is correctly reading a message off the real queue, correctly
 * resolving/rebuilding the transfers, and correctly acking.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rebuild
 * @group RebuildRequestConsumerTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 * @group NeedsDatabase
 */
class RebuildRequestConsumerTest extends Unit
{
    protected BrokerConnectionProvider $brokerConnectionProvider;

    protected SearchIndexAliasToQueueClientBridge $queueClient;

    protected SearchIndexAliasConfig $searchIndexAliasConfig;

    protected function _before(): void
    {
        $this->brokerConnectionProvider = new BrokerConnectionProvider(new RabbitMqConfig());
        $this->queueClient = new SearchIndexAliasToQueueClientBridge(new QueueClient());
        $this->searchIndexAliasConfig = new SearchIndexAliasConfig();
    }

    protected function _after(): void
    {
        $this->drainQueue($this->searchIndexAliasConfig->getRebuildRequestQueueName());
    }

    public function testConsumeOneReturnsFalseWhenTheQueueIsEmpty(): void
    {
        // The real queue name is shared with this project's own live rebuild-worker pipeline (no
        // scheduler/worker running in this lean dev environment -- see project memory), so a prior
        // manual/GUI-triggered rebuild can leave real messages sitting in it. Purge first so this test's
        // "empty" precondition is actually true, rather than assuming a fresh queue.
        $this->drainQueue($this->searchIndexAliasConfig->getRebuildRequestQueueName());

        $capturingOrchestrator = $this->createCapturingOrchestrator();
        $rebuildRequestConsumer = new RebuildRequestConsumer(
            $this->queueClient,
            $this->searchIndexAliasConfig,
            new SearchIndexAliasRepository(),
            $capturingOrchestrator,
        );

        $wasProcessed = $rebuildRequestConsumer->consumeOne();

        $this->assertFalse($wasProcessed);
        $this->assertSame(0, $capturingOrchestrator->callCount);
    }

    public function testConsumeOneProcessesARealQueuedMessageAgainstARealRolloutRow(): void
    {
        $searchIndexRolloutTransfer = (new SearchIndexAliasEntityManager())->createRollout(
            (new SearchIndexRolloutTransfer())
                ->setSearchIndexScope(
                    (new SearchIndexScopeTransfer())
                        ->setSourceIdentifier('page')
                        ->setStoreName('DE')
                        ->setAliasName('phpunit_consumer_page'),
                )
                ->setStatus(SharedSearchIndexAliasConfig::STATUS_BUILDING)
                ->setTargetIndexName('phpunit_consumer_page_20260101_120000'),
        );
        $this->publishRawPayload($this->searchIndexAliasConfig->getRebuildRequestQueueName(), [
            'idSearchIndexRollout' => $searchIndexRolloutTransfer->getIdSearchIndexRollout(),
            'sourceIdentifier' => 'page',
            'storeName' => 'DE',
            'aliasName' => 'phpunit_consumer_page',
            'targetMappingProperties' => null,
            'optimizeForBulkLoad' => false,
        ]);

        $capturingOrchestrator = $this->createCapturingOrchestrator();
        $rebuildRequestConsumer = new RebuildRequestConsumer(
            $this->queueClient,
            $this->searchIndexAliasConfig,
            new SearchIndexAliasRepository(),
            $capturingOrchestrator,
        );

        $wasProcessed = $rebuildRequestConsumer->consumeOne();

        $this->assertTrue($wasProcessed);
        $this->assertSame(1, $capturingOrchestrator->callCount);
        $this->assertSame($searchIndexRolloutTransfer->getIdSearchIndexRollout(), $capturingOrchestrator->lastRolloutTransfer?->getIdSearchIndexRollout());
        $this->assertSame('page', $capturingOrchestrator->lastScopeTransfer?->getSourceIdentifier());
    }

    public function testConsumeOneAcksAndDropsAMessageForARolloutRowThatNoLongerExists(): void
    {
        $this->publishRawPayload($this->searchIndexAliasConfig->getRebuildRequestQueueName(), [
            'idSearchIndexRollout' => 999999999,
            'sourceIdentifier' => 'page',
            'storeName' => 'DE',
            'aliasName' => 'phpunit_consumer_orphan',
            'targetMappingProperties' => null,
            'optimizeForBulkLoad' => false,
        ]);

        $capturingOrchestrator = $this->createCapturingOrchestrator();
        $rebuildRequestConsumer = new RebuildRequestConsumer(
            $this->queueClient,
            $this->searchIndexAliasConfig,
            new SearchIndexAliasRepository(),
            $capturingOrchestrator,
        );

        $wasProcessed = $rebuildRequestConsumer->consumeOne();

        $this->assertTrue($wasProcessed);
        $this->assertSame(0, $capturingOrchestrator->callCount);
    }

    protected function createCapturingOrchestrator(): RebuildOrchestratorInterface
    {
        // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter
        return new class implements RebuildOrchestratorInterface {
            public int $callCount = 0;

            public ?SearchIndexRolloutTransfer $lastRolloutTransfer = null;

            public ?SearchIndexScopeTransfer $lastScopeTransfer = null;

            public function start(
                SearchIndexScopeTransfer $searchIndexScopeTransfer,
                ?string $triggeredByUser = null,
                ?array $targetMappingProperties = null,
                bool $optimizeForBulkLoad = false,
            ): SearchIndexRolloutTransfer {
                return new SearchIndexRolloutTransfer();
            }

            public function requestRebuildAsync(
                SearchIndexScopeTransfer $searchIndexScopeTransfer,
                ?string $triggeredByUser = null,
                ?array $targetMappingProperties = null,
                bool $optimizeForBulkLoad = false,
            ): SearchIndexRolloutTransfer {
                return new SearchIndexRolloutTransfer();
            }

            public function executeQueuedRebuild(
                SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
                SearchIndexScopeTransfer $searchIndexScopeTransfer,
                ?array $targetMappingProperties,
                bool $optimizeForBulkLoad,
            ): SearchIndexRolloutTransfer {
                $this->callCount++;
                $this->lastRolloutTransfer = $searchIndexRolloutTransfer;
                $this->lastScopeTransfer = $searchIndexScopeTransfer;

                return $searchIndexRolloutTransfer;
            }

            public function flip(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
            {
                return $searchIndexRolloutTransfer;
            }

            public function abort(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer
            {
                return $searchIndexRolloutTransfer;
            }
        };
        // phpcs:enable
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function publishRawPayload(string $queueName, array $payload): void
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->basic_publish(new AMQPMessage((string)json_encode($payload)), '', $queueName);
        $channel->close();
        $connection->close();
    }

    protected function drainQueue(string $queueName): void
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        while (($amqpMessage = $channel->basic_get($queueName, false)) !== null) {
            $channel->basic_ack((int)$amqpMessage->getDeliveryTag());
        }

        $channel->close();
        $connection->close();
    }
}
