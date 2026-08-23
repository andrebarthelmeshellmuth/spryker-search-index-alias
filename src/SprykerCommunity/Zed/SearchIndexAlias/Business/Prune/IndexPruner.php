<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Prune;

use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class IndexPruner implements IndexPrunerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface $physicalIndexLister
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     */
    public function __construct(
        protected PhysicalIndexListerInterface $physicalIndexLister,
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
        protected SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     *
     * @return array<string>
     */
    public function pruneScope(SearchIndexScopeTransfer $searchIndexScopeTransfer, ?string $triggeredByUser = null): array
    {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();

        // Never deletion candidates, even if chronologically old enough to qualify: these are the two
        // indices a deploy might act on next (see IndexDeleter's own doc blocks for why deleting either
        // one out from under deploy-flip is a real, previously-hit failure mode this guards against too).
        $activeRolloutTargetIndexName = $this->searchIndexAliasRepository->findActiveRolloutForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        )?->getTargetIndexName();
        $pendingRollbackTargetIndexName = $this->searchIndexAliasRepository->findPendingRollbackTargetForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        )?->getTargetIndexName();

        $candidateIndexNames = array_values(array_filter(
            $this->physicalIndexLister->listIndexNamesForAlias($aliasName),
            fn (string $indexName): bool => $indexName !== $activeRolloutTargetIndexName
                && $indexName !== $pendingRollbackTargetIndexName
                && $this->aliasManager->getAliasesForIndex($indexName) === [],
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
            $this->searchIndexAliasEntityManager->recordIndexDeletion(
                (new SearchIndexDeletionTransfer())
                    ->setSearchIndexScope($searchIndexScopeTransfer)
                    ->setIndexName($indexName)
                    ->setTriggeredByUser($triggeredByUser),
            );
        }

        return $indexNamesToDelete;
    }
}
