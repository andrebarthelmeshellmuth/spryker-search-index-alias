<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use DateTime;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;

class RolloutStarter implements RolloutStarterInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuardInterface $rolloutGuard
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager
     */
    public function __construct(
        protected RolloutGuardInterface $rolloutGuard,
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     */
    public function start(SearchIndexScopeTransfer $searchIndexScopeTransfer, ?string $triggeredByUser = null): SearchIndexRolloutTransfer
    {
        $this->rolloutGuard->assertNoActiveRollout($searchIndexScopeTransfer);

        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $liveIndexNames = $this->aliasManager->getIndicesForAlias($aliasName);
        $liveIndexName = $liveIndexNames[0] ?? null;

        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($searchIndexScopeTransfer)
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING)
            ->setLiveIndexName($liveIndexName)
            ->setTriggeredByUser($triggeredByUser)
            ->setStartedAt((new DateTime())->format(DATE_ATOM));

        return $this->searchIndexAliasEntityManager->createRollout($searchIndexRolloutTransfer);
    }
}
