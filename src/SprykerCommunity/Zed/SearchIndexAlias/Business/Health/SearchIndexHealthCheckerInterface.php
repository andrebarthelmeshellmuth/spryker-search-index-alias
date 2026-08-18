<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Health;

use Generated\Shared\Transfer\SearchIndexHealthCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexHealthTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface SearchIndexHealthCheckerInterface
{
    /**
     * Detects the one drift condition this package's own operations never produce but an external
     * actor could (a manual `_aliases` call, another tool) -- an alias resolving to zero or more than one
     * physical index. Deliberately detection-only: which of several aliased indices is "correct" when an
     * alias has drifted to point at more than one requires a human decision this package cannot make
     * safely on an operator's behalf.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function checkScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexHealthTransfer;

    public function checkAllManagedScopes(): SearchIndexHealthCollectionTransfer;
}
