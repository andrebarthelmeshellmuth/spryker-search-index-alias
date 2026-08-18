<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business;

use Generated\Shared\Transfer\SearchIndexHealthCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexHealthTransfer;
use Generated\Shared\Transfer\SearchIndexPhysicalIndexCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasBusinessFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface getRepository()
 */
class SearchIndexAliasFacade extends AbstractFacade implements SearchIndexAliasFacadeInterface
{
    /**
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexScopeTransfer>
     */
    public function getManagedScopes(): array
    {
        return $this->getFactory()->createIndexEnumerator()->enumerateScopes();
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $toIndexName
     */
    public function adoptConcreteIndex(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $toIndexName): void
    {
        $this->getFactory()->createAliasManager()->adoptConcreteIndex(
            $searchIndexScopeTransfer->getAliasNameOrFail(),
            $toIndexName,
        );
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $fromIndexName
     * @param string $toIndexName
     */
    public function switchAlias(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $fromIndexName, string $toIndexName): void
    {
        $this->getFactory()->createAliasManager()->switchAlias(
            $searchIndexScopeTransfer->getAliasNameOrFail(),
            $fromIndexName,
            $toIndexName,
        );
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function buildTargetIndexName(SearchIndexScopeTransfer $searchIndexScopeTransfer): string
    {
        return $this->getFactory()->createIndexNameBuilder()->buildTargetIndexName(
            $searchIndexScopeTransfer->getAliasNameOrFail(),
        );
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function adopt(SearchIndexScopeTransfer $searchIndexScopeTransfer): string
    {
        return $this->getFactory()->createIndexAdopter()->adopt($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function needsAdoption(SearchIndexScopeTransfer $searchIndexScopeTransfer): bool
    {
        return $this->getFactory()->createIndexAdopter()->needsAdoption($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getActiveRollout(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?SearchIndexRolloutTransfer
    {
        return $this->getRepository()->findActiveRolloutForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getRolloutHistory(SearchIndexScopeTransfer $searchIndexScopeTransfer, int $limit = 20): array
    {
        return $this->getRepository()->getRolloutHistoryForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
            $limit,
        );
    }

    /**
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getLatestRolloutPerScope(): array
    {
        return $this->getRepository()->getLatestRolloutPerScope();
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function startRebuild(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer {
        return $this->getFactory()->createRebuildOrchestrator()->start(
            $searchIndexScopeTransfer,
            $triggeredByUser,
            $targetMappingProperties,
            $optimizeForBulkLoad,
        );
    }

    /**
     * GUI-safe: returns a `building` rollout immediately, the actual work happens asynchronously via
     * the `search-index-alias:rebuild-worker` queue consumer -- see RebuildOrchestrator::requestRebuildAsync().
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function requestRebuildAsync(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer {
        return $this->getFactory()->createRebuildOrchestrator()->requestRebuildAsync(
            $searchIndexScopeTransfer,
            $triggeredByUser,
            $targetMappingProperties,
            $optimizeForBulkLoad,
        );
    }

    /**
     * Processes at most one pending async rebuild request -- see `search-index-alias:rebuild-worker`.
     *
     * @api
     *
     * @return bool True if a request was processed, false if the queue was empty.
     */
    public function consumeOneRebuildRequest(): bool
    {
        return $this->getFactory()->createRebuildRequestConsumer()->consumeOne();
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function flipRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        return $this->getFactory()->createRebuildOrchestrator()->flip($searchIndexRolloutTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function markFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        return $this->getFactory()->createRolloutFinisher()->markFlipPending($searchIndexRolloutTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function unmarkFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        return $this->getFactory()->createRolloutFinisher()->unmarkFlipPending($searchIndexRolloutTransfer);
    }

    /**
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function findPendingFlipCandidates(): array
    {
        return $this->getFactory()->createDeployFlipRunner()->findPendingFlipCandidates();
    }

    /**
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function deployFlipPending(): array
    {
        return $this->getFactory()->createDeployFlipRunner()->flipAllPending();
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException
     */
    public function markPendingRollback(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser = null,
    ): void {
        $this->getFactory()->createPendingRollbackTargetManager()->mark($searchIndexScopeTransfer, $targetIndexName, $triggeredByUser);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function unmarkPendingRollback(SearchIndexScopeTransfer $searchIndexScopeTransfer): void
    {
        $this->getFactory()->createPendingRollbackTargetManager()->unmark($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function findPendingRollbackTarget(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?string
    {
        return $this->getFactory()->createPendingRollbackTargetManager()->findFor($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function abortRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer
    {
        return $this->getFactory()->createRebuildOrchestrator()->abort($searchIndexRolloutTransfer, $reason);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @return array<string>
     */
    public function pruneScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): array
    {
        return $this->getFactory()->createIndexPruner()->pruneScope($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function checkScopeHealth(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexHealthTransfer
    {
        return $this->getFactory()->createSearchIndexHealthChecker()->checkScope($searchIndexScopeTransfer);
    }

    /**
     * @api
     */
    public function checkAllManagedScopesHealth(): SearchIndexHealthCollectionTransfer
    {
        return $this->getFactory()->createSearchIndexHealthChecker()->checkAllManagedScopes();
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getIndicesForScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexPhysicalIndexCollectionTransfer
    {
        return $this->getFactory()->createScopeIndexOverview()->getIndicesForScope($searchIndexScopeTransfer);
    }

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     */
    public function rollbackToIndex(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser = null,
    ): SearchIndexRolloutTransfer {
        return $this->getFactory()->createAliasRollback()->rollbackToIndex($searchIndexScopeTransfer, $targetIndexName, $triggeredByUser);
    }

    /**
     * Deletes a single, currently-unaliased physical index -- refuses if it is still aliased (see
     * AliasManager::deleteUnaliasedIndex()). A manual, single-index counterpart to `pruneScope()`.
     *
     * @api
     *
     * @param string $indexName
     */
    public function deleteIndex(string $indexName): void
    {
        $this->getFactory()->createAliasManager()->deleteUnaliasedIndex($indexName);
    }
}
