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
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasFlipConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasFlipConsoleTest
 * @group Portable
 */
class SearchIndexAliasFlipConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasFlipConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testFailsWhenThereIsNoActiveRollout(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], null);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasFlipConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('start one with search-index-alias:rebuild first', $commandTester->getDisplay());
    }

    public function testFailsWhenTheActiveRolloutIsNotReady(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(3)->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);
        $commandTester = $this->createCommandTester([$this->createScope()], $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasFlipConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('is not ready to flip (status=building)', $commandTester->getDisplay());
    }

    public function testFlipsTheReadyRollout(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(3)->setStatus(SearchIndexAliasConfig::STATUS_READY);
        $flippedRollout = (new SearchIndexRolloutTransfer())->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED)->setTargetIndexName('page-de-2');
        $commandTester = $this->createCommandTester([$this->createScope()], $activeRollout, $flippedRollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasFlipConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" now points at "page-de-2".', $commandTester->getDisplay());
    }

    public function testFailsWhenTheFlipItselfFails(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setIdSearchIndexRollout(3)->setStatus(SearchIndexAliasConfig::STATUS_READY);
        $failedRollout = (new SearchIndexRolloutTransfer())->setStatus(SearchIndexAliasConfig::STATUS_FAILED)->setFailureReason('alias swap rejected');
        $commandTester = $this->createCommandTester([$this->createScope()], $activeRollout, $failedRollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasFlipConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Flip failed: alias swap rejected', $commandTester->getDisplay());
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
        ?SearchIndexRolloutTransfer $flippedRollout = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'getActiveRollout', 'flipRollout'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('getActiveRollout')->willReturn($activeRollout);

        if ($flippedRollout !== null) {
            $facadeMock->method('flipRollout')->willReturn($flippedRollout);
        }

        $console = new SearchIndexAliasFlipConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasFlipConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
