<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAbortConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAdoptConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasCheckInstallationConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasFlipConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasHealthConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasPruneConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildWorkerConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRollbackConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasStatusConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Deliberately hits this demoshop's OWN real project wiring for every check except
 * `checkSiblingCommandsRegistered()` (built with a fully controlled sibling `Application`, same
 * portability tradeoff spryker-community/search-ranking-optimizer's own
 * `SearchRankingOptimizerCheckInstallationConsoleTest` already accepts: this command exists specifically
 * to diagnose a REAL installation, a throwaway/mocked facade would prove nothing about whether the
 * project's own DependencyProvider/navigation.xml/config_default.php are actually wired. This demoshop is
 * expected to be fully wired (core namespace registered, navigation entries present, Elasticsearch and
 * the RabbitMQ Management API both reachable, the "page" scope has rebuild config, the Zed translation
 * catalog loaded and complete) -- asserted on accordingly.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasCheckInstallationConsoleTest
 * @group NeedsProject
 */
class SearchIndexAliasCheckInstallationConsoleTest extends Unit
{
    public function testSucceedsAndReportsEveryCheck(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([]);

        $this->assertSame(SearchIndexAliasCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('core namespace "SprykerCommunity" is registered', $commandTester->getDisplay());
        $this->assertStringContainsString('all 12 console command classes are present', $commandTester->getDisplay());
        $this->assertStringContainsString('the "spy_search_index_rollout" and "spy_search_index_deploy_rollback_target" tables exist and are queryable', $commandTester->getDisplay());
        $this->assertStringContainsString('navigation entries are registered in config/Zed/navigation.xml', $commandTester->getDisplay());
        $this->assertStringContainsString('Elasticsearch/OpenSearch is reachable', $commandTester->getDisplay());
        $this->assertStringContainsString('the RabbitMQ Management HTTP API is reachable', $commandTester->getDisplay());
        $this->assertStringContainsString('the Zed GUI translation catalog is loaded', $commandTester->getDisplay());
        $this->assertStringContainsString('all 49 Zed GUI strings are present in the translation catalog', $commandTester->getDisplay());
        $this->assertStringContainsString('Everything checkable from the CLI is in place.', $commandTester->getDisplay());
    }

    /**
     * "page" is this demoshop's own real, fully-configured managed scope (`getSpySearchSourceTables()`
     * has a "page" entry by default) -- must never be named among the unconfigured ones, even though
     * this project's other real managed scopes (product-review, return_reason, etc.) genuinely lack
     * rebuild config out of the box and are correctly warned about (this is a real, expected WARNING on
     * this project, not a failure -- exit code stays CODE_SUCCESS).
     */
    public function testDoesNotWarnAboutMissingRebuildConfigForTheRealPageScope(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([]);

        $this->assertSame(SearchIndexAliasCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $display = $commandTester->getDisplay();
        $this->assertMatchesRegularExpression(
            '/have no entry in SearchIndexAliasConfig::getSpySearchSourceTables\(\): [^\n]+/',
            $display,
        );
        preg_match('/have no entry in SearchIndexAliasConfig::getSpySearchSourceTables\(\): ([^.\n]+)/', $display, $matches);
        $unconfiguredSourceIdentifiers = array_map(trim(...), explode(',', $matches[1] ?? ''));
        $this->assertNotContains('page', $unconfiguredSourceIdentifiers);
    }

    /**
     * This demoshop's own real ACL state has exactly one unrestricted (root-style) role -- confirmed via
     * `AclFacade` directly in earlier live verification of this check. A real installation with no
     * restricted roles at all must be flagged: these actions (rebuild/flip/abort/roll back/delete) reach
     * real search infrastructure, unlike a typical read-only GUI page.
     */
    public function testWarnsThatAnUnrestrictedRoleCanReachTheHighImpactActions(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([]);

        $this->assertSame(SearchIndexAliasCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'unrestricted (root-style) back-office role(s) can reach this package\'s pages',
            $commandTester->getDisplay(),
        );
        $this->assertStringContainsString('Rebuild/Flip/Abort/Roll back/Delete', $commandTester->getDisplay());
    }

    protected function createCommandTester(): CommandTester
    {
        $console = new SearchIndexAliasCheckInstallationConsole();

        $application = new Application();
        $application->add($console);
        $application->add(new SearchIndexAliasStatusConsole());
        $application->add(new SearchIndexAliasAdoptConsole());
        $application->add(new SearchIndexAliasRebuildConsole());
        $application->add(new SearchIndexAliasRebuildWorkerConsole());
        $application->add(new SearchIndexAliasFlipConsole());
        $application->add(new SearchIndexAliasAbortConsole());
        $application->add(new SearchIndexAliasPruneConsole());
        $application->add(new SearchIndexAliasHealthConsole());
        $application->add(new SearchIndexAliasRollbackConsole());

        $command = $application->find(SearchIndexAliasCheckInstallationConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
