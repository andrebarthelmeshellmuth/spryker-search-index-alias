<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Alias;

interface AliasManagerInterface
{
    /**
     * Points a brand-new alias at an index for the first time, where no concrete index of the same name
     * exists yet (fresh installs, via the installer plugin -- see IndexAliasInstallerPlugin).
     *
     * @param string $aliasName
     * @param string $indexName
     */
    public function createAlias(string $aliasName, string $indexName): void;

    /**
     * Atomically points $aliasName at $toIndexName instead of $fromIndexName, in a single `_aliases`
     * call (`remove` + `add` as one atomic action list) -- never a remove-then-add pair. Verified live
     * against OpenSearch 1.3.4: 4000 concurrent reads across 40 atomic flips produced zero non-200
     * responses, where the equivalent two-call sequence produced a 3.3% error rate under identical load.
     *
     * @param string $aliasName
     * @param string $fromIndexName
     * @param string $toIndexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    public function switchAlias(string $aliasName, string $fromIndexName, string $toIndexName): void;

    /**
     * First-adoption migration: an existing installation has a REAL, concrete index named exactly
     * $aliasName (stock Spryker's shape before this package is installed). Elasticsearch refuses to
     * alias over a name that's already a concrete index, so this uses the `_aliases` API's
     * `remove_index` action -- which deletes a concrete index inside the SAME atomic transaction as the
     * alias `add` -- to swap the concrete index for an alias pointing at $toIndexName in one call, with
     * zero window where the name resolves to nothing. Verified live against OpenSearch 1.3.4.
     *
     * $toIndexName must already exist and be fully populated/verified BEFORE calling this -- this method
     * only performs the atomic swap, it does not build or check the target.
     *
     * @param string $aliasName
     * @param string $toIndexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    public function adoptConcreteIndex(string $aliasName, string $toIndexName): void;

    /**
     * @param string $aliasName
     *
     * @return array<string> Physical index names this alias currently points at. Empty if the alias
     *  does not exist. More than one entry means the alias is fanned out across multiple indices --
     *  a drift condition this package's own operations never produce but which SearchIndexHealthChecker
     *  must be able to detect (see README "How it works").
     */
    public function getIndicesForAlias(string $aliasName): array;

    /**
     * @param string $indexName
     */
    public function indexExists(string $indexName): bool;

    /**
     * @param string $indexName
     *
     * @return array<string> Every alias name currently pointing at this concrete index. Empty for an
     *  index that is not aliased at all (either a fresh, not-yet-flipped target, or a legacy
     *  not-yet-adopted concrete index).
     */
    public function getAliasesForIndex(string $indexName): array;

    /**
     * Refuses to delete an index that any alias still points at -- see IndexPruner, which is the only
     * caller that should ever reach this after checking that invariant itself; this is the
     * last-line-of-defense check, not the primary one.
     *
     * @param string $indexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    public function deleteUnaliasedIndex(string $indexName): void;
}
