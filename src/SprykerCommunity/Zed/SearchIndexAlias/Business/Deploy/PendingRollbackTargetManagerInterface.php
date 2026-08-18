<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface PendingRollbackTargetManagerInterface
{
    /**
     * Flags an already-existing physical index as "flip to this on the next deploy-flip run" -- the
     * rollback counterpart to `RolloutFinisher::markFlipPending()`. Rejects a target that's already the
     * live index (nothing to roll back to) or that no longer exists.
     *
     * @api
     *
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
    ): void;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function unmark(SearchIndexScopeTransfer $searchIndexScopeTransfer): void;

    /**
     * @api
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function findFor(SearchIndexScopeTransfer $searchIndexScopeTransfer): ?string;
}
