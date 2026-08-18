<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface RolloutStarterInterface
{
    /**
     * Records the start of a new rollout attempt: guards against a concurrent one for the same scope,
     * captures the scope's current live index (if any -- null for a first-adoption scope with no alias
     * yet), and persists a new row with status BUILDING.
     *
     * Does not itself touch the cluster -- see RebuildOrchestrator (P5) for what actually happens during
     * BUILDING.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function start(SearchIndexScopeTransfer $searchIndexScopeTransfer, ?string $triggeredByUser = null): SearchIndexRolloutTransfer;
}
