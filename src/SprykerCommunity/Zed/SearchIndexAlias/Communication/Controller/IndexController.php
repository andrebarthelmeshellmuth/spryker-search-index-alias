<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface;
use Symfony\Component\HttpFoundation\Request;

class IndexController extends AbstractScopeController
{
    /**
     * @var string
     */
    protected const DEFAULT_SOURCE = 'page';

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, mixed>
     */
    public function indexAction(Request $request): array
    {
        $searchIndexAliasFacade = $this->getFacade();
        $selectedSource = (string)$request->query->get('source', static::DEFAULT_SOURCE);
        $selectedStore = (string)$request->query->get('store', '');

        $managedScopes = $searchIndexAliasFacade->getManagedScopes();
        [$sources, $stores] = $this->collectSourcesAndStores($managedScopes);
        $searchIndexScopeTransfer = $this->resolveScope($managedScopes, $selectedSource, $selectedStore);

        $viewData = [
            'sources' => $sources,
            'selectedSource' => $selectedSource,
            'stores' => $stores,
            'selectedStore' => $selectedStore,
            'scope' => $searchIndexScopeTransfer,
            // Cross-scope, shown regardless of the Source/Store filter above -- "what would
            // search-index-alias:deploy-flip do right now" is exactly what an operator wants to see
            // before trusting the next deploy to flip anything.
            'pendingFlipCandidates' => $searchIndexAliasFacade->findPendingFlipCandidates(),
        ];

        if ($searchIndexScopeTransfer === null) {
            return $this->viewResponse($viewData);
        }

        $viewData += $this->buildScopeViewData($searchIndexAliasFacade, $searchIndexScopeTransfer, $selectedSource, $selectedStore);

        return $this->viewResponse($viewData);
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function collectSourcesAndStores(array $managedScopes): array
    {
        $sources = [];
        $stores = [];

        foreach ($managedScopes as $searchIndexScopeTransfer) {
            $sources[$searchIndexScopeTransfer->getSourceIdentifierOrFail()] = true;
            $stores[$searchIndexScopeTransfer->getStoreNameOrFail()] = true;
        }

        $sources = array_keys($sources);
        sort($sources);
        $stores = array_keys($stores);
        sort($stores);

        return [$sources, $stores];
    }

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface $searchIndexAliasFacade
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $selectedSource
     * @param string $selectedStore
     *
     * @return array<string, mixed>
     */
    protected function buildScopeViewData(
        SearchIndexAliasFacadeInterface $searchIndexAliasFacade,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $selectedSource,
        string $selectedStore,
    ): array {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $redirectTo = $this->buildIndexUrl($selectedSource, $selectedStore);

        $needsAdoption = $searchIndexAliasFacade->needsAdoption($searchIndexScopeTransfer);
        $activeSearchIndexRolloutTransfer = $needsAdoption ? null : $searchIndexAliasFacade->getActiveRollout($searchIndexScopeTransfer);
        $pendingRollbackTargetIndexName = $searchIndexAliasFacade->findPendingRollbackTarget($searchIndexScopeTransfer);
        $indexRows = $this->buildIndexRows(
            $searchIndexAliasFacade,
            $searchIndexScopeTransfer,
            $aliasName,
            $redirectTo,
            $pendingRollbackTargetIndexName,
            $activeSearchIndexRolloutTransfer?->getTargetIndexName(),
        );

        return [
            'needsAdoption' => $needsAdoption,
            'activeRollout' => $activeSearchIndexRolloutTransfer,
            'canRebuild' => !$needsAdoption && $activeSearchIndexRolloutTransfer === null,
            'canFlip' => $activeSearchIndexRolloutTransfer !== null && $activeSearchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_READY,
            'canAbort' => $activeSearchIndexRolloutTransfer !== null,
            'adoptFormView' => $needsAdoption ? $this->getFactory()->createAliasScopeForm($aliasName, $redirectTo)->createView() : null,
            'rebuildFormView' => $this->getFactory()->createRebuildForm($aliasName, $redirectTo)->createView(),
            'flipFormView' => $this->getFactory()->createAliasScopeForm($aliasName, $redirectTo)->createView(),
            'flipPendingFormView' => $this->getFactory()->createAliasScopeForm($aliasName, $redirectTo)->createView(),
            'abortFormView' => $this->getFactory()->createAbortForm($aliasName, $redirectTo)->createView(),
            'pendingRollbackTargetIndexName' => $pendingRollbackTargetIndexName,
            'indexRows' => $indexRows,
        ];
    }

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface $searchIndexAliasFacade
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $aliasName
     * @param string $redirectTo
     * @param string|null $pendingRollbackTargetIndexName
     * @param string|null $activeRolloutTargetIndexName The active (non-terminal) rollout's target, if
     *  any -- deleting it out from under a pending flip would break `search-index-alias:deploy-flip`,
     *  so this row never gets a Delete button (see `IndexDeleter::guardAgainstActiveRolloutTarget()`,
     *  which refuses it at the Business layer too).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildIndexRows(
        SearchIndexAliasFacadeInterface $searchIndexAliasFacade,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $aliasName,
        string $redirectTo,
        ?string $pendingRollbackTargetIndexName,
        ?string $activeRolloutTargetIndexName,
    ): array {
        $indexRows = [];

        foreach ($searchIndexAliasFacade->getIndicesForScope($searchIndexScopeTransfer)->getSearchIndexPhysicalIndices() as $searchIndexPhysicalIndexTransfer) {
            $indexName = $searchIndexPhysicalIndexTransfer->getIndexNameOrFail();
            $isCurrent = $searchIndexPhysicalIndexTransfer->getIsCurrentAlias();
            $isActiveRolloutTarget = $indexName === $activeRolloutTargetIndexName;
            $isRollbackPending = !$isCurrent && $indexName === $pendingRollbackTargetIndexName;

            $indexRows[] = [
                'physicalIndex' => $searchIndexPhysicalIndexTransfer,
                'isActiveRolloutTarget' => $isActiveRolloutTarget,
                'rollbackFormView' => $isCurrent
                    ? null
                    : $this->getFactory()->createRollbackForm($aliasName, $indexName, $redirectTo)->createView(),
                // Never offered for the active rollout's target (deleting it breaks the next deploy-flip
                // -- see IndexDeleter::guardAgainstActiveRolloutTarget()) or a flagged rollback target
                // (same failure mode, the other flag kind -- see guardAgainstPendingRollbackTarget()),
                // both refused at the Business layer too, this just avoids the round trip.
                'deleteFormView' => ($isCurrent || $isActiveRolloutTarget || $isRollbackPending)
                    ? null
                    : $this->getFactory()->createDeleteIndexForm($aliasName, $indexName, $redirectTo)->createView(),
                'flagRollbackPendingFormView' => $isCurrent
                    ? null
                    : $this->getFactory()->createRollbackForm($aliasName, $indexName, $redirectTo)->createView(),
                'isRollbackPending' => $isRollbackPending,
            ];
        }

        return $indexRows;
    }

    /**
     * Only ever resolves when BOTH source and store are given and match a real managed scope -- indices
     * are not comparable across scopes, so a partial filter (e.g. source only) intentionally shows a
     * picker instead of guessing which scope to display.
     *
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     * @param string $selectedSource
     * @param string $selectedStore
     */
    protected function resolveScope(array $managedScopes, string $selectedSource, string $selectedStore): ?SearchIndexScopeTransfer
    {
        if ($selectedSource === '' || $selectedStore === '') {
            return null;
        }

        foreach ($managedScopes as $searchIndexScopeTransfer) {
            if ($searchIndexScopeTransfer->getSourceIdentifier() === $selectedSource && $searchIndexScopeTransfer->getStoreName() === $selectedStore) {
                return $searchIndexScopeTransfer;
            }
        }

        return null;
    }

    /**
     * @param string $selectedSource
     * @param string $selectedStore
     */
    protected function buildIndexUrl(string $selectedSource, string $selectedStore): string
    {
        return sprintf('/search-index-alias/index?source=%s&store=%s', urlencode($selectedSource), urlencode($selectedStore));
    }
}
