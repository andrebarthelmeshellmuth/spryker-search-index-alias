<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\QueueSendMessageTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * `Client\Queue` (`spryker/queue`), not raw AMQP -- this is an ordinary, permanent, single fixed-name
 * task queue, exactly the shape `spryker/queue` is designed for. Unlike this package's OTHER raw-AMQP
 * uses (`MirrorQueueBinder`/`MirrorQueueDrain`, which need dynamic per-rollout exchange binding and
 * high-volume `basic_get` draining that neither `Client\Queue` nor the RabbitMQ Management API support --
 * see those classes' own doc blocks), nothing here needs anything below `Client\Queue`'s abstraction.
 */
class RebuildRequestPublisher implements RebuildRequestPublisherInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface $queueClient
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     */
    public function __construct(
        protected SearchIndexAliasToQueueClientInterface $queueClient,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     * @param bool $fromSchema See `RebuildOrchestratorInterface::start()`'s own doc block.
     */
    public function publish(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
        bool $fromSchema = true,
    ): void {
        $body = json_encode([
            'idSearchIndexRollout' => $searchIndexRolloutTransfer->getIdSearchIndexRolloutOrFail(),
            'sourceIdentifier' => $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            'storeName' => $searchIndexScopeTransfer->getStoreNameOrFail(),
            'aliasName' => $searchIndexScopeTransfer->getAliasNameOrFail(),
            'targetMappingProperties' => $targetMappingProperties,
            'optimizeForBulkLoad' => $optimizeForBulkLoad,
            'fromSchema' => $fromSchema,
        ]);

        $this->queueClient->sendMessage(
            $this->searchIndexAliasConfig->getRebuildRequestQueueName(),
            (new QueueSendMessageTransfer())->setBody((string)$body),
        );
    }
}
