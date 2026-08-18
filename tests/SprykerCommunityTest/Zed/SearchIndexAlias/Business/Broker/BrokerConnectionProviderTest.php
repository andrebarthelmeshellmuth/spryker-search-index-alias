<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Broker;

use Codeception\Test\Unit;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Spryker\Client\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;

/**
 * INTEGRATION TEST — real RabbitMQ broker, real `SPRYKER_BROKER_CONNECTIONS` env value. A stream
 * connection that actually opens is the only meaningful proof this class reads the right host/port/
 * credentials/vhost; a mock would only prove the class calls a constructor with SOME arguments.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Broker
 * @group BrokerConnectionProviderTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 */
class BrokerConnectionProviderTest extends Unit
{
    public function testGetConnectionOpensARealStreamConnectionToTheConfiguredBroker(): void
    {
        $brokerConnectionProvider = new BrokerConnectionProvider(new RabbitMqConfig());

        $connection = $brokerConnectionProvider->getConnection();

        $this->assertInstanceOf(AMQPStreamConnection::class, $connection);
        $this->assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testGetVirtualHostReturnsTheRealConfiguredVirtualHost(): void
    {
        $brokerConnectionProvider = new BrokerConnectionProvider(new RabbitMqConfig());

        // This project's own real docker default -- see SPRYKER_BROKER_CONNECTIONS in the dev env.
        $this->assertSame('eu-docker', $brokerConnectionProvider->getVirtualHost());
    }
}
