<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexMappingDiffTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildConsole;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasRebuildConsoleTest
 * @group Portable
 */
class SearchIndexAliasRebuildConsoleTest extends Unit
{
    public function testFailsWhenNoManagedScopeMatchesTheAlias(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('No managed scope found for alias "page-de".', $commandTester->getDisplay());
    }

    public function testFailsWhenTheMappingFileIsUnreadable(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester([$this->createScope()]);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de', '--mapping-file' => '/nonexistent/mapping.json']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Could not read mapping file "/nonexistent/mapping.json".', $commandTester->getDisplay());
    }

    public function testFailsWhenTheMappingFileIsNotAPropertiesObject(): void
    {
        // Arrange
        $path = tempnam(sys_get_temp_dir(), 'sia-mapping-');
        file_put_contents($path, json_encode(['not-properties' => true]));
        $commandTester = $this->createCommandTester([$this->createScope()]);

        try {
            // Act
            $exitCode = $commandTester->execute(['alias' => 'page-de', '--mapping-file' => $path]);

            // Assert
            $this->assertSame(SearchIndexAliasRebuildConsole::CODE_ERROR, $exitCode);
            $this->assertStringContainsString('must be a JSON object of the form {"properties": {...}}', $commandTester->getDisplay());
        } finally {
            unlink($path);
        }
    }

    public function testStartsTheRebuildWithoutAMappingFile(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())
            ->setIdSearchIndexRollout(9)
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING)
            ->setTargetIndexName('page-de-2')
            ->setActualDocumentCount(0);
        $commandTester = $this->createCommandTester([$this->createScope()], startResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Rollout 9 for "page-de" is now building (target=page-de-2, docs=0).', $commandTester->getDisplay());
    }

    public function testReportsAReadyRolloutWithAFlipHint(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())
            ->setIdSearchIndexRollout(9)
            ->setStatus(SearchIndexAliasConfig::STATUS_READY)
            ->setTargetIndexName('page-de-2')
            ->setActualDocumentCount(500);
        $commandTester = $this->createCommandTester([$this->createScope()], startResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Run `search-index-alias:flip page-de` once you\'re ready', $commandTester->getDisplay());
    }

    public function testWarnsAboutBreakingMappingFields(): void
    {
        // Arrange
        $mappingDiff = (new SearchIndexMappingDiffTransfer())->setClassification(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING)->setBreakingFields(['sku']);
        $rollout = (new SearchIndexRolloutTransfer())
            ->setIdSearchIndexRollout(9)
            ->setStatus(SearchIndexAliasConfig::STATUS_READY)
            ->setTargetIndexName('page-de-2')
            ->setActualDocumentCount(500)
            ->setSearchIndexMappingDiff($mappingDiff);
        $commandTester = $this->createCommandTester([$this->createScope()], startResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Mapping diff includes breaking field(s): sku', $commandTester->getDisplay());
    }

    public function testFailsWhenTheRolloutComesBackFailed(): void
    {
        // Arrange
        $rollout = (new SearchIndexRolloutTransfer())
            ->setIdSearchIndexRollout(9)
            ->setStatus(SearchIndexAliasConfig::STATUS_FAILED)
            ->setFailureReason('bulk load timed out');
        $commandTester = $this->createCommandTester([$this->createScope()], startResult: $rollout);

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_ERROR, $exitCode);
        $this->assertStringContainsString('Rollout failed: bulk load timed out', $commandTester->getDisplay());
    }

    public function testFailsWhenAnotherRolloutIsAlreadyInFlight(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester(
            [$this->createScope()],
            startException: new ConcurrentRolloutException('a rollout is already active for this scope'),
        );

        // Act
        $exitCode = $commandTester->execute(['alias' => 'page-de']);

        // Assert
        $this->assertSame(SearchIndexAliasRebuildConsole::CODE_ERROR, $exitCode);
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
        ?SearchIndexRolloutTransfer $startResult = null,
        ?ConcurrentRolloutException $startException = null,
    ): CommandTester {
        $facadeMock = $this->getMockBuilder(SearchIndexAliasFacade::class)
            ->onlyMethods(['getManagedScopes', 'startRebuild'])
            ->getMock();
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);

        if ($startException !== null) {
            $facadeMock->method('startRebuild')->willThrowException($startException);
        } elseif ($startResult !== null) {
            $facadeMock->method('startRebuild')->willReturn($startResult);
        }

        $console = new SearchIndexAliasRebuildConsole();
        $console->setFacade($facadeMock);

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchIndexAliasRebuildConsole::COMMAND_NAME);

        return new CommandTester($command);
    }
}
