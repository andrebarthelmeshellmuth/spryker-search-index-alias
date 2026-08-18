<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use Generated\Shared\Transfer\SearchIndexPhysicalIndexCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexPhysicalIndexTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;

class ScopeIndexOverview implements ScopeIndexOverviewInterface
{
    /**
     * Rollout rows are correlated by target index name, so this only needs to look back far enough to
     * plausibly cover every still-existing physical index -- generous on purpose, this is a read-only
     * GUI query, not a hot path.
     *
     * @var int
     */
    protected const ROLLOUT_HISTORY_LOOKBACK = 200;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface $physicalIndexLister
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface $indexCloner
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     */
    public function __construct(
        protected PhysicalIndexListerInterface $physicalIndexLister,
        protected AliasManagerInterface $aliasManager,
        protected IndexClonerInterface $indexCloner,
        protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function getIndicesForScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexPhysicalIndexCollectionTransfer
    {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();

        $currentAliasedIndexNames = $this->aliasManager->getIndicesForAlias($aliasName);
        $physicalIndexNames = $this->physicalIndexLister->listIndexNamesForAlias($aliasName);
        rsort($physicalIndexNames);

        $rolloutByTargetIndexName = [];

        foreach (
            $this->searchIndexAliasRepository->getRolloutHistoryForScope(
                $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
                $searchIndexScopeTransfer->getStoreNameOrFail(),
                static::ROLLOUT_HISTORY_LOOKBACK,
            ) as $searchIndexRolloutTransfer
        ) {
            $targetIndexName = $searchIndexRolloutTransfer->getTargetIndexName();

            if ($targetIndexName === null || isset($rolloutByTargetIndexName[$targetIndexName])) {
                continue;
            }

            $rolloutByTargetIndexName[$targetIndexName] = $searchIndexRolloutTransfer;
        }

        $searchIndexPhysicalIndexCollectionTransfer = new SearchIndexPhysicalIndexCollectionTransfer();

        foreach ($physicalIndexNames as $indexName) {
            $isCurrentAlias = in_array($indexName, $currentAliasedIndexNames, true);
            $searchIndexRolloutTransfer = $rolloutByTargetIndexName[$indexName] ?? null;

            $searchIndexPhysicalIndexCollectionTransfer->addSearchIndexPhysicalIndex(
                (new SearchIndexPhysicalIndexTransfer())
                    ->setIndexName($indexName)
                    ->setIsCurrentAlias($isCurrentAlias)
                    ->setStatus($this->resolveStatus($isCurrentAlias, $searchIndexRolloutTransfer))
                    ->setDocumentCount($this->indexCloner->getDocumentCount($indexName))
                    ->setSearchIndexRollout($searchIndexRolloutTransfer),
            );
        }

        return $searchIndexPhysicalIndexCollectionTransfer;
    }

    /**
     * @param bool $isCurrentAlias
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer|null $searchIndexRolloutTransfer
     */
    protected function resolveStatus(bool $isCurrentAlias, ?SearchIndexRolloutTransfer $searchIndexRolloutTransfer): string
    {
        if ($isCurrentAlias) {
            return SearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_CURRENT;
        }

        $rolloutStatus = $searchIndexRolloutTransfer?->getStatus();

        if ($rolloutStatus === SearchIndexAliasConfig::STATUS_FLIPPED) {
            return SearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_REPLACED;
        }

        if ($rolloutStatus === SearchIndexAliasConfig::STATUS_ABORTED || $rolloutStatus === SearchIndexAliasConfig::STATUS_FAILED) {
            return SearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_SKIPPED;
        }

        if ($rolloutStatus !== null) {
            return $rolloutStatus;
        }

        return SearchIndexAliasConfig::PHYSICAL_INDEX_STATUS_UNKNOWN;
    }
}
