<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion;

interface SearchIndexDeletionMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion $spySearchIndexDeletion
     * @param \Generated\Shared\Transfer\SearchIndexDeletionTransfer $searchIndexDeletionTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexDeletion $spySearchIndexDeletion,
        SearchIndexDeletionTransfer $searchIndexDeletionTransfer,
    ): SearchIndexDeletionTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeletionTransfer $searchIndexDeletionTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletion $spySearchIndexDeletion
     */
    public function mapTransferToEntity(
        SearchIndexDeletionTransfer $searchIndexDeletionTransfer,
        SpySearchIndexDeletion $spySearchIndexDeletion,
    ): SpySearchIndexDeletion;
}
