<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror;

use Elastica\Document;
use Elastica\Exception\ResponseException;
use Elastica\Index;
use PhpAmqpLib\Channel\AMQPChannel;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;

/**
 * Real AMQP protocol consumption (via `basic_get`, in a loop until the queue reports empty), not the
 * Management HTTP API's "get messages" debug endpoint -- that endpoint is explicitly documented by
 * RabbitMQ itself as unsuitable for anything but manual inspection, and this drains real production
 * message volume.
 *
 * Message body shape (`{"write": {"key": ..., "value": {...document...}, "params": {...}, "store": ...}}`
 * or the `"delete"` equivalent) verified against `Spryker\Zed\Synchronization\Business\Search\SynchronizationSearch`
 * / `QueueMessageCreator` source -- `value` is already the fully rendered document body (the exact same
 * shape as `spy_*_search.data`), and `key` is the same stable ID BulkLoader writes as `_id`, so a write
 * drained here correctly overwrites (never duplicates) what the bulk load already wrote.
 *
 * The mirror queue's binding (see `MirrorQueueBinder`) is scoped by sourceIdentifier only, not by store --
 * core's `sync.search.<sourceIdentifier>` exchange (e.g. `sync.search.product`) carries every store's
 * writes for that sourceIdentifier, and the binding uses an empty routing key, so this queue receives
 * every store's traffic, not just the scope being rebuilt. `applyDeduplicated()` filters by the message's
 * own `store` field (present when Dynamic Store Mode is on -- see `SynchronizationSearch::STORE`) against
 * the scope's store before writing anything, so cross-store messages are drained (acknowledged, so they
 * don't pile up) but never applied to the wrong target index. A message with no `store` field at all
 * (Dynamic Store Mode off, i.e. a genuinely single-store install) is always applied -- there is no other
 * store it could belong to. Store, not locale, is the scoping unit -- `SynchronizationSearch`'s sync
 * payload and `CanonicalIndexNameResolver`'s index naming both operate on (store, sourceIdentifier) only;
 * a store with multiple locales keeps every locale's documents in the same index/queue (locale lives
 * inside each document's fields), not in a separate physical index or queue.
 */
class MirrorQueueDrain implements MirrorQueueDrainInterface
{
    /**
     * @var string
     */
    protected const TYPE_WRITE = 'write';

    /**
     * @var string
     */
    protected const TYPE_DELETE = 'delete';

    /**
     * @var string
     */
    protected const STORE = 'store';

    /**
     * Safety bound on one drain pass -- a scope receiving genuinely more than this many writes between
     * two drain calls during a rebuild is a sign the rebuild is not keeping up, not something to loop
     * forever trying to catch in a single pass. The orchestrator's own repeated-drain loop (P5) is what
     * actually converges; this bound just keeps any ONE call finite.
     *
     * @var int
     */
    protected const MAX_MESSAGES_PER_PASS = 50000;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface $brokerConnectionProvider
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface $elasticaClientProvider
     */
    public function __construct(
        protected BrokerConnectionProviderInterface $brokerConnectionProvider,
        protected ElasticaClientProviderInterface $elasticaClientProvider,
    ) {
    }

    /**
     * @param string $mirrorQueueName
     * @param string $targetIndexName
     * @param string $storeName
     */
    public function drain(string $mirrorQueueName, string $targetIndexName, string $storeName): int
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();

        try {
            [$messages, $deliveryTags] = $this->fetchMessages($channel, $mirrorQueueName);

            if ($messages === []) {
                return 0;
            }

            $appliedCount = $this->applyDeduplicated($messages, $targetIndexName, $storeName);
            $this->acknowledge($channel, $deliveryTags);

            return $appliedCount;
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /**
     * @param \PhpAmqpLib\Channel\AMQPChannel $channel
     * @param string $queueName
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, int|string>}
     */
    protected function fetchMessages(AMQPChannel $channel, string $queueName): array
    {
        $messages = [];
        $deliveryTags = [];

        for ($i = 0; $i < static::MAX_MESSAGES_PER_PASS; $i++) {
            $amqpMessage = $channel->basic_get($queueName, false);

            if ($amqpMessage === null) {
                break;
            }

            $decoded = json_decode($amqpMessage->getBody(), true);

            if (is_array($decoded)) {
                $messages[] = $decoded;
            }

            $deliveryTags[] = $amqpMessage->getDeliveryTag();
        }

        return [$messages, $deliveryTags];
    }

    /**
     * Last message wins per key, regardless of whether it was a write or a delete -- a document
     * written then deleted (or vice versa) within the same drain batch must end up in whichever state
     * the LAST operation left it in, matching what would have happened had these gone through the live
     * index directly.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $targetIndexName
     * @param string $storeName
     *
     * @return int Number of distinct keys actually written/deleted for $storeName, after filtering out
     *  other stores' messages and deduplicating -- see {@see MirrorQueueDrainInterface::drain()} on why
     *  this, not the raw $messages count, is what the caller needs back.
     */
    protected function applyDeduplicated(array $messages, string $targetIndexName, string $storeName): int
    {
        [$documentsToWrite, $idsToDelete] = $this->partitionLatestByKey($messages, $storeName);

        if ($documentsToWrite === [] && $idsToDelete === []) {
            return 0;
        }

        $index = $this->elasticaClientProvider->getClient()->getIndex($targetIndexName);

        if ($documentsToWrite !== []) {
            $index->addDocuments($documentsToWrite);
        }

        $this->deleteByIds($index, $idsToDelete);

        $index->refresh();

        return count($documentsToWrite) + count($idsToDelete);
    }

    /**
     * Last message wins per key, regardless of whether it was a write or a delete -- a document
     * written then deleted (or vice versa) within the same drain batch must end up in whichever state
     * the LAST operation left it in, matching what would have happened had these gone through the live
     * index directly.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $storeName
     *
     * @return array{0: array<int, \Elastica\Document>, 1: array<int, string>}
     */
    protected function partitionLatestByKey(array $messages, string $storeName): array
    {
        $latestByKey = [];

        foreach ($messages as $message) {
            if (isset($message[static::TYPE_WRITE]['key']) && $this->belongsToStore($message[static::TYPE_WRITE], $storeName)) {
                $latestByKey[$message[static::TYPE_WRITE]['key']] = [static::TYPE_WRITE, $message[static::TYPE_WRITE]];
            } elseif (isset($message[static::TYPE_DELETE]['key']) && $this->belongsToStore($message[static::TYPE_DELETE], $storeName)) {
                $latestByKey[$message[static::TYPE_DELETE]['key']] = [static::TYPE_DELETE, $message[static::TYPE_DELETE]];
            }
        }

        $documentsToWrite = [];
        $idsToDelete = [];

        foreach ($latestByKey as $key => [$type, $payload]) {
            if ($type === static::TYPE_WRITE) {
                $value = $payload['value'] ?? [];
                unset($value['_timestamp']);
                $documentsToWrite[] = new Document((string)$key, $value);
            } else {
                $idsToDelete[] = (string)$key;
            }
        }

        return [$documentsToWrite, $idsToDelete];
    }

    /**
     * The mirror queue's exchange binding is shared across every store publishing to the same
     * sourceIdentifier (see class doc block) -- this is the actual scoping, applied per message rather
     * than at the broker level. A message with no `store` key at all is a Dynamic Store Mode OFF payload
     * (single-store install), which always belongs.
     *
     * @param array<string, mixed> $payload
     * @param string $storeName
     */
    protected function belongsToStore(array $payload, string $storeName): bool
    {
        if (!isset($payload[static::STORE])) {
            return true;
        }

        return $payload[static::STORE] === $storeName;
    }

    /**
     * @param \Elastica\Index $index
     * @param array<int, string> $idsToDelete
     *
     * @throws \Elastica\Exception\ResponseException
     */
    protected function deleteByIds(Index $index, array $idsToDelete): void
    {
        foreach ($idsToDelete as $id) {
            try {
                $index->deleteById($id);
            } catch (ResponseException $responseException) {
                // 404: the target may never have had this document (e.g. deleted before the bulk load
                // ever wrote it) -- not a failure, the end state (document absent) is already correct.
                // Anything else is a real problem and must propagate.
                if ($responseException->getResponse()->getStatus() !== 404) {
                    throw $responseException;
                }
            }
        }
    }

    /**
     * @param \PhpAmqpLib\Channel\AMQPChannel $channel
     * @param array<int, int|string> $deliveryTags
     */
    protected function acknowledge(AMQPChannel $channel, array $deliveryTags): void
    {
        foreach ($deliveryTags as $deliveryTag) {
            $channel->basic_ack((int)$deliveryTag);
        }
    }
}
