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
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasStatusConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasStatusConsoleTest
 * @group Portable
 */
class SearchIndexAliasStatusConsoleTest extends Unit
{
    public function testShowsEveryManagedScopeWithItsLatestRollout(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($this->createScope())
            ->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED)
            ->setTargetIndexName('page-de-2')
            ->setActualDocumentCount(500)
            ->setStartedAt('2026-08-20 10:00:00');
        $commandTester = $this->createCommandTester([$this->createScope()], [$rollout], false);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasStatusConsole::CODE_SUCCESS, $exitCode);
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('page-de', $display);
        $this->assertStringContainsString('flipped', $display);
        $this->assertStringContainsString('page-de-2', $display);
    }

    public function testFlagsAScopeThatStillNeedsAdoption(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], [], true);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasStatusConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('needs adoption', $commandTester->getDisplay());
    }

    public function testFiltersDownToASingleAlias(): void
    {
        // Arrange
        $other = (new SearchIndexScopeTransfer())->setAliasName('category-de')->setSourceIdentifier('category')->setStoreName('DE');
        $commandTester = $this->createCommandTester([$this->createScope(), $other], [], false);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasStatusConsole::CODE_SUCCESS, $exitCode);
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('page-de', $display);
        $this->assertStringNotContainsString('category-de', $display);
    }

    public function testFailsWhenTheFilteredAliasMatchesNoManagedScope(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], [], false);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'unknown-alias']);

        // Assert
        $this->assertSame(SearchIndexAliasStatusConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "unknown-alias".', $commandTester->getDisplay());
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())->setAliasName('page-de')->setSourceIdentifier('page')->setStoreName('DE');
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     * @param array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer> $latestRollouts
     */
    protected function createCommandTester(array $managedScopes, array $latestRollouts, bool $needsAdoption): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'getLatestRolloutPerScope', 'needsAdoption'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('getLatestRolloutPerScope')->willReturn($latestRollouts);
        $facadeMock->method('needsAdoption')->willReturn($needsAdoption);

        $console = new SearchIndexAliasStatusConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasStatusConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
