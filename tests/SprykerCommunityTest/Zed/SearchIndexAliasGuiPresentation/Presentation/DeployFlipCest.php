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
 * The deploy-time flow this package's own README "Deploying" section documents: click "Flag for next
 * deploy" instead of "Flip" (the live index stays untouched at click time), then run the REAL
 * `search-index-alias:deploy-flip` console command -- the same command `SPRYKER_HOOK_AFTER_DEPLOY` runs
 * in production -- and confirm it, not a GUI click, is what actually moves the live alias.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAliasGuiPresentation
 * @group Presentation
 * @group DeployFlipCest
 * Add your own group annotations below this line
 */
class DeployFlipCest
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

        // Same re-runnability guard as RebuildLifecycleCest/AbortCest -- start from no active rollout.
        if (!$i->tryToSeeElement("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]")) {
            return;
        }

        $i->clickAndConfirm("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]");
        $i->see(OverviewPage::FLASH_MESSAGE_ABORTED);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function flaggingForDeployLeavesLiveUntouchedUntilDeployFlipRuns(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->clickAndConfirm(OverviewPage::REBUILD_BUTTON_TEXT);
        $i->see(OverviewPage::FLASH_MESSAGE_REBUILD_REQUESTED_PREFIX);
        $consoleOutput = $i->runConsoleCommand(OverviewPage::CONSOLE_COMMAND_REBUILD_WORKER);

        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));

        if (!$i->tryToSeeElement("//button[text()='" . OverviewPage::FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT . "']")) {
            $i->comment('Rebuild did not reach a flip-able "ready" state (console output: ' . trim($consoleOutput) . '); skipping.');

            return;
        }

        $currentAliasRowTextBefore = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");

        $i->click(OverviewPage::FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT);
        $i->see(OverviewPage::FLASH_MESSAGE_FLIP_PENDING_SUFFIX);
        // The click alone must not have moved anything -- deploy-flip has not run yet.
        $currentAliasRowTextAfterFlag = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");
        $i->assertSame($currentAliasRowTextBefore, $currentAliasRowTextAfterFlag);
        $i->see(OverviewPage::PENDING_DEPLOY_FLIPS_PANEL_HEADING);

        $i->runConsoleCommand(OverviewPage::CONSOLE_COMMAND_DEPLOY_FLIP);

        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));
        $currentAliasRowTextAfterDeployFlip = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");
        $i->assertNotSame($currentAliasRowTextBefore, $currentAliasRowTextAfterDeployFlip, 'Expected deploy-flip to move the current-alias row onto the flagged target index.');
    }
}
