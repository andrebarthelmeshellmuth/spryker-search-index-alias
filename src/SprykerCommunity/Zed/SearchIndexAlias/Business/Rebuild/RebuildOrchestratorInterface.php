<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface RebuildOrchestratorInterface
{
    /**
     * Full BUILDING phase for an already-adopted scope (see IndexAdopter for a scope with no alias yet):
     * clone the live index's current mapping/settings onto a fresh target, optionally layer
     * $targetMappingProperties on top (see IndexCloner::applyMapping() -- always safe, the target has no
     * documents yet), bind a mirror queue to the scope's sync exchange, bulk-load the target directly
     * from the database, then drain the mirror queue until it converges. Ends in status READY (or
     * FLIPPED if `SearchIndexAliasConfig::isAutoFlipEnabled()` is true) or FAILED.
     *
     * The live index is never written to at any point in this method -- see README "How it works".
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad Disables automatic refresh/replicas on the target during the bulk
     *  load, restoring both afterward -- real overhead savings at a large catalog's scale, invisible at
     *  a small one. Opt-in, default off.
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function start(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer;

    /**
     * GUI-safe: does only the fast synchronous row-creation, publishes the actual work to a queue
     * instead of running it inline -- see RebuildOrchestrator's own doc block for why. Returns a
     * `building` rollout immediately.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function requestRebuildAsync(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer;

    /**
     * The consumer side of `requestRebuildAsync()` -- picks up an already-created `building` rollout and
     * performs the actual work. Called by `RebuildRequestConsumer`, not meant to be called directly.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function executeQueuedRebuild(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
    ): SearchIndexRolloutTransfer;

    /**
     * The atomic flip: one final drain pass to catch anything published since the rollout reached READY,
     * then the atomic alias switch (see AliasManager), then the mirror queue is deleted and the rollout
     * marked FLIPPED.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function flip(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * Drops the target index (if it exists and is not aliased -- it never should be, pre-flip) and
     * deletes the mirror queue. The live index is untouched, so an abort at any point is a clean,
     * zero-cleanup-on-the-live-side no-op as far as production traffic is concerned.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function abort(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer;
}
