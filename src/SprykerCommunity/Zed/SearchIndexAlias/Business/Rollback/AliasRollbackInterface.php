<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface AliasRollbackInterface
{
    /**
     * Atomically flips a scope's alias directly to an already-existing physical index -- typically an
     * older, superseded index that a previous flip left behind (see IndexPruner for why it's still
     * there: old targets are kept as a rollback buffer, not deleted immediately). Unlike a normal
     * rebuild's flip, no new index is built and no mirror queue is involved -- $targetIndexName must
     * already be a real, existing index with real data; this method only performs the atomic switch and
     * records it.
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
}
