<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface IndexEnumeratorInterface
{
    /**
     * Every (store, sourceIdentifier) index set this package manages, with its canonical alias name
     * already resolved (see CanonicalIndexNameResolver). Enumerated from the Store facade + the host
     * project's own `SearchElasticsearchConfig`/`SearchIndexAliasConfig` -- never by listing or
     * pattern-matching whatever happens to already exist in the cluster (see README "How it works" for
     * why: that would silently miss a scope that has never been created yet, which is exactly the
     * first-adoption case this package has to handle).
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexScopeTransfer>
     */
    public function enumerateScopes(): array;

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     *
     * @return \Generated\Shared\Transfer\SearchIndexScopeTransfer|null Null if this
     *  (sourceIdentifier, storeName) pair is not supported by the host project's search config at all
     *  (distinct from "supported but not yet built" -- see AliasManager for that case).
     */
    public function findScope(string $sourceIdentifier, string $storeName): ?SearchIndexScopeTransfer;
}
