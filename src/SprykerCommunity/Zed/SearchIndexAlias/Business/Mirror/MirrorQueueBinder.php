<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class MirrorQueueBinder implements MirrorQueueBinderInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClientInterface $rabbitMqManagementClient
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     */
    public function __construct(
        protected RabbitMqManagementClientInterface $rabbitMqManagementClient,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function bind(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): string
    {
        $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();
        $exchangeName = $this->searchIndexAliasConfig->getSyncExchangeNameForSourceIdentifier(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
        );

        $queueName = $this->buildQueueName($searchIndexRolloutTransfer);

        $this->rabbitMqManagementClient->declareQueue($queueName);
        $this->rabbitMqManagementClient->bindQueueToExchange($queueName, $exchangeName);

        return $queueName;
    }

    /**
     * @param string $mirrorQueueName
     */
    public function unbind(string $mirrorQueueName): void
    {
        $this->rabbitMqManagementClient->deleteQueue($mirrorQueueName);
    }

    /**
     * Unique per rollout attempt (not just per scope), so two rollouts of the same scope over time never
     * collide even if a prior mirror queue was somehow left behind uncleaned -- see the README's note on
     * an abandoned rollout needing a TTL/reconciliation sweep, which this naming makes safe to build
     * later without a collision risk in the meantime.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    protected function buildQueueName(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): string
    {
        return sprintf(
            '%s%s.%s',
            SearchIndexAliasConfig::MIRROR_QUEUE_NAME_PREFIX,
            $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getAliasNameOrFail(),
            $searchIndexRolloutTransfer->getIdSearchIndexRolloutOrFail(),
        );
    }
}
