<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAdoptConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasAdoptConsoleTest
 * @group Portable
 */
class SearchIndexAliasAdoptConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([], true);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAdoptConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testSucceedsWithoutAdoptingWhenAlreadyAnAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], false);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAdoptConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" is already an alias -- nothing to adopt.', $commandTester->getDisplay());
    }

    public function testAdoptsTheConcreteIndex(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], true, 'page-de-20260821120000');

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAdoptConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('"page-de" is now an alias pointing at "page-de-20260821120000".', $commandTester->getDisplay());
    }

    public function testFailsWhenAdoptionIsNotApplicable(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()], true, null, new AdoptionNotApplicableException('neither an alias nor a concrete index exists'));

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasAdoptConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('neither an alias nor a concrete index exists', $commandTester->getDisplay());
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
        bool $needsAdoption,
        ?string $adoptedIndexName = null,
        ?AdoptionNotApplicableException $adoptionNotApplicableException = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'needsAdoption', 'adopt'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);
        $facadeMock->method('needsAdoption')->willReturn($needsAdoption);

        if ($adoptionNotApplicableException !== null) {
            $facadeMock->method('adopt')->willThrowException($adoptionNotApplicableException);
        } elseif ($adoptedIndexName !== null) {
            $facadeMock->method('adopt')->willReturn($adoptedIndexName);
        }

        $console = new SearchIndexAliasAdoptConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasAdoptConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
