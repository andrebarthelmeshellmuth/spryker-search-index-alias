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
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRollbackConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasRollbackConsoleTest
 * @group Portable
 */
class SearchIndexAliasRollbackConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasRollbackConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testRollsBackToTheTargetIndex(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED);
        $commandTester = $this->createCommandTester([$this->createScope()], rollbackResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasRollbackConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" rolled back to "page-de-1".', $commandTester->getDisplay());
    }

    public function testFailsWhenTheRollbackComesBackFailed(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())->setStatus(SearchIndexAliasConfig::STATUS_FAILED)->setFailureReason('target index vanished');
        $commandTester = $this->createCommandTester([$this->createScope()], rollbackResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasRollbackConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Rollback failed: target index vanished', $commandTester->getDisplay());
    }

    public function testFailsWhenAnotherRolloutIsAlreadyInFlight(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            [$this->createScope()],
            rollbackException: new ConcurrentRolloutException('a rollout is already active for this scope'),
        );

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasRollbackConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('a rollout is already active for this scope', $commandTester->getDisplay());
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
        ?SearchIndexRolloutTransfer $rollbackResult = null,
        ?ConcurrentRolloutException $rollbackException = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'rollbackToIndex'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);

        if ($rollbackException !== null) {
            $facadeMock->method('rollbackToIndex')->willThrowException($rollbackException);
        } elseif ($rollbackResult !== null) {
            $facadeMock->method('rollbackToIndex')->willReturn($rollbackResult);
        }

        $console = new SearchIndexAliasRollbackConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasRollbackConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
