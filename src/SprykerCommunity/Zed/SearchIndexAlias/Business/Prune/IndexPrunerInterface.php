<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Prune;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface IndexPrunerInterface
{
    /**
     * Deletes old, unaliased physical indices that belong to this scope's alias (see
     * `IndexNameBuilder::belongsToAlias()`), keeping the `SearchIndexAliasConfig::getKeepIndicesCount()`
     * most recent ones as a rollback buffer. The currently-aliased (live) index is never a candidate --
     * every candidate is first confirmed unaliased via `AliasManager::getAliasesForIndex()`, and
     * `AliasManager::deleteUnaliasedIndex()` (the only method this ever calls to actually delete) refuses
     * again as a last line of defense.
     *
     * Each deleted index is also recorded to spy_search_index_deletion (see that table's own schema
     * comment) -- the History page's audit trail for "what happened and who did it", independent of
     * spy_search_index_rollout.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     *
     * @return array<string> The physical index names that were deleted.
     */
    public function pruneScope(SearchIndexScopeTransfer $searchIndexScopeTransfer, ?string $triggeredByUser = null): array;
}
