<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

interface PhysicalIndexListerInterface
{
    /**
     * Every physical index in the cluster whose name belongs to this alias (see
     * `IndexNameBuilder::belongsToAlias()`) — the concrete index from first adoption, every rebuild
     * target ever created, aliased or not, pruned or still around. Unsorted.
     *
     * @param string $aliasName
     *
     * @return array<string>
     */
    public function listIndexNamesForAlias(string $aliasName): array;
}
