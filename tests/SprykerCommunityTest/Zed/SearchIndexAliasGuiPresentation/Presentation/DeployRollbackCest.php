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
 * The rollback counterpart to DeployFlipCest: flag an already-existing, non-current physical index row
 * ("Flag for next deploy") instead of clicking "Roll back to this index" directly -- the live index stays
 * untouched at click time, and only the real `search-index-alias:deploy-flip` console command (the same
 * one `SPRYKER_HOOK_AFTER_DEPLOY` runs in production) actually performs the atomic switch.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAliasGuiPresentation
 * @group Presentation
 * @group DeployRollbackCest
 * Add your own group annotations below this line
 */
class DeployRollbackCest
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

        // Same re-runnability guard as the sibling Cests -- start from no active rollout.
        if (!$i->tryToSeeElement("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]")) {
            return;
        }

        $i->clickAndConfirm("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]");
        $i->see(OverviewPage::FLASH_MESSAGE_ABORTED);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function flaggingAnOldIndexForRollbackLeavesLiveUntouchedUntilDeployFlipRuns(SearchIndexAliasGuiPresentationTester $i): void
    {
        $flagRowXpath = "(//tr[.//button[text()='" . OverviewPage::ROW_FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT . "']])[1]";

        if (!$i->tryToSeeElement($flagRowXpath)) {
            $i->comment('No non-current physical index row available to flag; skipping.');

            return;
        }

        $targetIndexName = $i->grabTextFrom($flagRowXpath . '/td[1]');
        $currentAliasRowTextBefore = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");

        $i->click("(//button[text()='" . OverviewPage::ROW_FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT . "'])[1]");
        $i->see(OverviewPage::FLASH_MESSAGE_ROLLBACK_PENDING_SUFFIX);
        $i->see(OverviewPage::PENDING_DEPLOY_FLIPS_PANEL_HEADING);
        $i->see($targetIndexName);
        // The click alone must not have moved anything -- deploy-flip has not run yet.
        $currentAliasRowTextAfterFlag = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");
        $i->assertSame($currentAliasRowTextBefore, $currentAliasRowTextAfterFlag);

        $i->runConsoleCommand(OverviewPage::CONSOLE_COMMAND_DEPLOY_FLIP);

        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));
        $currentAliasRowTextAfterDeployFlip = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");
        $i->assertSame($targetIndexName, $currentAliasRowTextAfterDeployFlip, 'Expected deploy-flip to move the current-alias row onto the flagged rollback target.');
    }
}
