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
use Spryker\Client\Queue\QueueClient;
use Spryker\Client\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisher;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientBridge;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real RabbitMQ broker. Verifies the published message round-trips through the real
 * static worker queue with the exact payload shape `RebuildRequestConsumer::processPayload()` expects to
 * read back, by consuming it directly here rather than trusting a mock's recorded call args.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rebuild
 * @group RebuildRequestPublisherTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 */
class RebuildRequestPublisherTest extends Unit
{
    protected BrokerConnectionProvider $brokerConnectionProvider;

    protected SearchIndexAliasToQueueClientBridge $queueClient;

    protected function _before(): void
    {
        $this->brokerConnectionProvider = new BrokerConnectionProvider(new RabbitMqConfig());
        $this->queueClient = new SearchIndexAliasToQueueClientBridge(new QueueClient());
    }

    public function testPublishWritesTheExpectedPayloadShapeOntoTheRealRebuildRequestQueue(): void
    {
        $searchIndexAliasConfig = new SearchIndexAliasConfig();
        $rebuildRequestPublisher = new RebuildRequestPublisher($this->queueClient, $searchIndexAliasConfig);
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(9999);
        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('phpunit_publisher_page');

        $rebuildRequestPublisher->publish($searchIndexRolloutTransfer, $searchIndexScopeTransfer, ['properties' => ['sku' => ['type' => 'keyword']]], true);

        $payload = $this->consumeOneRawMessage($searchIndexAliasConfig->getRebuildRequestQueueName());

        $this->assertNotNull($payload);
        $this->assertSame(9999, $payload['idSearchIndexRollout']);
        $this->assertSame('page', $payload['sourceIdentifier']);
        $this->assertSame('DE', $payload['storeName']);
        $this->assertSame('phpunit_publisher_page', $payload['aliasName']);
        $this->assertSame(['properties' => ['sku' => ['type' => 'keyword']]], $payload['targetMappingProperties']);
        $this->assertTrue($payload['optimizeForBulkLoad']);
    }

    public function testPublishWritesANullTargetMappingPropertiesWhenNoneIsGiven(): void
    {
        $searchIndexAliasConfig = new SearchIndexAliasConfig();
        $rebuildRequestPublisher = new RebuildRequestPublisher($this->queueClient, $searchIndexAliasConfig);
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(10000);
        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('phpunit_publisher_page_2');

        $rebuildRequestPublisher->publish($searchIndexRolloutTransfer, $searchIndexScopeTransfer, null, false);

        $payload = $this->consumeOneRawMessage($searchIndexAliasConfig->getRebuildRequestQueueName());

        $this->assertNotNull($payload);
        $this->assertNull($payload['targetMappingProperties']);
        $this->assertFalse($payload['optimizeForBulkLoad']);
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
