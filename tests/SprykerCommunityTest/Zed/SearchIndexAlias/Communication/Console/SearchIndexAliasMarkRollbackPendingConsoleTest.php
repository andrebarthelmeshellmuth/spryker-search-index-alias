<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasMarkRollbackPendingConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasMarkRollbackPendingConsoleTest
 * @group Portable
 */
class SearchIndexAliasMarkRollbackPendingConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkRollbackPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testUnmarksWithOff(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', '--off' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasMarkRollbackPendingConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" is no longer flagged for a deploy-time rollback.', $commandTester->getDisplay());
    }

    public function testFailsWhenNoTargetIndexIsGivenWithoutOff(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkRollbackPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('A target index is required unless --off is given.', $commandTester->getDisplay());
    }

    public function testMarksThePendingRollbackTarget(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkRollbackPendingConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" will roll back to "page-de-1" on the next deploy.', $commandTester->getDisplay());
    }

    public function testFailsWhenTheRollbackTargetIsNotApplicable(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            [$this->createScope()],
            markException: new RollbackTargetNotApplicableException('target index does not exist'),
        );

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', 'target-index' => 'page-de-1']);

        // Assert
        $this->assertSame(SearchIndexAliasMarkRollbackPendingConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('target index does not exist', $commandTester->getDisplay());
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
        ?RollbackTargetNotApplicableException $markException = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'markPendingRollback', 'unmarkPendingRollback'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);

        if ($markException !== null) {
            $facadeMock->method('markPendingRollback')->willThrowException($markException);
        }

        $console = new SearchIndexAliasMarkRollbackPendingConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasMarkRollbackPendingConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
