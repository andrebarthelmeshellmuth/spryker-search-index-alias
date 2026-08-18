<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Broker;

use Codeception\Test\Unit;
use GuzzleHttp\Client;
use Spryker\Client\RabbitMq\RabbitMqConfig as ClientRabbitMqConfig;
use Spryker\Zed\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\BrokerOperationFailedException;

/**
 * INTEGRATION TEST — real RabbitMQ Management HTTP API. The one behavior most worth protecting live:
 * this class deliberately does NOT trust `RabbitMqConfig::getApiQueuesUrl()`'s own baked-in vhost
 * segment (see the class's own doc block for why) -- only a real HTTP round trip against the real
 * broker proves the rebuilt URL actually resolves to a vhost that exists.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Broker
 * @group RabbitMqManagementClientTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 */
class RabbitMqManagementClientTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_QUEUE_PREFIX = 'phpunit_mgmt_';

    /**
     * @var string
     */
    protected const REAL_EXCHANGE_NAME = 'sync.search.product';

    protected RabbitMqManagementClient $rabbitMqManagementClient;

    protected function _before(): void
    {
        $this->rabbitMqManagementClient = $this->createClient();
    }

    protected function _after(): void
    {
        // Best-effort cleanup; a queue that was never declared in a given test simply 404s, which this
        // class's own deleteQueue() does not special-case -- swallow it here instead.
        try {
            $this->rabbitMqManagementClient->deleteQueue(static::TEST_QUEUE_PREFIX . 'lifecycle');
        } catch (BrokerOperationFailedException) {
        }
    }

    public function testDeclareQueueBindQueueToExchangeAndDeleteQueueRoundTripAgainstTheRealBroker(): void
    {
        $queueName = static::TEST_QUEUE_PREFIX . 'lifecycle';

        $this->rabbitMqManagementClient->declareQueue($queueName);
        $this->rabbitMqManagementClient->bindQueueToExchange($queueName, static::REAL_EXCHANGE_NAME);
        $this->rabbitMqManagementClient->deleteQueue($queueName);

        // A second delete of an already-deleted queue is a 404 from the broker -- proves the first
        // delete really happened, not just that the call didn't throw.
        $this->expectException(BrokerOperationFailedException::class);
        $this->rabbitMqManagementClient->deleteQueue($queueName);
    }

    public function testBindQueueToExchangeThrowsABrokerOperationFailedExceptionForANonexistentExchange(): void
    {
        $queueName = static::TEST_QUEUE_PREFIX . 'orphan';
        $this->rabbitMqManagementClient->declareQueue($queueName);

        $this->expectException(BrokerOperationFailedException::class);

        try {
            $this->rabbitMqManagementClient->bindQueueToExchange($queueName, 'definitely_not_a_real_exchange');
        } finally {
            $this->rabbitMqManagementClient->deleteQueue($queueName);
        }
    }

    public function testDeclareQueueThrowsABrokerOperationFailedExceptionWrappingTheUnderlyingGuzzleException(): void
    {
        // getApiQueuesUrl() already returns the real, absolute broker URL (a custom Guzzle base_uri has
        // no effect on an absolute request URL), so the only way to force a real transport-level failure
        // here is bad credentials against the real, reachable broker -- a genuine 401 from Guzzle.
        $wrongCredentialsConfig = new class extends RabbitMqConfig {
            public function getApiPassword(): string
            {
                return 'definitely_not_the_real_password';
            }
        };
        $rabbitMqManagementClient = new RabbitMqManagementClient(
            new Client(),
            $wrongCredentialsConfig,
            new BrokerConnectionProvider(new ClientRabbitMqConfig()),
        );

        $this->expectException(BrokerOperationFailedException::class);

        $rabbitMqManagementClient->declareQueue(static::TEST_QUEUE_PREFIX . 'unreachable');
    }

    protected function createClient(): RabbitMqManagementClient
    {
        return new RabbitMqManagementClient(
            new Client(),
            new RabbitMqConfig(),
            new BrokerConnectionProvider(new ClientRabbitMqConfig()),
        );
    }
}
