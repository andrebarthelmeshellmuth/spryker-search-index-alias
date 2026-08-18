<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Broker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Spryker\Zed\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\BrokerOperationFailedException;

/**
 * Talks to the RabbitMQ Management HTTP API -- the same interface `Spryker\Zed\RabbitMq\Business\RabbitMqBusinessFactory`
 * itself uses (via `getApiQueuesUrl()`/`getApiExchangesUrl()`), and the one this package's own design
 * spikes were verified against live (see README "How it works"). Appropriate for admin operations
 * (declare/bind/delete a queue); NOT used for message consumption, which needs the real AMQP protocol --
 * see MirrorQueueDrain.
 *
 * Deliberately does NOT trust `RabbitMqConfig::getApiQueuesUrl()`'s baked-in vhost segment
 * (`getApiVirtualHost()`, fed by `SPRYKER_BROKER_NAMESPACE`) -- confirmed live on this package's own
 * reference install that this env var is simply unset, producing a URL with an EMPTY vhost segment
 * (`http://broker:15672/api/queues/`, no trailing segment at all). Appending a queue name directly to
 * that would put the queue name where the vhost belongs, silently targeting the default `/` vhost
 * instead of the real one (or erroring, depending on RabbitMQ's own default-vhost permissions) -- a
 * failure mode with no error at bind time, only later when the mirror queue turns out to receive
 * nothing. Instead, the vhost segment is always rebuilt from `BrokerConnectionProvider::getVirtualHost()`,
 * which reads the SAME `SPRYKER_BROKER_CONNECTIONS` value the real AMQP connection also uses and is
 * confirmed reliably populated.
 */
class RabbitMqManagementClient implements RabbitMqManagementClientInterface
{
    /**
     * @param \GuzzleHttp\Client $client
     * @param \Spryker\Zed\RabbitMq\RabbitMqConfig $rabbitMqConfig
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface $brokerConnectionProvider
     */
    public function __construct(
        protected Client $client,
        protected RabbitMqConfig $rabbitMqConfig,
        protected BrokerConnectionProviderInterface $brokerConnectionProvider,
    ) {
    }

    /**
     * @param string $queueName
     */
    public function declareQueue(string $queueName): void
    {
        $this->request('PUT', $this->getQueuesUrl($queueName), ['durable' => true]);
    }

    /**
     * @param string $queueName
     * @param string $exchangeName
     */
    public function bindQueueToExchange(string $queueName, string $exchangeName): void
    {
        $this->request('POST', $this->getBindingsUrl($exchangeName, $queueName), ['routing_key' => '']);
    }

    /**
     * @param string $queueName
     */
    public function deleteQueue(string $queueName): void
    {
        $this->request('DELETE', $this->getQueuesUrl($queueName), null);
    }

    /**
     * @param string $queueName
     */
    protected function getQueuesUrl(string $queueName): string
    {
        return sprintf('%s/%s', $this->getQueuesBaseUrl(), urlencode($queueName));
    }

    /**
     * The Management API has no dedicated "bindings" config getter -- derived from the queues base URL,
     * which shares the same scheme/host/port (not the vhost -- see this class's own doc block for why
     * that segment is rebuilt instead of trusted).
     *
     * @param string $exchangeName
     * @param string $queueName
     */
    protected function getBindingsUrl(string $exchangeName, string $queueName): string
    {
        $bindingsBaseUrl = str_replace('/api/queues/', '/api/bindings/', $this->getQueuesBaseUrl());

        return sprintf(
            '%s/e/%s/q/%s',
            $bindingsBaseUrl,
            urlencode($exchangeName),
            urlencode($queueName),
        );
    }

    /**
     * `getApiQueuesUrl()`'s own vhost segment, sliced off and replaced with the confirmed-correct one --
     * see this class's own doc block.
     */
    protected function getQueuesBaseUrl(): string
    {
        $rawUrl = $this->rabbitMqConfig->getApiQueuesUrl();
        $marker = '/api/queues/';
        $markerPosition = strpos($rawUrl, $marker);
        $schemeHostPort = $markerPosition !== false ? substr($rawUrl, 0, $markerPosition) : rtrim($rawUrl, '/');

        return sprintf('%s%s%s', $schemeHostPort, $marker, urlencode($this->brokerConnectionProvider->getVirtualHost()));
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, mixed>|null $json
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\BrokerOperationFailedException
     */
    protected function request(string $method, string $url, ?array $json): void
    {
        $options = [
            'auth' => [$this->rabbitMqConfig->getApiUsername(), $this->rabbitMqConfig->getApiPassword()],
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $this->client->request($method, $url, $options);
        } catch (GuzzleException $guzzleException) {
            throw new BrokerOperationFailedException(sprintf(
                '%s %s failed: %s',
                $method,
                $url,
                $guzzleException->getMessage(),
            ), 0, $guzzleException);
        }
    }
}
