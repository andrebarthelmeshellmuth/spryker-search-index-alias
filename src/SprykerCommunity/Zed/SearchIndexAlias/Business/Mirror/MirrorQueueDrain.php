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
     */
    public function drain(string $mirrorQueueName, string $targetIndexName): int
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();

        try {
            [$messages, $deliveryTags] = $this->fetchMessages($channel, $mirrorQueueName);

            if ($messages === []) {
                return 0;
            }

            $this->applyDeduplicated($messages, $targetIndexName);
            $this->acknowledge($channel, $deliveryTags);

            return count($messages);
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
     */
    protected function applyDeduplicated(array $messages, string $targetIndexName): void
    {
        [$documentsToWrite, $idsToDelete] = $this->partitionLatestByKey($messages);

        if ($documentsToWrite === [] && $idsToDelete === []) {
            return;
        }

        $index = $this->elasticaClientProvider->getClient()->getIndex($targetIndexName);

        if ($documentsToWrite !== []) {
            $index->addDocuments($documentsToWrite);
        }

        $this->deleteByIds($index, $idsToDelete);

        $index->refresh();
    }

    /**
     * Last message wins per key, regardless of whether it was a write or a delete -- a document
     * written then deleted (or vice versa) within the same drain batch must end up in whichever state
     * the LAST operation left it in, matching what would have happened had these gone through the live
     * index directly.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array{0: array<int, \Elastica\Document>, 1: array<int, string>}
     */
    protected function partitionLatestByKey(array $messages): array
    {
        $latestByKey = [];

        foreach ($messages as $message) {
            if (isset($message[static::TYPE_WRITE]['key'])) {
                $latestByKey[$message[static::TYPE_WRITE]['key']] = [static::TYPE_WRITE, $message[static::TYPE_WRITE]];
            } elseif (isset($message[static::TYPE_DELETE]['key'])) {
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
