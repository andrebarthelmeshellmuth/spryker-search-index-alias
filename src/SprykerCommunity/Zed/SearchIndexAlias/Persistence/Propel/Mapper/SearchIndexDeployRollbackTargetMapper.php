<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget;

class SearchIndexDeployRollbackTargetMapper implements SearchIndexDeployRollbackTargetMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget,
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
    ): SearchIndexDeployRollbackTargetTransfer {
        return $searchIndexDeployRollbackTargetTransfer
            ->setIdSearchIndexDeployRollbackTarget($spySearchIndexDeployRollbackTarget->getIdSearchIndexDeployRollbackTarget())
            ->setTargetIndexName($spySearchIndexDeployRollbackTarget->getTargetIndexName())
            ->setTriggeredByUser($spySearchIndexDeployRollbackTarget->getTriggeredByUser())
            ->setCreatedAt($spySearchIndexDeployRollbackTarget->getCreatedAt()?->format(DATE_ATOM))
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier($spySearchIndexDeployRollbackTarget->getSourceIdentifier())
                    ->setStoreName($spySearchIndexDeployRollbackTarget->getStoreName())
                    ->setAliasName($spySearchIndexDeployRollbackTarget->getAliasName()),
            );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget
     */
    public function mapTransferToEntity(
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
        SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget,
    ): SpySearchIndexDeployRollbackTarget {
        $searchIndexScopeTransfer = $searchIndexDeployRollbackTargetTransfer->getSearchIndexScopeOrFail();

        return $spySearchIndexDeployRollbackTarget
            ->setSourceIdentifier($searchIndexScopeTransfer->getSourceIdentifierOrFail())
            ->setStoreName($searchIndexScopeTransfer->getStoreNameOrFail())
            ->setAliasName($searchIndexScopeTransfer->getAliasNameOrFail())
            ->setTargetIndexName($searchIndexDeployRollbackTargetTransfer->getTargetIndexNameOrFail())
            ->setTriggeredByUser($searchIndexDeployRollbackTargetTransfer->getTriggeredByUser());
    }
}
