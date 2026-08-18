<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy;

interface DeployFlipRunnerInterface
{
    /**
     * Every managed scope's active rollout that is READY and flagged flip-pending -- what
     * `search-index-alias:deploy-flip --dry-run` shows, and exactly the set `flipAllPending()` would act
     * on if called right now.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function findPendingFlipCandidates(): array;

    /**
     * The deploy pipeline's entrypoint (see README "Deploying" section and `SPRYKER_HOOK_AFTER_DEPLOY`):
     * flips every managed scope's rollout that is READY and flagged flip-pending, one scope at a time. A
     * single scope's flip failing does not stop the others -- each result carries its own
     * FLIPPED/FAILED status so the caller (console/CI) can report per-scope and still exit non-zero
     * overall if anything failed.
     *
     * @return array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>
     */
    public function flipAllPending(): array;
}
