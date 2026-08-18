<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Prune;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class IndexPruner implements IndexPrunerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface $physicalIndexLister
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     */
    public function __construct(
        protected PhysicalIndexListerInterface $physicalIndexLister,
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @return array<string>
     */
    public function pruneScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): array
    {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();

        $candidateIndexNames = array_values(array_filter(
            $this->physicalIndexLister->listIndexNamesForAlias($aliasName),
            fn (string $indexName): bool => $this->aliasManager->getAliasesForIndex($indexName) === [],
        ));

        sort($candidateIndexNames);

        $keepCount = $this->searchIndexAliasConfig->getKeepIndicesCount();
        $deletableCount = count($candidateIndexNames) - $keepCount;

        if ($deletableCount <= 0) {
            return [];
        }

        $indexNamesToDelete = array_slice($candidateIndexNames, 0, $deletableCount);

        foreach ($indexNamesToDelete as $indexName) {
            $this->aliasManager->deleteUnaliasedIndex($indexName);
        }

        return $indexNamesToDelete;
    }
}
