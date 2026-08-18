<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

interface RebuildRequestConsumerInterface
{
    /**
     * Processes at most one pending request from the queue. "Processed" means the message was consumed
     * and acknowledged -- not that the rebuild itself succeeded, a request that ends in a FAILED rollout
     * is still correctly acked, the failure is on record in the rollout row itself.
     *
     * @return bool True if a request was processed, false if the queue was empty.
     */
    public function consumeOne(): bool;
}
