<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;

interface RolloutFinisherInterface
{
    /**
     * Records that the rollout's target index is built and verified, ready for the atomic flip -- does
     * not itself flip anything (see RebuildOrchestrator, P5).
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $targetIndexName
     * @param int $actualDocumentCount
     */
    public function markReady(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        string $targetIndexName,
        int $actualDocumentCount,
    ): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function markFlipped(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function markRolledBack(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function markAborted(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function markFailed(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function markFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    public function unmarkFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer;
}
