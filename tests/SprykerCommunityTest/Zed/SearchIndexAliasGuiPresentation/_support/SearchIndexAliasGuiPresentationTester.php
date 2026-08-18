<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation;

use Codeception\Actor;
use Exception;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method \Codeception\Lib\Friend haveFriend($name, $actorClass = null)
 *
 * @SuppressWarnings(\SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PHPMD)
 */
class SearchIndexAliasGuiPresentationTester extends Actor
{
    use _generated\SearchIndexAliasGuiPresentationTesterActions;

    /**
     * @var string
     */
    public const DEFAULT_SOURCE_IDENTIFIER = 'page';

    /**
     * @var string
     */
    public const DEFAULT_STORE_NAME = 'DE';

    /**
     * This project's own real, already-adopted alias for DEFAULT_SOURCE_IDENTIFIER/DEFAULT_STORE_NAME --
     * confirmed live via the Overview page itself, same as this suite's sibling packages hardcode a
     * known-real scope/search-term/product rather than fabricating one.
     *
     * @var string
     */
    public const ALIAS_NAME_DEFAULT_SCOPE = 'spryker_b2b_marketplace_dev_de_page';

    /**
     * @param string $selector
     */
    public function tryToSeeElement(string $selector): bool
    {
        try {
            $this->seeElement($selector);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Clicks a submit button guarded by a native `confirm()` dialog (every action form on this
     * package's Overview page has one), accepts it, then waits for the resulting flash message to
     * actually be visible before returning. `acceptPopup()` dismissing the dialog only lets the
     * browser's own subsequent form POST + redirect START -- it does not itself wait for that
     * navigation to finish, so a `see()` called immediately afterward races the page load and
     * intermittently reads an empty/mid-navigation DOM. Confirmed live: without this wait, `see()`
     * calls right after `acceptPopup()` fail nondeterministically even though the flash text is
     * present moments later (visible in the very next screenshot).
     *
     * @param string $buttonSelector A `click()`-compatible selector (link/button text or a WebDriver locator).
     */
    public function clickAndConfirm(string $buttonSelector): void
    {
        $this->click($buttonSelector);
        $this->acceptPopup();
        $this->waitForElementVisible('.alert__text', 10);
    }

    /**
     * Runs a real console command inside this same container (the test process and `vendor/bin/console`
     * share one `/data` working directory) -- used to actually process a real "Rebuild" click's queued
     * request synchronously within a test, rather than either waiting for a long-running worker or
     * leaving the whole BUILDING phase (bulk-load, mirror-queue drain, mapping clone) untested. Mirrors
     * exactly what `search-index-alias:rebuild-worker` is documented to do continuously; this just runs
     * one pass and stops. Same pattern search-ranking-optimizer's own Presentation suite uses for its
     * cron-driven "optimize"/"calibrate" commands.
     *
     * @param string $command e.g. "search-index-alias:rebuild-worker --stop-when-empty"
     *
     * @return string Combined stdout+stderr, for assertions/debugging.
     */
    public function runConsoleCommand(string $command): string
    {
        $output = shell_exec(sprintf('cd /data && vendor/bin/console %s 2>&1', $command));

        return (string)$output;
    }
}
