<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client;

use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;

class SearchIndexAliasToQueueClientBridge implements SearchIndexAliasToQueueClientInterface
{
    /**
     * @var \Spryker\Client\Queue\QueueClientInterface
     */
    protected $queueClient;

    /**
     * @param \Spryker\Client\Queue\QueueClientInterface $queueClient
     */
    public function __construct($queueClient)
    {
        $this->queueClient = $queueClient;
    }

    /**
     * @param string $queueName
     * @param \Generated\Shared\Transfer\QueueSendMessageTransfer $queueSendMessageTransfer
     */
    public function sendMessage(string $queueName, QueueSendMessageTransfer $queueSendMessageTransfer): void
    {
        $this->queueClient->sendMessage($queueName, $queueSendMessageTransfer);
    }

    /**
     * @param string $queueName
     * @param array<string, mixed> $options
     */
    public function receiveMessage(string $queueName, array $options = []): QueueReceiveMessageTransfer
    {
        return $this->queueClient->receiveMessage($queueName, $options);
    }

    /**
     * @param \Generated\Shared\Transfer\QueueReceiveMessageTransfer $queueReceiveMessageTransfer
     */
    public function acknowledge(QueueReceiveMessageTransfer $queueReceiveMessageTransfer): void
    {
        $this->queueClient->acknowledge($queueReceiveMessageTransfer);
    }
}
