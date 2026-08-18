<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollbackInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;
use Throwable;

/**
 * The `search-index-alias:deploy-flip` console command's real logic (thin wrapper per the family's own
 * D6 rule -- see README) -- iterates every managed scope and applies whichever deploy-time intent an
 * operator explicitly flagged: either flip a rebuilt, READY, flip-pending rollout onto live (see
 * RolloutFinisher's own markFlipPending()/unmarkFlipPending() doc block), or roll back to an
 * already-existing physical index that was flagged via PendingRollbackTargetManager. Deliberately does
 * NOT act on every READY rollout or every old index it finds -- an unflagged one might still be under
 * manual review, not yet meant to go live. The two flag kinds are mutually exclusive per scope (enforced
 * where they're set, not here).
 */
class DeployFlipRunner implements DeployFlipRunnerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface $indexEnumerator
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface $rebuildOrchestrator
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollbackInterface $aliasRollback
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManagerInterface $pendingRollbackTargetManager
     */
    public function __construct(
        protected IndexEnumeratorInterface $indexEnumerator,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
        protected RebuildOrchestratorInterface $rebuildOrchestrator,
        protected AliasRollbackInterface $aliasRollback,
        protected PendingRollbackTargetManagerInterface $pendingRollbackTargetManager,
    ) {
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function findPendingFlipCandidates(): array
    {
        return [...$this->findPendingRebuildFlips(), ...$this->findPendingRollbackFlips()];
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function flipAllPending(): array
    {
        $results = [];

        foreach ($this->findPendingRebuildFlips() as $searchIndexRolloutTransfer) {
            $results[] = $this->rebuildOrchestrator->flip($searchIndexRolloutTransfer);
        }

        foreach ($this->findPendingRollbackTargets() as $searchIndexDeployRollbackTargetTransfer) {
            $searchIndexScopeTransfer = $searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail();

            try {
                $results[] = $this->aliasRollback->rollbackToIndex(
                    $searchIndexScopeTransfer,
                    $searchIndexDeployRollbackTargetTransfer->getTargetIndexNameOrFail(),
                    $searchIndexDeployRollbackTargetTransfer->getTriggeredByUser(),
                );
            } catch (Throwable $throwable) {
                $results[] = $this->buildFailedRollbackResult($searchIndexDeployRollbackTargetTransfer, $throwable);
            }

            // Attempted (successfully or not) -- either way, this flag must not silently retry on every
            // future deploy. A genuine failure (e.g. a concurrent rebuild) needs a fresh, deliberate
            // re-flag, same as ConcurrentRolloutException already forces for a plain rollback attempt.
            $this->pendingRollbackTargetManager->unmark($searchIndexScopeTransfer);
        }

        return $results;
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    protected function findPendingRebuildFlips(): array
    {
        $candidates = [];

        foreach ($this->indexEnumerator->enumerateScopes() as $searchIndexScopeTransfer) {
            $searchIndexRolloutTransfer = $this->searchIndexAliasRepository->findActiveRolloutForScope(
                $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
                $searchIndexScopeTransfer->getStoreNameOrFail(),
            );

            if ($searchIndexRolloutTransfer === null || !$this->isPendingFlip($searchIndexRolloutTransfer)) {
                continue;
            }

            $candidates[] = $searchIndexRolloutTransfer;
        }

        return $candidates;
    }

    /**
     * In-memory only, never persisted -- a pending rollback target has no rollout row until
     * `rollbackToIndex()` actually runs it (see that class's own doc block for why), so this is built
     * fresh from `spy_search_index_deploy_rollback_target` purely for display (the Overview page's
     * "Pending deploy flips" panel, `deploy-flip --dry-run`).
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    protected function findPendingRollbackFlips(): array
    {
        $candidates = [];

        foreach ($this->findPendingRollbackTargets() as $searchIndexDeployRollbackTargetTransfer) {
            $candidates[] = (new SearchIndexRolloutTransfer())
                ->setSearchIndexScope($searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail())
                ->setTargetIndexName($searchIndexDeployRollbackTargetTransfer->getTargetIndexName())
                ->setTriggeredByUser($searchIndexDeployRollbackTargetTransfer->getTriggeredByUser())
                // Not a real rollout's startedAt -- reused here purely so a stale-flag check (see
                // check-installation) has a real timestamp to measure against instead of treating every
                // rollback candidate as instantly stale.
                ->setStartedAt($searchIndexDeployRollbackTargetTransfer->getCreatedAt());
        }

        return $candidates;
    }

    /**
     * `getAllPendingRollbackTargets()` returns every row in the table, unscoped -- filtered here against
     * `enumerateScopes()` the same way `findPendingRebuildFlips()` already scopes itself, so a stray row
     * for a scope this instance doesn't manage (e.g. a different project config, or leftover test data)
     * can never leak into either display or execution.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer>
     */
    protected function findPendingRollbackTargets(): array
    {
        $managedScopeKeys = [];

        foreach ($this->indexEnumerator->enumerateScopes() as $searchIndexScopeTransfer) {
            $managedScopeKeys[$this->buildScopeKey($searchIndexScopeTransfer->getSourceIdentifierOrFail(), $searchIndexScopeTransfer->getStoreNameOrFail())] = true;
        }

        return array_values(array_filter(
            $this->searchIndexAliasRepository->getAllPendingRollbackTargets(),
            function (SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer) use ($managedScopeKeys): bool {
                $searchIndexScopeTransfer = $searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail();

                return isset($managedScopeKeys[$this->buildScopeKey($searchIndexScopeTransfer->getSourceIdentifierOrFail(), $searchIndexScopeTransfer->getStoreNameOrFail())]);
            },
        ));
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    protected function buildScopeKey(string $sourceIdentifier, string $storeName): string
    {
        return $sourceIdentifier . ':' . $storeName;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     * @param \Throwable $throwable
     */
    protected function buildFailedRollbackResult(
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
        Throwable $throwable,
    ): SearchIndexRolloutTransfer {
        return (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail())
            ->setTargetIndexName($searchIndexDeployRollbackTargetTransfer->getTargetIndexName())
            ->setStatus(SearchIndexAliasConfig::STATUS_FAILED)
            ->setFailureReason($throwable->getMessage());
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer|null $searchIndexRolloutTransfer
     */
    protected function isPendingFlip(?SearchIndexRolloutTransfer $searchIndexRolloutTransfer): bool
    {
        return $searchIndexRolloutTransfer !== null
            && $searchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_READY
            && $searchIndexRolloutTransfer->getFlipPending() === true;
    }
}
