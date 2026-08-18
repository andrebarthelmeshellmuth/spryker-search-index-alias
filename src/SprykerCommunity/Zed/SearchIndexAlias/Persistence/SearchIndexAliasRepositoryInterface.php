<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;

interface SearchIndexAliasRepositoryInterface
{
    /**
     * @param int $idSearchIndexRollout
     */
    public function findRolloutById(int $idSearchIndexRollout): ?SearchIndexRolloutTransfer;

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findLatestRolloutForScope(string $sourceIdentifier, string $storeName): ?SearchIndexRolloutTransfer;

    /**
     * The one rollout for this scope currently NOT in a terminal status, if any -- see
     * `SearchIndexAliasConfig::TERMINAL_STATUSES`. There can be at most one, enforced at the database
     * level by the `active_scope_key` unique index (see the schema).
     *
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findActiveRolloutForScope(string $sourceIdentifier, string $storeName): ?SearchIndexRolloutTransfer;

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getRolloutHistoryForScope(string $sourceIdentifier, string $storeName, int $limit = 20): array;

    /**
     * The latest rollout row for every distinct (source_identifier, store_name) pair that has ever had
     * one -- the GUI overview's data source. A scope with no row at all yet (never adopted, never
     * rebuilt) simply has no entry here; combine with IndexEnumerator::enumerateScopes() to see those
     * too.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getLatestRolloutPerScope(): array;

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findPendingRollbackTargetForScope(string $sourceIdentifier, string $storeName): ?SearchIndexDeployRollbackTargetTransfer;

    /**
     * Every scope that currently has a pending rollback target flagged -- the rollback counterpart to
     * `findActiveRolloutForScope()` READY+flipPending rows, used by `DeployFlipRunner`.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer>
     */
    public function getAllPendingRollbackTargets(): array;
}
