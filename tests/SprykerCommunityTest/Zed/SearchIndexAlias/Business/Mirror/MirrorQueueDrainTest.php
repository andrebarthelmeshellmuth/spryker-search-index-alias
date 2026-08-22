<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Mirror;

use Codeception\Test\Unit;
use Elastica\Client as ElasticaClient;
use Elastica\Document;
use Elastica\Exception\ResponseException;
use PhpAmqpLib\Message\AMQPMessage;
use Spryker\Client\RabbitMq\RabbitMqConfig;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;

/**
 * INTEGRATION TEST — real RabbitMQ (AMQP `basic_get` loop) AND real Elasticsearch/OpenSearch. Publishes
 * raw AMQP messages directly onto a throwaway queue (via the default exchange, which always routes to a
 * queue of the same name regardless of any binding) to prove the drain-deduplicate-apply path works
 * against the real broker and a real index, not a mocked message source.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Mirror
 * @group MirrorQueueDrainTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 * @group NeedsSearch
 */
class MirrorQueueDrainTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_mirror_drain_';

    protected BrokerConnectionProvider $brokerConnectionProvider;

    protected ElasticaClient $elasticaClient;

    protected MirrorQueueDrain $mirrorQueueDrain;

    protected function _before(): void
    {
        $this->brokerConnectionProvider = new BrokerConnectionProvider(new RabbitMqConfig());
        $this->elasticaClient = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->mirrorQueueDrain = new MirrorQueueDrain(
            $this->brokerConnectionProvider,
            new ElasticaClientProvider(new SearchElasticsearchConfig()),
        );
    }

    protected function _after(): void
    {
        foreach ($this->elasticaClient->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->elasticaClient->getIndex($row['index'])->delete();
        }
    }

    public function testDrainAppliesAWriteMessageToTheTargetIndex(): void
    {
        $queueName = static::TEST_PREFIX . 'write';
        $targetIndexName = static::TEST_PREFIX . 'index1';
        $this->elasticaClient->getIndex($targetIndexName)->create();
        $this->declareAndPublish($queueName, [
            'write' => ['key' => 'sku-1', 'value' => ['name' => 'Widget'], 'store' => 'DE'],
        ]);

        $drainedCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');

        $this->assertSame(1, $drainedCount);
        $document = $this->elasticaClient->getIndex($targetIndexName)->getDocument('sku-1');
        $this->assertSame('Widget', $document->getData()['name']);
    }

    public function testDrainAppliesADeleteMessageToTheTargetIndex(): void
    {
        $queueName = static::TEST_PREFIX . 'delete';
        $targetIndexName = static::TEST_PREFIX . 'index2';
        $this->elasticaClient->getIndex($targetIndexName)->create();
        $this->elasticaClient->getIndex($targetIndexName)->addDocuments([
            new Document('sku-2', ['name' => 'ToBeDeleted']),
        ]);
        $this->elasticaClient->getIndex($targetIndexName)->refresh();
        $this->declareAndPublish($queueName, [
            'delete' => ['key' => 'sku-2'],
        ]);

        $drainedCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');

        $this->assertSame(1, $drainedCount);
        $this->assertFalse($this->documentExists($targetIndexName, 'sku-2'));
    }

    public function testDrainKeepsOnlyTheLastMessagePerKeyWhenAWriteAndADeleteBothTargetTheSameKey(): void
    {
        $queueName = static::TEST_PREFIX . 'dedupe';
        $targetIndexName = static::TEST_PREFIX . 'index3';
        $this->elasticaClient->getIndex($targetIndexName)->create();
        $this->declareAndPublish($queueName, [
            'write' => ['key' => 'sku-3', 'value' => ['name' => 'FirstWrite'], 'store' => 'DE'],
        ]);
        $this->declareAndPublish($queueName, [
            'delete' => ['key' => 'sku-3'],
        ], declareQueue: false);

        $drainedCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');

        // 2 raw messages, but they dedupe to ONE surviving key (the delete, since it's last) -- the
        // return value reflects what was actually applied, not what was fetched off the broker.
        $this->assertSame(1, $drainedCount);
        $this->assertFalse($this->documentExists($targetIndexName, 'sku-3'));
    }

    public function testDrainAcknowledgesButDiscardsAMessageBelongingToAnotherStore(): void
    {
        $queueName = static::TEST_PREFIX . 'other-store';
        $targetIndexName = static::TEST_PREFIX . 'index5';
        $this->elasticaClient->getIndex($targetIndexName)->create();
        $this->declareAndPublish($queueName, [
            'write' => ['key' => 'sku-5', 'value' => ['name' => 'AtOnly'], 'store' => 'AT'],
        ]);

        $drainedCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');

        // The message WAS fetched and acknowledged (removed from the shared queue -- proven by the
        // second call below finding nothing left), but it belongs to another store and was never
        // applied -- 0, not 1, is what RebuildOrchestrator's convergence loop actually needs to see so a
        // busy OTHER store's traffic can't keep it spinning forever. See MirrorQueueDrainInterface's own
        // docblock.
        $this->assertSame(0, $drainedCount);
        $this->assertFalse($this->documentExists($targetIndexName, 'sku-5'));

        $secondPassCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');
        $this->assertSame(0, $secondPassCount, 'The cross-store message must have been acknowledged off the queue already, not left behind.');
    }

    public function testDrainReturnsZeroForAnEmptyQueueAndDoesNotTouchTheTargetIndex(): void
    {
        $queueName = static::TEST_PREFIX . 'empty';
        $targetIndexName = static::TEST_PREFIX . 'index4';
        $this->elasticaClient->getIndex($targetIndexName)->create();
        $this->declareQueue($queueName);

        $drainedCount = $this->mirrorQueueDrain->drain($queueName, $targetIndexName, 'DE');

        $this->assertSame(0, $drainedCount);
    }

    protected function documentExists(string $indexName, string $id): bool
    {
        try {
            $this->elasticaClient->getIndex($indexName)->getDocument($id);

            return true;
        } catch (ResponseException | \Elastica\Exception\NotFoundException) {
            return false;
        }
    }

    protected function declareQueue(string $queueName): void
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->close();
        $connection->close();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function declareAndPublish(string $queueName, array $payload, bool $declareQueue = true): void
    {
        $connection = $this->brokerConnectionProvider->getConnection();
        $channel = $connection->channel();

        if ($declareQueue) {
            $channel->queue_declare($queueName, false, true, false, false);
        }

        $channel->basic_publish(new AMQPMessage((string)json_encode($payload)), '', $queueName);
        $channel->close();
        $connection->close();
    }
}
