<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Prune;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface IndexDeleterInterface
{
    /**
     * Deletes a single, currently-unaliased physical index -- refuses if it is still aliased (see
     * `AliasManager::deleteUnaliasedIndex()`). A manual, single-index counterpart to `IndexPruner`'s
     * bulk cleanup, recording the same spy_search_index_deletion audit row.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $indexName
     * @param string|null $triggeredByUser
     */
    public function deleteIndex(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $indexName, ?string $triggeredByUser = null): void;
}
