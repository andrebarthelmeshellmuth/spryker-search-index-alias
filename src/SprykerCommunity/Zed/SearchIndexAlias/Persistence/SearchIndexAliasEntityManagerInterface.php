<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;

interface SearchIndexAliasEntityManagerInterface
{
    /**
     * Creates a new rollout row for a scope that has no active (non-terminal) rollout yet.
     *
     * Enforced at the database level, not just checked in application code first: this INSERTs with
     * `active_scope_key` set to "{sourceIdentifier}:{storeName}", and the schema's UNIQUE index on that
     * column turns a concurrent duplicate attempt into a genuine constraint violation rather than a race
     * the application would otherwise have to detect itself between its own check and its own insert.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function createRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * Updates an existing rollout row (status transitions, target index name once known, document
     * counts, etc.). Clears `active_scope_key` back to null automatically once the new status is
     * terminal -- see `SearchIndexAliasConfig::TERMINAL_STATUSES` -- freeing the scope for a future
     * rollout.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function updateRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * Upserts a scope's pending rollback target row (unique on source_identifier+store_name) -- a second
     * call for the same scope overwrites the target rather than creating a duplicate.
     *
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     */
    public function savePendingRollbackTarget(
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
    ): SearchIndexDeployRollbackTargetTransfer;

    /**
     * A no-op if no pending rollback target exists for the scope.
     *
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function deletePendingRollbackTargetForScope(string $sourceIdentifier, string $storeName): void;
}
