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
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasDeployFlipConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasDeployFlipConsoleTest
 * @group Portable
 */
class SearchIndexAliasDeployFlipConsoleTest extends Unit
{
    public function testDryRunReportsNothingToDoWhenNoScopeIsPending(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(candidates: []);

        // Act
        $exitCode = $commandTester->execute(['--dry-run' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasDeployFlipConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('a deploy right now would flip nothing', $commandTester->getDisplay());
    }

    public function testDryRunListsThePendingCandidates(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setSearchIndexScope($this->createScope())->setTargetIndexName('page-de-2')->setActualDocumentCount(42);
        $commandTester = $this->createCommandTester(candidates: [$rollout]);

        // Act
        $exitCode = $commandTester->execute(['--dry-run' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasDeployFlipConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('1 scope(s) would flip on the next deploy.', $commandTester->getDisplay());
    }

    public function testReportsNothingToDoWhenNoScopeIsFlipPending(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(deployed: []);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasDeployFlipConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('No scope is flagged flip-pending -- nothing to do.', $commandTester->getDisplay());
    }

    public function testFlipsEveryPendingScope(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setSearchIndexScope($this->createScope())->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED)->setTargetIndexName('page-de-2');
        $commandTester = $this->createCommandTester(deployed: [$rollout]);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasDeployFlipConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('1 scope(s) flipped.', $commandTester->getDisplay());
    }

    public function testFailsWhenAFlipFailed(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setSearchIndexScope($this->createScope())->setStatus(SearchIndexAliasConfig::STATUS_FAILED)->setFailureReason('cluster unreachable');
        $commandTester = $this->createCommandTester(deployed: [$rollout]);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasDeployFlipConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('1 of 1 flip(s) failed.', $commandTester->getDisplay());
        $this->assertStringContainsString('cluster unreachable', $commandTester->getDisplay());
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())->setAliasName('page-de')->setSourceIdentifier('page')->setStoreName('DE');
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>|null $candidates
     * @param array<\Generated\Shared\Transfer\SearchIndexRolloutTransfer>|null $deployed
     */
    protected function createCommandTester(?array $candidates = null, ?array $deployed = null): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['findPendingFlipCandidates', 'deployFlipPending'])
            ->getMock();

        if ($candidates !== null) {
            $facadeMock->method('findPendingFlipCandidates')->willReturn($candidates);
        }

        if ($deployed !== null) {
            $facadeMock->method('deployFlipPending')->willReturn($deployed);
        }

        $console = new SearchIndexAliasDeployFlipConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasDeployFlipConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
