<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Broker;

use Generated\Shared\Transfer\QueueConnectionTransfer;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Spryker\Client\RabbitMq\RabbitMqConfig;

/**
 * `Spryker\Client\RabbitMq\RabbitMqConfig` is a Client-layer class, but -- like `SearchElasticsearchConfig`
 * in `ElasticaClientProvider` -- it is a plain Config (env-var reads only, no DI container/HTTP-session
 * entanglement), unlike `Client\RabbitMq`'s own Facade. This is the ONLY documented, `@api` way to read
 * the real AMQP connection parameters (host/port/user/password/vhost) from Zed: the Zed-side
 * `RabbitMqConfig` only exposes the separate Management HTTP API connection (see RabbitMqManagementClient),
 * not the AMQP protocol one.
 */
class BrokerConnectionProvider implements BrokerConnectionProviderInterface
{
    /**
     * @param \Spryker\Client\RabbitMq\RabbitMqConfig $rabbitMqConfig
     */
    public function __construct(protected RabbitMqConfig $rabbitMqConfig)
    {
    }

    public function getConnection(): AMQPStreamConnection
    {
        $queueConnectionTransfer = $this->getDefaultConnectionTransfer();

        return new AMQPStreamConnection(
            $queueConnectionTransfer->getHostOrFail(),
            $queueConnectionTransfer->getPortOrFail(),
            $queueConnectionTransfer->getUsernameOrFail(),
            $queueConnectionTransfer->getPasswordOrFail(),
            $queueConnectionTransfer->getVirtualHostOrFail(),
        );
    }

    public function getVirtualHost(): string
    {
        return $this->getDefaultConnectionTransfer()->getVirtualHostOrFail();
    }

    /**
     * @throws \RuntimeException
     */
    protected function getDefaultConnectionTransfer(): QueueConnectionTransfer
    {
        $queueConnectionTransfers = $this->rabbitMqConfig->getQueueConnections();
        $first = $queueConnectionTransfers[0] ?? null;

        if ($first === null) {
            throw new RuntimeException('No RabbitMQ connection configured (SPRYKER_BROKER_CONNECTIONS).');
        }

        return $first;
    }
}
