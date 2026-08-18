<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuardInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarterInterface;
use Throwable;

class AliasRollback implements AliasRollbackInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarterInterface $rolloutStarter
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface $rolloutFinisher
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuardInterface $rolloutGuard
     */
    public function __construct(
        protected RolloutStarterInterface $rolloutStarter,
        protected RolloutFinisherInterface $rolloutFinisher,
        protected AliasManagerInterface $aliasManager,
        protected RolloutGuardInterface $rolloutGuard,
    ) {
    }

    /**
     * Validates before persisting anything -- these are pure input-rejection cases (bad target, nothing
     * to do), not real rollback attempts, so they must not leave a `failed` rollout row behind. Such a
     * row would wrongly become "the" event Overview correlates with whichever index it happened to name
     * as its target -- including, confusingly, the CURRENT live index if that's what was rejected as
     * "already live". A rollout row is only created once we're actually about to call switchAlias().
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     */
    public function rollbackToIndex(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser = null,
    ): SearchIndexRolloutTransfer {
        $this->rolloutGuard->assertNoActiveRollout($searchIndexScopeTransfer);

        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $liveIndexNames = $this->aliasManager->getIndicesForAlias($aliasName);
        $liveIndexName = $liveIndexNames[0] ?? null;

        if ($liveIndexName === null) {
            return $this->buildRejection($searchIndexScopeTransfer, $targetIndexName, $triggeredByUser, 'Scope has no live index to roll back from -- it has not been adopted yet.');
        }

        if ($liveIndexName === $targetIndexName) {
            return $this->buildRejection($searchIndexScopeTransfer, $targetIndexName, $triggeredByUser, sprintf('"%s" is already the live index -- nothing to roll back.', $targetIndexName));
        }

        if (!$this->aliasManager->indexExists($targetIndexName)) {
            return $this->buildRejection($searchIndexScopeTransfer, $targetIndexName, $triggeredByUser, sprintf('"%s" no longer exists -- it may already have been pruned.', $targetIndexName));
        }

        $searchIndexRolloutTransfer = $this->rolloutStarter->start($searchIndexScopeTransfer, $triggeredByUser);
        $searchIndexRolloutTransfer->setTargetIndexName($targetIndexName);

        try {
            $this->aliasManager->switchAlias($aliasName, $liveIndexName, $targetIndexName);
        } catch (Throwable $throwable) {
            return $this->rolloutFinisher->markFailed($searchIndexRolloutTransfer, $throwable->getMessage());
        }

        return $this->rolloutFinisher->markRolledBack($searchIndexRolloutTransfer);
    }

    /**
     * An in-memory-only failure response for a rejection that never got far enough to be worth
     * persisting -- callers only ever read `getStatus()`/`getFailureReason()` off this, the same as any
     * other failed rollout, they just never see an `idSearchIndexRollout`.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string|null $triggeredByUser
     * @param string $reason
     */
    protected function buildRejection(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        ?string $triggeredByUser,
        string $reason,
    ): SearchIndexRolloutTransfer {
        return (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($searchIndexScopeTransfer)
            ->setTargetIndexName($targetIndexName)
            ->setTriggeredByUser($triggeredByUser)
            ->setStatus(SearchIndexAliasConfig::STATUS_FAILED)
            ->setFailureReason($reason);
    }
}
