<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror;

interface MirrorQueueDrainInterface
{
    /**
     * Drains every message currently sitting in the mirror queue, deduplicates by document key (last
     * message wins, whether it was a write or a delete), and applies the surviving entries to the target
     * index. A single pass only -- see README/RebuildOrchestrator for the "repeat until it converges"
     * loop this is meant to be called from repeatedly.
     *
     * @param string $mirrorQueueName
     * @param string $targetIndexName
     * @param string $storeName Only messages carrying this store (see class doc block) are applied --
     *  everything else on the queue is acknowledged and discarded, not left behind for a later scope to
     *  pick up (there is no "later scope" for a message that was never meant for this rebuild at all).
     *
     * @return int Number of messages drained (0 means the queue was empty at the moment of this call --
     *  the caller's signal that convergence may have been reached, though a message published after this
     *  call returns is still possible and must be caught by a subsequent drain).
     */
    public function drain(string $mirrorQueueName, string $targetIndexName, string $storeName): int;
}
