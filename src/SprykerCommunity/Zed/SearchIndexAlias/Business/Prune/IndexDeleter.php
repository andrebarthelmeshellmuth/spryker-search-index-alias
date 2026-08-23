<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Prune;

use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;

class IndexDeleter implements IndexDeleterInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     */
    public function __construct(
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $indexName
     * @param string|null $triggeredByUser
     */
    public function deleteIndex(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $indexName, ?string $triggeredByUser = null): void
    {
        $this->guardAgainstActiveRolloutTarget($searchIndexScopeTransfer, $indexName);
        $this->guardAgainstPendingRollbackTarget($searchIndexScopeTransfer, $indexName);

        $this->aliasManager->deleteUnaliasedIndex($indexName);

        $this->searchIndexAliasEntityManager->recordIndexDeletion(
            (new SearchIndexDeletionTransfer())
                ->setSearchIndexScope($searchIndexScopeTransfer)
                ->setIndexName($indexName)
                ->setTriggeredByUser($triggeredByUser),
        );
    }

    /**
     * Refuses to delete the target of this scope's currently active (non-terminal: building/ready/
     * flipping) rollout -- most importantly a READY, flip-pending target: deleting that out from under
     * `search-index-alias:deploy-flip` leaves the next deploy failing on "no such index" instead of
     * actually flipping. The live (aliased) index is already refused by `AliasManager` itself; this
     * closes the other way an in-flight rollout can be broken by a manual delete.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $indexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    protected function guardAgainstActiveRolloutTarget(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $indexName): void
    {
        $activeSearchIndexRolloutTransfer = $this->searchIndexAliasRepository->findActiveRolloutForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        if ($activeSearchIndexRolloutTransfer === null || $activeSearchIndexRolloutTransfer->getTargetIndexName() !== $indexName) {
            return;
        }

        throw new AliasOperationFailedException(sprintf(
            'Refusing to delete index "%s": it is the target of an active rollout (status=%s)%s.',
            $indexName,
            $activeSearchIndexRolloutTransfer->getStatus(),
            $activeSearchIndexRolloutTransfer->getFlipPending() ? ', flagged for the next deploy-flip' : '',
        ));
    }

    /**
     * Refuses to delete an index flagged as this scope's deploy-time rollback target (see
     * `spy_search_index_deploy_rollback_target`'s own schema comment) -- the rollback counterpart to
     * `guardAgainstActiveRolloutTarget()` above, and a real incident, not a hypothetical: an index
     * flagged via "Flag for next deploy" on the Overview page's rollback row got manually deleted
     * afterward, leaving a dangling flag that `search-index-alias:deploy-flip` would only discover was
     * broken when it actually ran (self-healing there -- see `DeployFlipRunner::flipAllPending()` -- but
     * only after a real deploy failed on it).
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $indexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    protected function guardAgainstPendingRollbackTarget(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $indexName): void
    {
        $pendingRollbackTarget = $this->searchIndexAliasRepository->findPendingRollbackTargetForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        if ($pendingRollbackTarget === null || $pendingRollbackTarget->getTargetIndexName() !== $indexName) {
            return;
        }

        throw new AliasOperationFailedException(sprintf(
            'Refusing to delete index "%s": it is flagged as this scope\'s deploy-time rollback target -- unflag it first (Overview page, or search-index-alias:mark-rollback-pending --off) if you really want to delete it.',
            $indexName,
        ));
    }
}
