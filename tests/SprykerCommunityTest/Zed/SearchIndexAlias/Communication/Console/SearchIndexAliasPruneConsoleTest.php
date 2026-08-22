<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasPruneConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasPruneConsoleTest
 * @group Portable
 */
class SearchIndexAliasPruneConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([], []);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasPruneConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testReportsNothingToPrune(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], []);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasPruneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Nothing to prune for "page-de".', $commandTester->getDisplay());
    }

    public function testDeletesTheOldIndices(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], ['page-de-1', 'page-de-2']);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasPruneConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Deleted 2 old index(es) for "page-de": page-de-1, page-de-2', $commandTester->getDisplay());
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())->setAliasName('page-de')->setSourceIdentifier('page')->setStoreName('DE');
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     * @param array<string> $deletedIndexNames
     */
    protected function createCommandTester(array $managedScopes, array $deletedIndexNames): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'pruneScope'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('pruneScope')->willReturn($deletedIndexNames);

        $console = new SearchIndexAliasPruneConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasPruneConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
