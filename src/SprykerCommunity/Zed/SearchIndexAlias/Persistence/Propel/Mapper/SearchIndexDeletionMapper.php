<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion;

class SearchIndexDeletionMapper implements SearchIndexDeletionMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion $spySearchIndexDeletion
     * @param \Generated\Shared\Transfer\SearchIndexDeletionTransfer $searchIndexDeletionTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexDeletion $spySearchIndexDeletion,
        SearchIndexDeletionTransfer $searchIndexDeletionTransfer,
    ): SearchIndexDeletionTransfer {
        return $searchIndexDeletionTransfer
            ->setIdSearchIndexDeletion($spySearchIndexDeletion->getIdSearchIndexDeletion())
            ->setIndexName($spySearchIndexDeletion->getIndexName())
            ->setTriggeredByUser($spySearchIndexDeletion->getTriggeredByUser())
            ->setCreatedAt($spySearchIndexDeletion->getCreatedAt()?->format(DATE_ATOM))
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier($spySearchIndexDeletion->getSourceIdentifier())
                    ->setStoreName($spySearchIndexDeletion->getStoreName())
                    ->setAliasName($spySearchIndexDeletion->getAliasName()),
            );
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeletionTransfer $searchIndexDeletionTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion $spySearchIndexDeletion
     */
    public function mapTransferToEntity(
        SearchIndexDeletionTransfer $searchIndexDeletionTransfer,
        SpySearchIndexDeletion $spySearchIndexDeletion,
    ): SpySearchIndexDeletion {
        $searchIndexScopeTransfer = $searchIndexDeletionTransfer->getSearchIndexScopeOrFail();

        return $spySearchIndexDeletion
            ->setSourceIdentifier($searchIndexScopeTransfer->getSourceIdentifierOrFail())
            ->setStoreName($searchIndexScopeTransfer->getStoreNameOrFail())
            ->setAliasName($searchIndexScopeTransfer->getAliasNameOrFail())
            ->setIndexName($searchIndexDeletionTransfer->getIndexNameOrFail())
            ->setTriggeredByUser($searchIndexDeletionTransfer->getTriggeredByUser());
    }
}
