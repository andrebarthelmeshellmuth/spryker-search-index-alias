<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use DateTime;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;

class RolloutFinisher implements RolloutFinisherInterface
{
    /**
     * `outcome` is a VARCHAR(255) "short summary" column (see the schema's own column comment) --
     * `failure_reason`/`abortedSearchIndexRolloutTransfer` carry the untruncated detail. Confirmed live
     * this needs enforcing, not just documenting: a real RabbitMQ Management API error (Guzzle's
     * exception message includes the full HTTP request/response, e.g. a 404's JSON body) is easily
     * 300+ characters, and an un-truncated `outcome` overflowing the column crashes the UPDATE that
     * records the failure itself -- which leaves the rollout permanently stuck in a non-terminal status
     * (its `active_scope_key` never clears), silently blocking every future rollout for that scope.
     *
     * @var int
     */
    protected const OUTCOME_MAX_LENGTH = 200;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager
     */
    public function __construct(protected SearchIndexAliasEntityManagerInterface $searchIndexAliasEntityManager)
    {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $targetIndexName
     * @param int $actualDocumentCount
     */
    public function markReady(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        string $targetIndexName,
        int $actualDocumentCount,
    ): SearchIndexRolloutTransfer {
        $searchIndexRolloutTransfer
            ->setStatus(SearchIndexAliasConfig::STATUS_READY)
            ->setTargetIndexName($targetIndexName)
            ->setActualDocumentCount($actualDocumentCount);

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function markFlipped(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer
            ->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED)
            ->setFlipPending(false)
            ->setFinishedAt((new DateTime())->format(DATE_ATOM))
            ->setOutcome(sprintf('flipped to %s', (string)$searchIndexRolloutTransfer->getTargetIndexName()));

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * A rollback IS a flip -- same terminal status, same atomic mechanism (see AliasManager::switchAlias()),
     * just to an older index instead of a freshly-built one. Kept as its own method rather than reusing
     * `markFlipped()` purely so the `outcome` text says what actually happened, since that's the one
     * thing an operator reading the History table sees at a glance.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function markRolledBack(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer
            ->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED)
            ->setFlipPending(false)
            ->setFinishedAt((new DateTime())->format(DATE_ATOM))
            ->setOutcome(sprintf('rolled back to %s', (string)$searchIndexRolloutTransfer->getTargetIndexName()));

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function markAborted(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer
            ->setStatus(SearchIndexAliasConfig::STATUS_ABORTED)
            ->setFlipPending(false)
            ->setFinishedAt((new DateTime())->format(DATE_ATOM))
            ->setOutcome($this->buildOutcome('aborted', $reason));

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function markFailed(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer
            ->setStatus(SearchIndexAliasConfig::STATUS_FAILED)
            ->setFlipPending(false)
            ->setFinishedAt((new DateTime())->format(DATE_ATOM))
            ->setOutcome($this->buildOutcome('failed', $reason))
            ->setFailureReason($reason);

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * Operator (or deploy-pipeline-in-waiting) intent: "flip this READY rollout the next time
     * `search-index-alias:deploy-flip` runs" -- see the schema column's own doc block. Only meaningful on
     * a READY rollout; every terminal transition above (`markFlipped`/`markRolledBack`/`markAborted`/
     * `markFailed`) clears it back to false as part of leaving READY, so a stale flag can never survive
     * onto the next rollout for the same scope. Also clears any pending rollback target for the same
     * scope -- the two are mutually exclusive (see PendingRollbackTargetManager's own doc block).
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function markFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $this->assertReady($searchIndexRolloutTransfer);

        $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();
        $this->searchIndexAliasEntityManager->deletePendingRollbackTargetForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        $searchIndexRolloutTransfer->setFlipPending(true);

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function unmarkFlipPending(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $this->assertReady($searchIndexRolloutTransfer);

        $searchIndexRolloutTransfer->setFlipPending(false);

        return $this->searchIndexAliasEntityManager->updateRollout($searchIndexRolloutTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException
     */
    protected function assertReady(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): void
    {
        if ($searchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_READY) {
            throw new RolloutNotReadyException(sprintf(
                'Rollout %d is not READY (status: %s) -- only a READY rollout can be flagged for deploy-time flip.',
                $searchIndexRolloutTransfer->getIdSearchIndexRollout() ?? 0,
                (string)$searchIndexRolloutTransfer->getStatus(),
            ));
        }
    }

    /**
     * @param string $verb
     * @param string $reason
     */
    protected function buildOutcome(string $verb, string $reason): string
    {
        $outcome = sprintf('%s: %s', $verb, $reason);

        if (mb_strlen($outcome) <= static::OUTCOME_MAX_LENGTH) {
            return $outcome;
        }

        return mb_substr($outcome, 0, static::OUTCOME_MAX_LENGTH - 1) . '…';
    }
}
