<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use Generated\Shared\Transfer\SearchIndexPhysicalIndexCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface ScopeIndexOverviewInterface
{
    /**
     * Every physical index belonging to this scope's alias, newest first, each flagged whether it is
     * the one currently aliased and correlated with the rollout that built it (if any is still on
     * record -- e.g. an adoption's target has none). This -- not the rollout history table -- is the
     * unit the GUI's per-scope page is built around: a rollout event and a physical index are not 1:1.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getIndicesForScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexPhysicalIndexCollectionTransfer;
}
