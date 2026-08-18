<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface IndexAdopterInterface
{
    /**
     * First-time migration of an existing, un-aliased concrete index into this package's alias-managed
     * shape, with zero downtime: clone the concrete index's own current mapping/settings into a fresh
     * timestamped index, copy its documents via a server-side `_reindex` (re-reindexing to catch up any
     * documents written during the first pass, up to a bounded number of attempts), verify the document
     * counts converge, then atomically swap the concrete index for an alias pointing at the populated
     * clone (see AliasManager::adoptConcreteIndex()).
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     *
     * @return string The new physical index name now aliased.
     */
    public function adopt(SearchIndexScopeTransfer $searchIndexScopeTransfer): string;

    /**
     * Whether this scope is a not-yet-adopted concrete index -- i.e. whether adopt() is applicable at
     * all. False for a scope that's already aliased, or one where neither an alias nor a concrete index
     * exists yet.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function needsAdoption(SearchIndexScopeTransfer $searchIndexScopeTransfer): bool;
}
