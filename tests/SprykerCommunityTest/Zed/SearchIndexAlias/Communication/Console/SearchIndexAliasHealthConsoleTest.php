<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use ArrayObject;
use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexHealthCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexHealthTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasHealthConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasHealthConsoleTest
 * @group Portable
 */
class SearchIndexAliasHealthConsoleTest extends Unit
{
    public function testFailsWhenTheFilteredAliasHasNoManagedScope(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasHealthConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testReportsHealthyForTheFilteredAlias(): void
    {
        // Arrange
        $health = (new SearchIndexHealthTransfer())->setSearchIndexScope($this->createScope())->setIsHealthy(true)->setAliasedIndexNames(['page-de-2'])->setDocumentCount(100)->setIssues([]);
        $commandTester = $this->createCommandTester([$this->createScope()], scopeHealth: $health);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasHealthConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('All checked scopes are healthy.', $commandTester->getDisplay());
    }

    public function testChecksEveryManagedScopeWithoutAFilter(): void
    {
        // Arrange
        $health = (new SearchIndexHealthTransfer())->setSearchIndexScope($this->createScope())->setIsHealthy(true)->setAliasedIndexNames(['page-de-2'])->setDocumentCount(100)->setIssues([]);
        $collection = (new SearchIndexHealthCollectionTransfer())->setSearchIndexHealths(new ArrayObject([$health]));
        $commandTester = $this->createCommandTester([$this->createScope()], allHealth: $collection);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasHealthConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('page-de', $commandTester->getDisplay());
    }

    public function testFailsWhenAScopeIsUnhealthy(): void
    {
        // Arrange
        $health = (new SearchIndexHealthTransfer())->setSearchIndexScope($this->createScope())->setIsHealthy(false)->setAliasedIndexNames([])->setDocumentCount(0)->setIssues(['alias does not exist']);
        $collection = (new SearchIndexHealthCollectionTransfer())->setSearchIndexHealths(new ArrayObject([$health]));
        $commandTester = $this->createCommandTester([$this->createScope()], allHealth: $collection);

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchIndexAliasHealthConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('1 scope(s) have an issue.', $commandTester->getDisplay());
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
        ?SearchIndexHealthTransfer $scopeHealth = null,
        ?SearchIndexHealthCollectionTransfer $allHealth = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'checkScopeHealth', 'checkAllManagedScopesHealth'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);

        if ($scopeHealth !== null) {
            $facadeMock->method('checkScopeHealth')->willReturn($scopeHealth);
        }

        if ($allHealth !== null) {
            $facadeMock->method('checkAllManagedScopesHealth')->willReturn($allHealth);
        }

        $console = new SearchIndexAliasHealthConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasHealthConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
