<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client;

use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;

interface SearchIndexAliasToQueueClientInterface
{
    /**
     * @param string $queueName
     * @param \Generated\Shared\Transfer\QueueSendMessageTransfer $queueSendMessageTransfer
     */
    public function sendMessage(string $queueName, QueueSendMessageTransfer $queueSendMessageTransfer): void;

    /**
     * @param string $queueName
     * @param array<string, mixed> $options `Client\Queue`'s RabbitMQ adapter reads `$options['rabbitmq']`
     *  (a `RabbitMqConsumerOptionTransfer`) directly and unconditionally -- unlike `sendMessage()`, there
     *  is no adapter-side default, so a caller driving this outside `queue:worker:start`'s own built-in
     *  message-checking helper (which supplies it automatically for registered processor plugins) must
     *  pass it explicitly. See `RebuildRequestConsumer::consumeOne()` for the caller that does this.
     */
    public function receiveMessage(string $queueName, array $options = []): QueueReceiveMessageTransfer;

    /**
     * @param \Generated\Shared\Transfer\QueueReceiveMessageTransfer $queueReceiveMessageTransfer
     */
    public function acknowledge(QueueReceiveMessageTransfer $queueReceiveMessageTransfer): void;
}
