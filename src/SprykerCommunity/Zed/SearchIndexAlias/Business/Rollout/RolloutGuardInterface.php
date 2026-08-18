<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface RolloutGuardInterface
{
    /**
     * Application-level pre-check, so a caller gets a clear error before attempting anything against
     * the cluster -- the database's own `active_scope_key` unique index (see
     * SearchIndexAliasEntityManager) remains the actual enforcement and the one that matters under a
     * real race; this is a courtesy, not a substitute for it.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function assertNoActiveRollout(SearchIndexScopeTransfer $searchIndexScopeTransfer): void;
}
