<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\Presentation;

use SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PageObject\HistoryPage;
use SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PageObject\OverviewPage;
use SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester;

/**
 * Both Zed pages load without error against this project's own real managed scope, and the sidebar
 * carries this package's own navigation entry -- covers wiring, not the actual rollout mechanics (see
 * the other Cests in this suite for those).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAliasGuiPresentation
 * @group Presentation
 * @group PreFlightCest
 * Add your own group annotations below this line
 */
class PreFlightCest
{
    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function _before(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amZed();
        $i->amLoggedInUser();
    }

    /**
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function overviewPageLoadsWithARealScopesPhysicalIndices(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amOnPage(OverviewPage::buildUrl(
            SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER,
            SearchIndexAliasGuiPresentationTester::DEFAULT_STORE_NAME,
        ));

        $i->see(SearchIndexAliasGuiPresentationTester::ALIAS_NAME_DEFAULT_SCOPE);
        $i->see('Current alias?');
        $i->see('Rollout status');
        $i->seeElement('#' . OverviewPage::SELECT_SOURCE_ID);
        $i->seeElement('#' . OverviewPage::SELECT_STORE_ID);
        $i->see(OverviewPage::REBUILD_BUTTON_TEXT);
    }

    /**
     * A partial filter (source picked, store not) must show a picker prompt rather than guessing which
     * scope was meant -- see IndexController::resolveScope()'s own doc block.
     *
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function overviewPageShowsAPickerPromptForAPartialFilter(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amOnPage(OverviewPage::URL . '?source=' . SearchIndexAliasGuiPresentationTester::DEFAULT_SOURCE_IDENTIFIER);

        // "Rebuild" also appears in this page's own always-rendered instructional copy ('"Rebuild"
        // builds a fresh target index...'), so asserting the substring is absent would be wrong --
        // assert on the actual prompt text and the absence of the specific button element instead.
        $i->see('Select a source and a store above to manage its indices.');
        $i->dontSeeElement("//button[text()='" . OverviewPage::REBUILD_BUTTON_TEXT . "']");
    }

    /**
     * History renders as a pure read-only audit log for a real scope -- no action buttons, since every
     * action lives on the Overview page (see RolloutController::historyAction()'s own doc block).
     *
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function historyPageLoadsAsAReadOnlyAuditLog(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amOnPage(HistoryPage::buildUrl(SearchIndexAliasGuiPresentationTester::ALIAS_NAME_DEFAULT_SCOPE));

        $i->see('Rollout history');
        $i->see(SearchIndexAliasGuiPresentationTester::ALIAS_NAME_DEFAULT_SCOPE);
        $i->see('Started');
        $i->see('Outcome');
        $i->see(HistoryPage::BACK_TO_OVERVIEW_LINK_TEXT);
        // Scoped to the content wrapper (.wrapper-content, this Gui theme's own real class), not the
        // whole page -- the Zed layout's own chrome (dark-mode toggle, sidebar hamburger) has real
        // <button> elements unrelated to this page's own content.
        $i->dontSeeElement('.wrapper-content button');
    }

    /**
     * The sidebar renders this package's own navigation.xml wrapper/label verbatim, nested under the
     * shared "Search Toolbox" parent this project's own demoshop wiring adopts it into -- History is
     * deliberately NOT a sidebar entry (visible=0 in navigation.xml, reachable only via the Overview
     * page's own "View rollout history" link).
     *
     * @param \SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\SearchIndexAliasGuiPresentationTester $i
     */
    public function sidebarListsTheSearchIndexAliasEntryUnderSearchToolbox(SearchIndexAliasGuiPresentationTester $i): void
    {
        $i->amOnPage(OverviewPage::URL);

        $i->see('Search Toolbox');
        $i->see('Search Index Alias');
    }
}
