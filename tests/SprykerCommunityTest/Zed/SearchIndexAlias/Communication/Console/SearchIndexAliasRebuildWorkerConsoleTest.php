<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildWorkerConsole;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Only exercises `--stop-when-empty`: the default polling loop sleeps between empty checks and never
 * returns on its own, which would make a test either hang or need to mock `sleep()` itself.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasRebuildWorkerConsoleTest
 * @group Portable
 */
class SearchIndexAliasRebuildWorkerConsoleTest extends Unit
{
    public function testStopsImmediatelyWhenTheQueueIsAlreadyEmpty(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([false]);

        // Act
        $exitCode = $commandTester->execute(['--stop-when-empty' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildWorkerConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('search-index-alias rebuild worker started.', $commandTester->getDisplay());
        $this->assertStringNotContainsString('Processed a queued rebuild request.', $commandTester->getDisplay());
        $this->assertStringContainsString('search-index-alias rebuild worker stopped.', $commandTester->getDisplay());
    }

    public function testProcessesEveryQueuedRequestThenStops(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([true, true, false]);

        // Act
        $exitCode = $commandTester->execute(['--stop-when-empty' => true]);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildWorkerConsole::CODE_SUCCESS, $exitCode);
        $this->assertSame(2, substr_count($commandTester->getDisplay(), 'Processed a queued rebuild request.'));
    }

    /**
     * @param array<bool> $consumeResults
     */
    protected function createCommandTester(array $consumeResults): CommandTester
    {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['consumeOneRebuildRequest'])
            ->getMock();
        $facadeMock->method('consumeOneRebuildRequest')->willReturnOnConsecutiveCalls(...$consumeResults);

        $console = new SearchIndexAliasRebuildWorkerConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasRebuildWorkerConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
