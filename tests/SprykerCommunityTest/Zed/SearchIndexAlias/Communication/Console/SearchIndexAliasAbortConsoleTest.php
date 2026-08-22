<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAbortConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasAbortConsoleTest
 * @group Portable
 */
class SearchIndexAliasAbortConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAbortConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testFailsWhenThereIsNoActiveRollout(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], null);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAbortConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No active rollout for "page-de" -- nothing to abort.', $commandTester->getDisplay());
    }

    public function testAbortsTheActiveRollout(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(7)->setStatus(SearchIndexAliasConfig::STATUS_ABORTED);
        $commandTester = $this->createCommandTester([$this->createScope()], $rollout, $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAbortConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Rollout 7 for "page-de" aborted.', $commandTester->getDisplay());
    }

    public function testFailsWhenTheAbortDoesNotEndUpAborted(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(7)->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);
        $abortedRollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(7)->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);
        $commandTester = $this->createCommandTester([$this->createScope()], $activeRollout, $abortedRollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAbortConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Abort did not complete cleanly: status=building', $commandTester->getDisplay());
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())->setAliasName('page-de')->setSourceIdentifier('page')->setStoreName('DE');
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     */
    protected function createCommandTester(
        array $managedScopes,
        ?SearchIndexRolloutTransfer $activeRollout = null,
        ?SearchIndexRolloutTransfer $abortedRollout = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'getActiveRollout', 'abortRollout'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('getActiveRollout')->willReturn($activeRollout);

        if ($abortedRollout !== null) {
            $facadeMock->method('abortRollout')->willReturn($abortedRollout);
        }

        $console = new SearchIndexAliasAbortConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasAbortConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
