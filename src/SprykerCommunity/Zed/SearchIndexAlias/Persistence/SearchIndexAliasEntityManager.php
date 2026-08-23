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
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout;
use Propel\Runtime\Exception\PropelException;
use Spryker\Zed\Kernel\Persistence\AbstractEntityManager;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasPersistenceFactory getFactory()
 */
class SearchIndexAliasEntityManager extends AbstractEntityManager implements SearchIndexAliasEntityManagerInterface
{
    /**
     * MySQL's own duplicate-entry error code -- used to distinguish "the active_scope_key unique index
     * rejected this insert" from any other PropelException, since Propel does not expose a typed
     * exception for constraint violations specifically.
     *
     * @var string
     */
    protected const MYSQL_DUPLICATE_ENTRY_SQLSTATE = '23000';

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function createRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $spySearchIndexRollout = $this->getFactory()
            ->createSearchIndexRolloutMapper()
            ->mapTransferToEntity($searchIndexRolloutTransfer, new SpySearchIndexRollout());

        $this->applyActiveScopeKey($spySearchIndexRollout);

        try {
            $spySearchIndexRollout->save();
        } catch (PropelException $propelException) {
            if ($this->isDuplicateActiveScopeKey($propelException)) {
                throw new ConcurrentRolloutException(sprintf(
                    'A rollout for scope "%s" (%s/%s) is already in progress.',
                    $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getAliasNameOrFail(),
                    $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getSourceIdentifierOrFail(),
                    $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getStoreNameOrFail(),
                ), 0, $propelException);
            }

            throw $propelException;
        }

        return $this->getFactory()->createSearchIndexRolloutMapper()->mapEntityToTransfer(
            $spySearchIndexRollout,
            $searchIndexRolloutTransfer,
        );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function updateRollout(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $spySearchIndexRollout = $this->getFactory()
            ->createSpySearchIndexRolloutQuery()
            ->findOneByIdSearchIndexRollout($searchIndexRolloutTransfer->getIdSearchIndexRolloutOrFail());

        if ($spySearchIndexRollout === null) {
            $spySearchIndexRollout = new SpySearchIndexRollout();
        }

        $this->getFactory()->createSearchIndexRolloutMapper()->mapTransferToEntity($searchIndexRolloutTransfer, $spySearchIndexRollout);
        $this->applyActiveScopeKey($spySearchIndexRollout);

        $spySearchIndexRollout->save();

        return $this->getFactory()->createSearchIndexRolloutMapper()->mapEntityToTransfer(
            $spySearchIndexRollout,
            $searchIndexRolloutTransfer,
        );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     */
    public function savePendingRollbackTarget(
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
    ): SearchIndexDeployRollbackTargetTransfer {
        $searchIndexScopeTransfer = $searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail();

        $spySearchIndexDeployRollbackTarget = $this->getFactory()
            ->createSpySearchIndexDeployRollbackTargetQuery()
            ->filterBySourceIdentifier($searchIndexScopeTransfer->getSourceIdentifierOrFail())
            ->filterByStoreName($searchIndexScopeTransfer->getStoreNameOrFail())
            ->findOneOrCreate();

        $this->getFactory()->createSearchIndexDeployRollbackTargetMapper()->mapTransferToEntity(
            $searchIndexDeployRollbackTargetTransfer,
            $spySearchIndexDeployRollbackTarget,
        );
        $spySearchIndexDeployRollbackTarget->save();

        return $this->getFactory()->createSearchIndexDeployRollbackTargetMapper()->mapEntityToTransfer(
            $spySearchIndexDeployRollbackTarget,
            $searchIndexDeployRollbackTargetTransfer,
        );
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function deletePendingRollbackTargetForScope(string $sourceIdentifier, string $storeName): void
    {
        $this->getFactory()
            ->createSpySearchIndexDeployRollbackTargetQuery()
            ->filterBySourceIdentifier($sourceIdentifier)
            ->filterByStoreName($storeName)
            ->delete();
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeletionTransfer $searchIndexDeletionTransfer
     */
    public function recordIndexDeletion(SearchIndexDeletionTransfer $searchIndexDeletionTransfer): SearchIndexDeletionTransfer
    {
        $spySearchIndexDeletion = $this->getFactory()
            ->createSearchIndexDeletionMapper()
            ->mapTransferToEntity($searchIndexDeletionTransfer, new SpySearchIndexDeletion());

        $spySearchIndexDeletion->save();

        return $this->getFactory()->createSearchIndexDeletionMapper()->mapEntityToTransfer(
            $spySearchIndexDeletion,
            $searchIndexDeletionTransfer,
        );
    }

    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout $spySearchIndexRollout
     */
    protected function applyActiveScopeKey(SpySearchIndexRollout $spySearchIndexRollout): void
    {
        if (in_array($spySearchIndexRollout->getStatus(), SearchIndexAliasConfig::TERMINAL_STATUSES, true)) {
            $spySearchIndexRollout->setActiveScopeKey(null);

            return;
        }

        $spySearchIndexRollout->setActiveScopeKey(sprintf(
            '%s:%s',
            $spySearchIndexRollout->getSourceIdentifier(),
            $spySearchIndexRollout->getStoreName(),
        ));
    }

    /**
     * @param \Propel\Runtime\Exception\PropelException $propelException
     */
    protected function isDuplicateActiveScopeKey(PropelException $propelException): bool
    {
        $previous = $propelException->getPrevious();

        if ($previous === null) {
            return false;
        }

        return $previous->getCode() === static::MYSQL_DUPLICATE_ENTRY_SQLSTATE
            && str_contains($previous->getMessage(), 'active_scope_key');
    }
}
