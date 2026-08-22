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
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasMarkFlipPendingConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasMarkFlipPendingConsoleTest
 * @group Portable
 */
class SearchIndexAliasMarkFlipPendingConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkFlipPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testFailsWhenThereIsNoActiveRollout(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], null);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkFlipPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No active rollout for "page-de".', $commandTester->getDisplay());
    }

    public function testMarksTheRolloutFlipPending(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setTargetIndexName('page-de-2');
        $markedRollout = (new SearchIndexRolloutTransfer())->setTargetIndexName('page-de-2')->setFlipPending(true);
        $commandTester = $this->createCommandTester([$this->createScope()], $activeRollout, markResult: $markedRollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkFlipPendingConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" (target page-de-2) is now flip-pending.', $commandTester->getDisplay());
    }

    public function testUnmarksTheRolloutWithOff(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setTargetIndexName('page-de-2');
        $unmarkedRollout = (new SearchIndexRolloutTransfer())->setTargetIndexName('page-de-2')->setFlipPending(false);
        $commandTester = $this->createCommandTester([$this->createScope()], $activeRollout, unmarkResult: $unmarkedRollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', '--off' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasMarkFlipPendingConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" (target page-de-2) is no longer flip-pending.', $commandTester->getDisplay());
    }

    public function testFailsWhenTheRolloutIsNotReady(): void
    {
        // Arrange
        $activeRollout = (new SearchIndexRolloutTransfer())->setTargetIndexName('page-de-2');
        $commandTester = $this->createCommandTester(
            [$this->createScope()],
            $activeRollout,
            markException: new RolloutNotReadyException('rollout is not READY'),
        );

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkFlipPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('rollout is not READY', $commandTester->getDisplay());
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
        ?SearchIndexRolloutTransfer $markResult = null,
        ?SearchIndexRolloutTransfer $unmarkResult = null,
        ?RolloutNotReadyException $markException = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'getActiveRollout', 'markFlipPending', 'unmarkFlipPending'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('getActiveRollout')->willReturn($activeRollout);

        if ($markException !== null) {
            $facadeMock->method('markFlipPending')->willThrowException($markException);
        } elseif ($markResult !== null) {
            $facadeMock->method('markFlipPending')->willReturn($markResult);
        }

        if ($unmarkResult !== null) {
            $facadeMock->method('unmarkFlipPending')->willReturn($unmarkResult);
        }

        $console = new SearchIndexAliasMarkFlipPendingConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasMarkFlipPendingConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
