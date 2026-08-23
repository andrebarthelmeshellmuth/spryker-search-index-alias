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

interface SearchIndexAliasFacadeInterface
{
    /**
     * Every (store, sourceIdentifier) index set this package manages, per the host project's own
     * search + `SearchIndexAliasConfig` configuration.
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexScopeTransfer>
     */
    public function getManagedScopes(): array;

    /**
     * Migrates an existing installation's concrete index into an alias, atomically, with zero downtime
     * -- see AliasManager::adoptConcreteIndex() for the mechanism. $toIndexName must already exist and
     * be fully populated before calling this.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $toIndexName
     */
    public function adoptConcreteIndex(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $toIndexName): void;

    /**
     * Atomically switches a scope's alias from one physical index to another, in a single `_aliases`
     * call -- see AliasManager::switchAlias().
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $fromIndexName
     * @param string $toIndexName
     */
    public function switchAlias(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $fromIndexName, string $toIndexName): void;

    /**
     * A fresh, timestamped physical index name for this scope's alias -- does not create anything, just
     * computes the name (see IndexNameBuilder).
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function buildTargetIndexName(SearchIndexScopeTransfer $searchIndexScopeTransfer): string;

    /**
     * Full first-time adoption of an existing, un-aliased concrete index: clones its mapping/settings,
     * reindexes its documents server-side, verifies the counts converge, then atomically swaps it for an
     * alias -- see IndexAdopter for the mechanism. Zero downtime; the concrete index keeps serving
     * traffic under its original name for the entire clone/reindex, only becoming an alias in the final
     * atomic step.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException
     *
     * @return string The new physical index name now aliased.
     */
    public function adopt(SearchIndexScopeTransfer $searchIndexScopeTransfer): string;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function needsAdoption(SearchIndexScopeTransfer $searchIndexScopeTransfer): bool;

    /**
     * The one rollout for this scope currently in progress (not in a terminal status), if any.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getActiveRollout(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?SearchIndexRolloutTransfer;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getRolloutHistory(SearchIndexScopeTransfer $searchIndexScopeTransfer, int $limit = 20): array;

    /**
     * The latest rollout row for every scope that has ever had one -- the GUI overview's data source
     * (combine with getManagedScopes() to also see scopes that have never had a rollout at all).
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getLatestRolloutPerScope(): array;

    /**
     * Starts a blue-green rebuild for an already-adopted scope: builds a fresh target index (optionally
     * with a mapping change layered on top), bulk-loads it directly from the database, converges a
     * mirror queue against it, and -- unless `SearchIndexAliasConfig::isAutoFlipEnabled()` is disabled
     * (the default) -- atomically flips the alias once ready. The live index is never written to. See
     * RebuildOrchestrator for the full mechanism.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad Disables automatic refresh/replicas on the target during the bulk
     *  load, restoring both afterward. Opt-in, default off.
     * @param bool $fromSchema Build the target's base mapping+settings from the project's own
     *  `Shared/Search/Schema/*.json` definition(s) -- the default, see `SchemaIndexDefinitionResolver`'s
     *  own doc block. Pass `false` to instead clone the live index's current mapping+settings, e.g. when
     *  live has legitimately drifted from schema.json and that drift needs to survive the rebuild.
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function startRebuild(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
        bool $fromSchema = true,
    ): SearchIndexRolloutTransfer;

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
     * @param bool $fromSchema See `startRebuild()`'s own doc block.
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function requestRebuildAsync(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
        bool $fromSchema = true,
    ): SearchIndexRolloutTransfer;

    /**
     * Processes at most one pending async rebuild request -- see `search-index-alias:rebuild-worker`.
     *
     * @api
     *
     * @return bool True if a request was processed, false if the queue was empty.
     */
    public function consumeOneRebuildRequest(): bool;

    /**
     * The atomic flip for a rollout already in status READY -- see RebuildOrchestrator::flip().
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function flipRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * Flags a READY rollout as "flip this the next time the deploy pipeline runs
     * `search-index-alias:deploy-flip`" -- an explicit, per-scope opt-in distinct from merely being
     * READY, since a scope can be rebuilt and verified well ahead of a deploy without being meant to go
     * live at the very next one. See `SPRYKER_HOOK_AFTER_DEPLOY` / README "Deploying".
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function markFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function unmarkFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * Every managed scope's active rollout that is READY and flagged flip-pending right now -- what
     * `search-index-alias:deploy-flip --dry-run` and the Overview page's "Pending deploy flips" panel
     * both show, without flipping anything.
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function findPendingFlipCandidates(): array;

    /**
     * The deploy pipeline's entrypoint -- flips every managed scope's flip-pending READY rollout. See
     * `search-index-alias:deploy-flip` / `SPRYKER_HOOK_AFTER_DEPLOY`.
     *
     * @api
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function deployFlipPending(): array;

    /**
     * Flags an already-existing physical index as "flip to this the next time deploy-flip runs" -- the
     * rollback counterpart to `markFlipPending()`. Mutually exclusive with it per scope: marking one
     * clears the other.
     *
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
    ): void;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function unmarkPendingRollback(SearchIndexScopeTransfer $searchIndexScopeTransfer): void;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function findPendingRollbackTarget(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?string;

    /**
     * Aborts an in-progress rollout: drops its target index and mirror queue, leaving the live index
     * untouched -- see RebuildOrchestrator::abort().
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function abortRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer;

    /**
     * Deletes old, unaliased physical indices belonging to this scope's alias, keeping the
     * `SearchIndexAliasConfig::getKeepIndicesCount()` most recent ones as a rollback buffer -- see
     * IndexPruner.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     *
     * @return array<string> The physical index names that were deleted.
     */
    public function pruneScope(SearchIndexScopeTransfer $searchIndexScopeTransfer, ?string $triggeredByUser = null): array;

    /**
     * Detects alias drift (an alias resolving to zero or more than one physical index) -- see
     * SearchIndexHealthChecker.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function checkScopeHealth(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexHealthTransfer;

    /**
     * @api
     */
    public function checkAllManagedScopesHealth(): SearchIndexHealthCollectionTransfer;

    /**
     * Every physical index belonging to this scope's alias, newest first, flagged whether it is the one
     * currently aliased and correlated with the rollout that built it (if any is still on record) -- see
     * ScopeIndexOverview.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getIndicesForScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexPhysicalIndexCollectionTransfer;

    /**
     * Atomically flips a scope's alias directly to an already-existing physical index -- see
     * AliasRollback.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function rollbackToIndex(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser = null,
    ): SearchIndexRolloutTransfer;

    /**
     * Deletes a single, currently-unaliased physical index -- refuses if it is still aliased. A manual,
     * single-index counterpart to `pruneScope()`.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $indexName
     * @param string|null $triggeredByUser
     */
    public function deleteIndex(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $indexName, ?string $triggeredByUser = null): void;

    /**
     * Every manual/pruned deletion recorded for this scope, newest first -- see
     * spy_search_index_deletion's own schema comment for why this is separate from `getRolloutHistory()`.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexDeletionTransfer>
     */
    public function getDeletionHistory(SearchIndexScopeTransfer $searchIndexScopeTransfer, int $limit = 20): array;
}
