<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget;

interface SearchIndexDeployRollbackTargetMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget,
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
    ): SearchIndexDeployRollbackTargetTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget
     */
    public function mapTransferToEntity(
        SearchIndexDeployRollbackTargetTransfer $searchIndexDeployRollbackTargetTransfer,
        SpySearchIndexDeployRollbackTarget $spySearchIndexDeployRollbackTarget,
    ): SpySearchIndexDeployRollbackTarget;
}
