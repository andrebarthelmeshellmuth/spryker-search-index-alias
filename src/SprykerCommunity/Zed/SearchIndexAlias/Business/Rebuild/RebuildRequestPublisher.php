<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use PhpAmqpLib\Message\AMQPMessage;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * Plain AMQP, deliberately not the RabbitMQ Management API this package uses elsewhere (AliasManager's
 * mirror queue) -- this queue never needs exchange binding, it's a single static worker queue published
 * to directly by name via the default exchange, exactly like a classic task queue.
 */
class RebuildRequestPublisher implements RebuildRequestPublisherInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface $brokerConnectionProvider
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     */
    public function __construct(
        protected BrokerConnectionProviderInterface $brokerConnectionProvider,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function publish(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
    ): void {
        $queueName = $this->searchIndexAliasConfig->getRebuildRequestQueueName();

        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();

        try {
            $channel->queue_declare($queueName, false, true, false, false);

            $body = json_encode([
                'idSearchIndexRollout' => $searchIndexRolloutTransfer->getIdSearchIndexRolloutOrFail(),
                'sourceIdentifier' => $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
                'storeName' => $searchIndexScopeTransfer->getStoreNameOrFail(),
                'aliasName' => $searchIndexScopeTransfer->getAliasNameOrFail(),
                'targetMappingProperties' => $targetMappingProperties,
                'optimizeForBulkLoad' => $optimizeForBulkLoad,
            ]);

            $channel->basic_publish(
                new AMQPMessage((string)$body, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]),
                '',
                $queueName,
            );
        } finally {
            $channel->close();
            $connection->close();
        }
    }
}
