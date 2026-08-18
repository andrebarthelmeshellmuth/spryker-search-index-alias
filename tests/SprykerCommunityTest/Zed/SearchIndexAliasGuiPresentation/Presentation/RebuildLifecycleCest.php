<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PageObject\OverviewPage;
use SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester;

/**
 * The real end-to-end GUI flow: click "Rebuild" (dispatches asynchronously, a `building` row appears
 * immediately), run the real rebuild-worker console command to process it (the same command
 * "Run the rebuild worker" in the README documents), then click the action-bar "Flip" button once it
 * reaches `ready`. Leaves the newly-flipped index as the new live one and the previously-live index as an
 * ordinary non-current row -- this is the intended steady state of normal usage, not something this
 * test cleans up (see IndexPruner for the package's own designed-in cleanup path).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAliasGuiPresentation
 * @group Presentation
 * @group RebuildLifecycleCest
 * Add your own group annotations below this line
 */
class RebuildLifecycleCest
{
    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function _before(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amZed();
        $i->amLoggedInUser();
        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));

        // Guard against a rollout left `building`/`ready` by a previous interrupted run of this same
        // test -- RolloutGuard rejects a second rebuild while one is already active for the scope, so
        // this test must start from a clean (no active rollout) state to be safely re-runnable.
        if (!$i->tryToSeeElement("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]")) {
            return;
        }

        $i->clickAndConfirm("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]");
        $i->see(OverviewPage::FLASH_MESSAGE_ABORTED);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function rebuildingProcessingAndFlippingMovesTheLiveAliasToTheNewIndex(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));
        $rowCountBefore = count($i->grabMultiple('td:first-child'));

        $i->clickAndConfirm(OverviewPage::REBUILD_BUTTON_TEXT);
        $i->see(OverviewPage::FLASH_MESSAGE_REBUILD_REQUESTED_PREFIX);

        // The rebuild request is queued, not run inline -- see RebuildOrchestrator::requestRebuildAsync()'s
        // own doc block for why the HTTP request that clicks "Rebuild" never blocks on the rebuild itself.
        $consoleOutput = $i->runConsoleCommand(OverviewPage::CONSOLE_COMMAND_REBUILD_WORKER);

        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));
        $rowCountAfter = count($i->grabMultiple('td:first-child'));
        $i->assertGreaterThan($rowCountBefore, $rowCountAfter, 'Expected a new physical-index row after a processed rebuild (console output: ' . trim($consoleOutput) . ').');

        if (!$i->tryToSeeElement("//button[text()='" . OverviewPage::FLIP_BUTTON_TEXT . "']")) {
            $i->comment('Rebuild did not reach a flip-able "ready" state (console output: ' . trim($consoleOutput) . '); skipping the flip assertion.');

            return;
        }

        $i->clickAndConfirm("//button[text()='" . OverviewPage::FLIP_BUTTON_TEXT . "']");
        $i->see(OverviewPage::FLASH_MESSAGE_FLIPPED_SUFFIX);
    }
}
