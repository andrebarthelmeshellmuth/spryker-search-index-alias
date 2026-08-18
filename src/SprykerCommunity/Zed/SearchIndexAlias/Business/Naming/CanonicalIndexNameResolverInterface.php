<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Naming;

interface CanonicalIndexNameResolverInterface
{
    /**
     * The alias name for this (sourceIdentifier, storeName) pair -- exactly what core's own
     * `console search:setup`/Client `IndexNameResolver` would have called the physical index, computed
     * from the same config a project already maintains. See class doc block for why this is a
     * deliberate reimplementation rather than a dependency on core's internal `SourceIdentifier` class.
     *
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function resolve(string $sourceIdentifier, string $storeName): string;

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function isSupported(string $sourceIdentifier, string $storeName): bool;
}
