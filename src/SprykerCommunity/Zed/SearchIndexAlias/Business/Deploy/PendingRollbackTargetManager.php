<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;

class PendingRollbackTargetManager implements PendingRollbackTargetManagerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface $rolloutFinisher
     */
    public function __construct(
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
        protected SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager,
        protected RolloutFinisherInterface $rolloutFinisher,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException
     */
    public function mark(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser = null,
    ): void {
        if (!$this->aliasManager->indexExists($targetIndexName)) {
            throw new RollbackTargetNotApplicableException(sprintf('"%s" no longer exists.', $targetIndexName));
        }

        $liveIndexNames = $this->aliasManager->getIndicesForAlias($searchIndexScopeTransfer->getAliasNameOrFail());

        if (in_array($targetIndexName, $liveIndexNames, true)) {
            throw new RollbackTargetNotApplicableException(sprintf('"%s" is already the live index -- nothing to roll back.', $targetIndexName));
        }

        $this->searchIndexAliasEntityManager->savePendingRollbackTarget(
            (new SearchIndexDeployRollbackTargetTransfer())
                ->setSearchIndexScope($searchIndexScopeTransfer)
                ->setTargetIndexName($targetIndexName)
                ->setTriggeredByUser($triggeredByUser),
        );

        // Mutually exclusive with a pending rebuild-flip on the same scope (see this class's own doc
        // block) -- clear the other direction's flag too.
        $activeSearchIndexRolloutTransfer = $this->searchIndexAliasRepository->findActiveRolloutForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        if (
            $activeSearchIndexRolloutTransfer === null
            || $activeSearchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_READY
            || $activeSearchIndexRolloutTransfer->getFlipPending() !== true
        ) {
            return;
        }

        $this->rolloutFinisher->unmarkFlipPending($activeSearchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function unmark(SearchIndexScopeTransfer $searchIndexScopeTransfer): void
    {
        $this->searchIndexAliasEntityManager->deletePendingRollbackTargetForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function findFor(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?string
    {
        $searchIndexDeployRollbackTargetTransfer = $this->searchIndexAliasRepository->findPendingRollbackTargetForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        return $searchIndexDeployRollbackTargetTransfer?->getTargetIndexName();
    }
}
