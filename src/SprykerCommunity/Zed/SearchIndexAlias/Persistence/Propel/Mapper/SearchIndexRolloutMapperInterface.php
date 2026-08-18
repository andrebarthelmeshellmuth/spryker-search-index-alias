<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout;

interface SearchIndexRolloutMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout $spySearchIndexRollout
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexRollout $spySearchIndexRollout,
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
    ): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout $spySearchIndexRollout
     */
    public function mapTransferToEntity(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SpySearchIndexRollout $spySearchIndexRollout,
    ): SpySearchIndexRollout;
}
