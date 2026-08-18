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
 * Aborting a `building` rollout via the GUI never touches the live index -- the target is left in place
 * for inspection (see RebuildOrchestrator::abort()'s own doc block), and the rollout row itself just
 * moves to a terminal `aborted` status.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAliasGuiPresentation
 * @group Presentation
 * @group AbortCest
 * Add your own group annotations below this line
 */
class AbortCest
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

        // Same re-runnability guard as RebuildLifecycleCest -- start from no active rollout.
        if (!$i->tryToSeeElement("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]")) {
            return;
        }

        $i->clickAndConfirm("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]");
        $i->see(OverviewPage::FLASH_MESSAGE_ABORTED);
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function abortingAnInProgressRolloutLeavesTheCurrentAliasRowUnchanged(SearchIndexAliasGuiPresentationTester $i): void
    {
        $currentAliasRowText = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");

        $i->clickAndConfirm(OverviewPage::REBUILD_BUTTON_TEXT);
        $i->see(OverviewPage::FLASH_MESSAGE_REBUILD_REQUESTED_PREFIX);

        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));
        $i->see('current alias');
        $i->clickAndConfirm("//button[contains(., '" . OverviewPage::ABORT_BUTTON_TEXT . "')]");
        $i->see(OverviewPage::FLASH_MESSAGE_ABORTED);

        // Still the same live index -- an abort at any point is a clean, zero-impact-on-live-traffic no-op.
        $currentAliasRowTextAfterAbort = $i->grabTextFrom("//tr[.//*[contains(text(), 'current alias')]]/td[1]");
        $i->assertSame($currentAliasRowText, $currentAliasRowTextAfterAbort);
    }
}
