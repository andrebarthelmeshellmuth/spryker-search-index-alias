<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Broker;

use PhpAmqpLib\Connection\AMQPStreamConnection;

interface BrokerConnectionProviderInterface
{
    public function getConnection(): AMQPStreamConnection;

    public function getVirtualHost(): string;
}
