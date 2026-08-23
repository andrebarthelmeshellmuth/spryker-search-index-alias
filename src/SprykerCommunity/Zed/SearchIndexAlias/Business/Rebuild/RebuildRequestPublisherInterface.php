<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;

interface RebuildRequestPublisherInterface
{
    /**
     * Publishes a request to run the heavy part of a rebuild (already-recorded as `$searchIndexRolloutTransfer`,
     * status `building`) asynchronously, off whichever request created that row -- see
     * RebuildOrchestrator::requestRebuildAsync() and the `search-index-alias:rebuild-worker` console
     * command that consumes this queue.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     * @param bool $fromSchema See `RebuildOrchestratorInterface::start()`'s own doc block.
     */
    public function publish(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
        bool $fromSchema = true,
    ): void;
}
