<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface BulkLoaderInterface
{
    /**
     * Populates $targetIndexName directly from every `spy_*_search` table configured for this scope's
     * sourceIdentifier (see `SearchIndexAliasConfig::getSpySearchSourceTables()`), bypassing the
     * publish/sync queue entirely -- see this package's README, "How it works", for why this is safe and
     * why it's what makes the rebuild never need to touch the live index.
     *
     * $targetIndexName's mapping MUST already exist and be correct BEFORE calling this (see
     * IndexCloner/MappingDiffClassifier, P5) -- this method writes raw documents with no mapping of its
     * own. Confirmed live: bulk-loading into a bare, unmapped index lets Elasticsearch's own dynamic-type
     * inference pick a type from whichever rows happen to be written first, and later rows with a
     * genuinely different (but equally valid) type for the same field then fail outright
     * (`mapper [...] cannot be changed from type [text] to [long]`) -- silently, at write time, not in
     * any way this method's own return value would surface in advance.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     *
     * @return int Number of documents written.
     */
    public function load(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $targetIndexName): int;
}
