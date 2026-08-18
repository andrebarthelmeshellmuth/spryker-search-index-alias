<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Broker;

interface RabbitMqManagementClientInterface
{
    /**
     * @param string $queueName
     */
    public function declareQueue(string $queueName): void;

    /**
     * @param string $queueName
     * @param string $exchangeName
     */
    public function bindQueueToExchange(string $queueName, string $exchangeName): void;

    /**
     * @param string $queueName
     */
    public function deleteQueue(string $queueName): void;
}
