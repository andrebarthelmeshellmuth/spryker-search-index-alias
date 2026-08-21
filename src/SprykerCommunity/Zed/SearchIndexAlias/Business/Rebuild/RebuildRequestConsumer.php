<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\RabbitMqConsumerOptionTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class RebuildRequestConsumer implements RebuildRequestConsumerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface $queueClient
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface $rebuildOrchestrator
     */
    public function __construct(
        protected SearchIndexAliasToQueueClientInterface $queueClient,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
        protected RebuildOrchestratorInterface $rebuildOrchestrator,
    ) {
    }

    public function consumeOne(): bool
    {
        $queueReceiveMessageTransfer = $this->queueClient->receiveMessage(
            $this->searchIndexAliasConfig->getRebuildRequestQueueName(),
            // `noAck` deliberately left null (-> false): messages are only acknowledged below, after
            // `processPayload()` has actually run, so a crash mid-processing leaves the message on the
            // queue to be retried rather than silently lost.
            ['rabbitmq' => new RabbitMqConsumerOptionTransfer()],
        );
        $queueSendMessageTransfer = $queueReceiveMessageTransfer->getQueueMessage();
        $body = $queueSendMessageTransfer?->getBody();

        if ($body === null) {
            return false;
        }

        $payload = json_decode($body, true);

        if (is_array($payload)) {
            $this->processPayload($payload);
        }

        $this->queueClient->acknowledge($queueReceiveMessageTransfer);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function processPayload(array $payload): void
    {
        $idSearchIndexRollout = (int)($payload['idSearchIndexRollout'] ?? 0);
        $searchIndexRolloutTransfer = $this->searchIndexAliasRepository->findRolloutById($idSearchIndexRollout);

        if ($searchIndexRolloutTransfer === null) {
            // Rollout row is gone (e.g. manually deleted from the DB) -- nothing sane to do with an
            // orphaned request, drop it rather than fail loudly for a state that can no longer be acted on.
            return;
        }

        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier((string)($payload['sourceIdentifier'] ?? ''))
            ->setStoreName((string)($payload['storeName'] ?? ''))
            ->setAliasName((string)($payload['aliasName'] ?? ''));

        $targetMappingProperties = $payload['targetMappingProperties'] ?? null;

        $this->rebuildOrchestrator->executeQueuedRebuild(
            $searchIndexRolloutTransfer,
            $searchIndexScopeTransfer,
            is_array($targetMappingProperties) ? $targetMappingProperties : null,
            (bool)($payload['optimizeForBulkLoad'] ?? false),
        );
    }
}
