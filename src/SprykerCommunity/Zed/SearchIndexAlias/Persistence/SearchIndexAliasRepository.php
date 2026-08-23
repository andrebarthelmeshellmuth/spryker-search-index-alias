<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence;

use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Propel\Runtime\ActiveQuery\Criteria;
use Spryker\Zed\Kernel\Persistence\AbstractRepository;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasPersistenceFactory getFactory()
 */
class SearchIndexAliasRepository extends AbstractRepository implements SearchIndexAliasRepositoryInterface
{
    /**
     * @param int $idSearchIndexRollout
     */
    public function findRolloutById(int $idSearchIndexRollout): ?SearchIndexRolloutTransfer
    {
        $spySearchIndexRollout = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->filterByIdSearchIndexRollout($idSearchIndexRollout)
            ->findOne();

        if ($spySearchIndexRollout === null) {
            return null;
        }

        return $this->getFactory()->createSearchIndexRolloutMapper()->mapEntityToTransfer(
            $spySearchIndexRollout,
            new SearchIndexRolloutTransfer(),
        );
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findLatestRolloutForScope(string $sourceIdentifier, string $storeName): ?SearchIndexRolloutTransfer
    {
        $spySearchIndexRollout = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->orderByIdSearchIndexRollout(Criteria::DESC)
            ->findOne();

        if ($spySearchIndexRollout === null) {
            return null;
        }

        return $this->getFactory()->createSearchIndexRolloutMapper()->mapEntityToTransfer(
            $spySearchIndexRollout,
            new SearchIndexRolloutTransfer(),
        );
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findActiveRolloutForScope(string $sourceIdentifier, string $storeName): ?SearchIndexRolloutTransfer
    {
        $spySearchIndexRollout = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->filterByStatus(SearchIndexAliasConfig::TERMINAL_STATUSES, Criteria::NOT_IN)
            ->findOne();

        if ($spySearchIndexRollout === null) {
            return null;
        }

        return $this->getFactory()->createSearchIndexRolloutMapper()->mapEntityToTransfer(
            $spySearchIndexRollout,
            new SearchIndexRolloutTransfer(),
        );
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getRolloutHistoryForScope(string $sourceIdentifier, string $storeName, int $limit = 20): array
    {
        $spySearchIndexRollouts = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->orderByIdSearchIndexRollout(Criteria::DESC)
            ->limit($limit)
            ->find();

        $mapper = $this->getFactory()->createSearchIndexRolloutMapper();
        $searchIndexRolloutTransfers = [];

        foreach ($spySearchIndexRollouts as $spySearchIndexRollout) {
            $searchIndexRolloutTransfers[] = $mapper->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());
        }

        return $searchIndexRolloutTransfers;
    }

    /**
     * Two-step "latest row per group" (find the max IDs, then fetch those rows) rather than a single
     * correlated-subquery/window-function Criteria -- Propel's query builder has no first-class support
     * for either, and this stays portable across the MySQL versions this package targets rather than
     * depending on window-function support.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function getLatestRolloutPerScope(): array
    {
        $latestIds = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->withColumn('MAX(id_search_index_rollout)', 'MaxId')
            ->groupBy('source_identifier')
            ->groupBy('store_name')
            ->select(['MaxId'])
            ->find()
            ->getData();

        if ($latestIds === []) {
            return [];
        }

        $spySearchIndexRollouts = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->filterByIdSearchIndexRollout($latestIds, Criteria::IN)
            ->orderBySourceIdentifier()
            ->orderByStoreName()
            ->find();

        $mapper = $this->getFactory()->createSearchIndexRolloutMapper();
        $searchIndexRolloutTransfers = [];

        foreach ($spySearchIndexRollouts as $spySearchIndexRollout) {
            $searchIndexRolloutTransfers[] = $mapper->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());
        }

        return $searchIndexRolloutTransfers;
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findPendingRollbackTargetForScope(string $sourceIdentifier, string $storeName): ?SearchIndexDeployRollbackTargetTransfer
    {
        $spySearchIndexDeployRollbackTarget = $this->getFactory()
            ->createSpySearchIndexDeployRollbackTargetQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->findOne();

        if ($spySearchIndexDeployRollbackTarget === null) {
            return null;
        }

        return $this->getFactory()->createSearchIndexDeployRollbackTargetMapper()->mapEntityToTransfer(
            $spySearchIndexDeployRollbackTarget,
            new SearchIndexDeployRollbackTargetTransfer(),
        );
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer>
     */
    public function getAllPendingRollbackTargets(): array
    {
        $mapper = $this->getFactory()->createSearchIndexDeployRollbackTargetMapper();
        $searchIndexDeployRollbackTargetTransfers = [];

        foreach ($this->getFactory()->createSpySearchIndexDeployRollbackTargetQuery()->find() as $spySearchIndexDeployRollbackTarget) {
            $searchIndexDeployRollbackTargetTransfers[] = $mapper->mapEntityToTransfer($spySearchIndexDeployRollbackTarget, new SearchIndexDeployRollbackTargetTransfer());
        }

        return $searchIndexDeployRollbackTargetTransfers;
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexDeletionTransfer>
     */
    public function getDeletionHistoryForScope(string $sourceIdentifier, string $storeName, int $limit = 20): array
    {
        $spySearchIndexDeletions = $this->getFactory()
            ->createSpySearchIndexDeletionQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->orderByIdSearchIndexDeletion(Criteria::DESC)
            ->limit($limit)
            ->find();

        $mapper = $this->getFactory()->createSearchIndexDeletionMapper();
        $searchIndexDeletionTransfers = [];

        foreach ($spySearchIndexDeletions as $spySearchIndexDeletion) {
            $searchIndexDeletionTransfers[] = $mapper->mapEntityToTransfer($spySearchIndexDeletion, new SearchIndexDeletionTransfer());
        }

        return $searchIndexDeletionTransfers;
    }
}
