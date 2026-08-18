<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rollout;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;

/**
 * INTEGRATION TEST — real database. The one behavior most worth protecting here is the outcome-truncation
 * safety net (see the class's own docblock: an untruncated outcome overflowing its VARCHAR(255) column
 * would crash the very UPDATE that records a failure, permanently stranding the rollout).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rollout
 * @group RolloutFinisherTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 */
class RolloutFinisherTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_finisher_source';

    protected function _before(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    protected function _after(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    public function testMarkReadySetsStatusTargetIndexAndActualDocumentCount(): void
    {
        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markReady(
            $this->persistRollout(),
            'myshop_de_page_20260101_120000',
            1064,
        );

        $this->assertSame(SearchIndexAliasConfig::STATUS_READY, $result->getStatus());
        $this->assertSame('myshop_de_page_20260101_120000', $result->getTargetIndexName());
        $this->assertSame(1064, $result->getActualDocumentCount());
    }

    public function testMarkFlippedSetsTerminalStatusFinishedAtAndOutcome(): void
    {
        $searchIndexRolloutTransfer = $this->persistRollout()->setTargetIndexName('myshop_de_page_20260101_120000');

        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFlipped($searchIndexRolloutTransfer);

        $this->assertSame(SearchIndexAliasConfig::STATUS_FLIPPED, $result->getStatus());
        $this->assertNotNull($result->getFinishedAt());
        $this->assertSame('flipped to myshop_de_page_20260101_120000', $result->getOutcome());
    }

    public function testMarkRolledBackUsesTheFlippedStatusButARollbackSpecificOutcome(): void
    {
        // A rollback IS a flip mechanically (same terminal status), but the outcome text must say what
        // actually happened, not the generic "flipped to" wording.
        $searchIndexRolloutTransfer = $this->persistRollout()->setTargetIndexName('myshop_de_page_20260101_090000');

        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markRolledBack($searchIndexRolloutTransfer);

        $this->assertSame(SearchIndexAliasConfig::STATUS_FLIPPED, $result->getStatus());
        $this->assertSame('rolled back to myshop_de_page_20260101_090000', $result->getOutcome());
    }

    public function testMarkAbortedSetsTerminalStatusAndOutcomeWithReason(): void
    {
        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markAborted($this->persistRollout(), 'operator-triggered');

        $this->assertSame(SearchIndexAliasConfig::STATUS_ABORTED, $result->getStatus());
        $this->assertSame('aborted: operator-triggered', $result->getOutcome());
    }

    public function testMarkFailedSetsTerminalStatusOutcomeAndFailureReason(): void
    {
        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFailed($this->persistRollout(), 'connection refused');

        $this->assertSame(SearchIndexAliasConfig::STATUS_FAILED, $result->getStatus());
        $this->assertSame('failed: connection refused', $result->getOutcome());
        $this->assertSame('connection refused', $result->getFailureReason());
    }

    public function testMarkFailedTruncatesAnOutcomeLongerThanTheColumnsSafeLength(): void
    {
        // The real trigger for this: a Guzzle HTTP exception message can easily exceed 255 characters
        // (it includes the full request/response). An untruncated UPDATE would overflow the VARCHAR(255)
        // `outcome` column and throw, permanently stranding the rollout in a non-terminal status.
        $longReason = str_repeat('a very long real-world error message fragment. ', 20);

        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFailed($this->persistRollout(), $longReason);

        $this->assertLessThanOrEqual(200, mb_strlen((string)$result->getOutcome()));
        $this->assertStringEndsWith('…', (string)$result->getOutcome());
        // failureReason itself is NOT truncated -- only outcome, the short-summary column.
        $this->assertSame($longReason, $result->getFailureReason());
    }

    public function testMarkFailedDoesNotTruncateAnOutcomeAtExactlyTheSafeLength(): void
    {
        // verb + ": " + reason must fit in exactly 200 chars without the ellipsis kicking in.
        $reason = str_repeat('a', 200 - mb_strlen('failed: '));

        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFailed($this->persistRollout(), $reason);

        $this->assertSame(200, mb_strlen((string)$result->getOutcome()));
        $this->assertStringEndsNotWith('…', (string)$result->getOutcome());
    }

    public function testMarkFlipPendingSetsTheFlagOnAReadyRollout(): void
    {
        $searchIndexRolloutTransfer = $this->persistRollout()->setStatus(SearchIndexAliasConfig::STATUS_READY);
        $readyRollout = (new SearchIndexAliasEntityManager())->updateRollout($searchIndexRolloutTransfer);

        $result = (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFlipPending($readyRollout);

        $this->assertTrue($result->getFlipPending());
    }

    public function testUnmarkFlipPendingClearsTheFlagOnAReadyRollout(): void
    {
        $searchIndexRolloutTransfer = $this->persistRollout()->setStatus(SearchIndexAliasConfig::STATUS_READY);
        $readyRollout = (new SearchIndexAliasEntityManager())->updateRollout($searchIndexRolloutTransfer);
        $rolloutFinisher = new RolloutFinisher(new SearchIndexAliasEntityManager());
        $pendingRollout = $rolloutFinisher->markFlipPending($readyRollout);

        $result = $rolloutFinisher->unmarkFlipPending($pendingRollout);

        $this->assertFalse($result->getFlipPending());
    }

    public function testMarkFlipPendingRejectsANonReadyRollout(): void
    {
        $this->expectException(RolloutNotReadyException::class);

        (new RolloutFinisher(new SearchIndexAliasEntityManager()))->markFlipPending($this->persistRollout());
    }

    public function testMarkFlippedClearsAnyPreviouslySetFlipPendingFlag(): void
    {
        $searchIndexRolloutTransfer = $this->persistRollout()->setStatus(SearchIndexAliasConfig::STATUS_READY)->setTargetIndexName('myshop_de_page_20260101_120000');
        $readyRollout = (new SearchIndexAliasEntityManager())->updateRollout($searchIndexRolloutTransfer);
        $rolloutFinisher = new RolloutFinisher(new SearchIndexAliasEntityManager());
        $pendingRollout = $rolloutFinisher->markFlipPending($readyRollout);

        $result = $rolloutFinisher->markFlipped($pendingRollout);

        $this->assertFalse($result->getFlipPending());
    }

    protected function persistRollout(): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
                    ->setStoreName('PHPUNIT')
                    ->setAliasName('phpunit_finisher_alias'),
            )
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);

        return (new SearchIndexAliasEntityManager())->createRollout($searchIndexRolloutTransfer);
    }
}
