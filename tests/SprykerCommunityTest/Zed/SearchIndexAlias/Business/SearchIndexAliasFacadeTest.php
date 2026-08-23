<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexHealthCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexHealthTransfer;
use Generated\Shared\Transfer\SearchIndexPhysicalIndexCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexAdopterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\DeployFlipRunnerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Health\SearchIndexHealthCheckerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\ScopeIndexOverviewInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexDeleterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexPrunerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestConsumerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollbackInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasBusinessFactory;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * Every one of this Facade's methods is a pure one-hop delegation, either to a factory-built collaborator
 * or straight to the repository -- this test's only job is asserting each hop happens with the right
 * arguments and returns the collaborator's result unchanged. The real logic behind each collaborator is
 * covered by that collaborator's own dedicated test.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group SearchIndexAliasFacadeTest
 * @group Portable
 */
class SearchIndexAliasFacadeTest extends Unit
{
    public function testGetManagedScopesDelegatesToTheIndexEnumerator(): void
    {
        // Arrange
        $scopes = [$this->createScope()];
        $enumeratorMock = $this->createConfiguredMock(IndexEnumeratorInterface::class, [
            'enumerateScopes' => $scopes,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createIndexEnumerator'], $enumeratorMock));

        // Act & Assert
        $this->assertSame($scopes, $facade->getManagedScopes());
    }

    public function testAdoptConcreteIndexDelegatesToTheAliasManager(): void
    {
        // Arrange
        $aliasManagerMock = $this->getMockBuilder(AliasManagerInterface::class)->getMock();
        $aliasManagerMock->expects($this->once())->method('adoptConcreteIndex')->with('page-de', 'page-de-1');
        $facade = $this->createFacade($this->createFactoryMock(['createAliasManager'], $aliasManagerMock));

        // Act
        $facade->adoptConcreteIndex($this->createScope(), 'page-de-1');
    }

    public function testSwitchAliasDelegatesToTheAliasManager(): void
    {
        // Arrange
        $aliasManagerMock = $this->getMockBuilder(AliasManagerInterface::class)->getMock();
        $aliasManagerMock->expects($this->once())->method('switchAlias')->with('page-de', 'page-de-1', 'page-de-2');
        $facade = $this->createFacade($this->createFactoryMock(['createAliasManager'], $aliasManagerMock));

        // Act
        $facade->switchAlias($this->createScope(), 'page-de-1', 'page-de-2');
    }

    public function testBuildTargetIndexNameDelegatesToTheIndexNameBuilder(): void
    {
        // Arrange
        $builderMock = $this->createConfiguredMock(IndexNameBuilderInterface::class, [
            'buildTargetIndexName' => 'page-de-20260821',
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createIndexNameBuilder'], $builderMock));

        // Act & Assert
        $this->assertSame('page-de-20260821', $facade->buildTargetIndexName($this->createScope()));
    }

    public function testAdoptDelegatesToTheIndexAdopter(): void
    {
        // Arrange
        $adopterMock = $this->createConfiguredMock(IndexAdopterInterface::class, [
            'adopt' => 'page-de-1',
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createIndexAdopter'], $adopterMock));

        // Act & Assert
        $this->assertSame('page-de-1', $facade->adopt($this->createScope()));
    }

    public function testNeedsAdoptionDelegatesToTheIndexAdopter(): void
    {
        // Arrange
        $adopterMock = $this->createConfiguredMock(IndexAdopterInterface::class, [
            'needsAdoption' => true,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createIndexAdopter'], $adopterMock));

        // Act & Assert
        $this->assertTrue($facade->needsAdoption($this->createScope()));
    }

    public function testGetActiveRolloutDelegatesToTheRepository(): void
    {
        // Arrange
        $rollout = new SearchIndexRolloutTransfer();
        $repositoryMock = $this->getMockBuilder(SearchIndexAliasRepository::class)->disableOriginalConstructor()->getMock();
        $repositoryMock->method('findActiveRolloutForScope')->with('page', 'DE')->willReturn($rollout);

        $facade = new SearchIndexAliasFacade();
        $facade->setRepository($repositoryMock);

        // Act & Assert
        $this->assertSame($rollout, $facade->getActiveRollout($this->createScope()));
    }

    public function testGetRolloutHistoryDelegatesToTheRepository(): void
    {
        // Arrange
        $history = [new SearchIndexRolloutTransfer()];
        $repositoryMock = $this->getMockBuilder(SearchIndexAliasRepository::class)->disableOriginalConstructor()->getMock();
        $repositoryMock->method('getRolloutHistoryForScope')->with('page', 'DE', 5)->willReturn($history);

        $facade = new SearchIndexAliasFacade();
        $facade->setRepository($repositoryMock);

        // Act & Assert
        $this->assertSame($history, $facade->getRolloutHistory($this->createScope(), 5));
    }

    public function testGetLatestRolloutPerScopeDelegatesToTheRepository(): void
    {
        // Arrange
        $latest = [new SearchIndexRolloutTransfer()];
        $repositoryMock = $this->getMockBuilder(SearchIndexAliasRepository::class)->disableOriginalConstructor()->getMock();
        $repositoryMock->method('getLatestRolloutPerScope')->willReturn($latest);

        $facade = new SearchIndexAliasFacade();
        $facade->setRepository($repositoryMock);

        // Act & Assert
        $this->assertSame($latest, $facade->getLatestRolloutPerScope());
    }

    public function testStartRebuildDelegatesToTheRebuildOrchestrator(): void
    {
        // Arrange
        $rollout = new SearchIndexRolloutTransfer();
        $orchestratorMock = $this->getMockBuilder(RebuildOrchestratorInterface::class)->getMock();
        $orchestratorMock->expects($this->once())->method('start')
            ->with($this->createScope(), 'andre', ['properties' => []], true)
            ->willReturn($rollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildOrchestrator'], $orchestratorMock));

        // Act & Assert
        $this->assertSame($rollout, $facade->startRebuild($this->createScope(), 'andre', ['properties' => []], true));
    }

    public function testStartRebuildDelegatesTheFromSchemaFlagToTheRebuildOrchestrator(): void
    {
        // Arrange
        $rollout = new SearchIndexRolloutTransfer();
        $orchestratorMock = $this->getMockBuilder(RebuildOrchestratorInterface::class)->getMock();
        $orchestratorMock->expects($this->once())->method('start')
            ->with($this->createScope(), 'andre', null, false, true)
            ->willReturn($rollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildOrchestrator'], $orchestratorMock));

        // Act & Assert
        $this->assertSame($rollout, $facade->startRebuild($this->createScope(), 'andre', null, false, true));
    }

    public function testRequestRebuildAsyncDelegatesToTheRebuildOrchestrator(): void
    {
        // Arrange
        $rollout = new SearchIndexRolloutTransfer();
        $orchestratorMock = $this->getMockBuilder(RebuildOrchestratorInterface::class)->getMock();
        $orchestratorMock->expects($this->once())->method('requestRebuildAsync')
            ->with($this->createScope(), 'andre', null, false)
            ->willReturn($rollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildOrchestrator'], $orchestratorMock));

        // Act & Assert
        $this->assertSame($rollout, $facade->requestRebuildAsync($this->createScope(), 'andre'));
    }

    public function testConsumeOneRebuildRequestDelegatesToTheRebuildRequestConsumer(): void
    {
        // Arrange
        $consumerMock = $this->createConfiguredMock(RebuildRequestConsumerInterface::class, [
            'consumeOne' => true,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildRequestConsumer'], $consumerMock));

        // Act & Assert
        $this->assertTrue($facade->consumeOneRebuildRequest());
    }

    public function testFlipRolloutDelegatesToTheRebuildOrchestrator(): void
    {
        // Arrange
        $inputRollout = new SearchIndexRolloutTransfer();
        $flippedRollout = new SearchIndexRolloutTransfer();
        $orchestratorMock = $this->getMockBuilder(RebuildOrchestratorInterface::class)->getMock();
        $orchestratorMock->expects($this->once())->method('flip')->with($inputRollout)->willReturn($flippedRollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildOrchestrator'], $orchestratorMock));

        // Act & Assert
        $this->assertSame($flippedRollout, $facade->flipRollout($inputRollout));
    }

    public function testMarkFlipPendingDelegatesToTheRolloutFinisher(): void
    {
        // Arrange
        $inputRollout = new SearchIndexRolloutTransfer();
        $markedRollout = new SearchIndexRolloutTransfer();
        $finisherMock = $this->getMockBuilder(RolloutFinisherInterface::class)->getMock();
        $finisherMock->expects($this->once())->method('markFlipPending')->with($inputRollout)->willReturn($markedRollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRolloutFinisher'], $finisherMock));

        // Act & Assert
        $this->assertSame($markedRollout, $facade->markFlipPending($inputRollout));
    }

    public function testUnmarkFlipPendingDelegatesToTheRolloutFinisher(): void
    {
        // Arrange
        $inputRollout = new SearchIndexRolloutTransfer();
        $unmarkedRollout = new SearchIndexRolloutTransfer();
        $finisherMock = $this->getMockBuilder(RolloutFinisherInterface::class)->getMock();
        $finisherMock->expects($this->once())->method('unmarkFlipPending')->with($inputRollout)->willReturn($unmarkedRollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRolloutFinisher'], $finisherMock));

        // Act & Assert
        $this->assertSame($unmarkedRollout, $facade->unmarkFlipPending($inputRollout));
    }

    public function testFindPendingFlipCandidatesDelegatesToTheDeployFlipRunner(): void
    {
        // Arrange
        $candidates = [new SearchIndexRolloutTransfer()];
        $runnerMock = $this->createConfiguredMock(DeployFlipRunnerInterface::class, [
            'findPendingFlipCandidates' => $candidates,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createDeployFlipRunner'], $runnerMock));

        // Act & Assert
        $this->assertSame($candidates, $facade->findPendingFlipCandidates());
    }

    public function testDeployFlipPendingDelegatesToTheDeployFlipRunner(): void
    {
        // Arrange
        $flipped = [new SearchIndexRolloutTransfer()];
        $runnerMock = $this->createConfiguredMock(DeployFlipRunnerInterface::class, [
            'flipAllPending' => $flipped,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createDeployFlipRunner'], $runnerMock));

        // Act & Assert
        $this->assertSame($flipped, $facade->deployFlipPending());
    }

    public function testMarkPendingRollbackDelegatesToThePendingRollbackTargetManager(): void
    {
        // Arrange
        $managerMock = $this->getMockBuilder(PendingRollbackTargetManagerInterface::class)->getMock();
        $managerMock->expects($this->once())->method('mark')->with($this->createScope(), 'page-de-1', 'andre');
        $facade = $this->createFacade($this->createFactoryMock(['createPendingRollbackTargetManager'], $managerMock));

        // Act
        $facade->markPendingRollback($this->createScope(), 'page-de-1', 'andre');
    }

    public function testUnmarkPendingRollbackDelegatesToThePendingRollbackTargetManager(): void
    {
        // Arrange
        $managerMock = $this->getMockBuilder(PendingRollbackTargetManagerInterface::class)->getMock();
        $managerMock->expects($this->once())->method('unmark')->with($this->createScope());
        $facade = $this->createFacade($this->createFactoryMock(['createPendingRollbackTargetManager'], $managerMock));

        // Act
        $facade->unmarkPendingRollback($this->createScope());
    }

    public function testFindPendingRollbackTargetDelegatesToThePendingRollbackTargetManager(): void
    {
        // Arrange
        $managerMock = $this->createConfiguredMock(PendingRollbackTargetManagerInterface::class, [
            'findFor' => 'page-de-1',
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createPendingRollbackTargetManager'], $managerMock));

        // Act & Assert
        $this->assertSame('page-de-1', $facade->findPendingRollbackTarget($this->createScope()));
    }

    public function testAbortRolloutDelegatesToTheRebuildOrchestrator(): void
    {
        // Arrange
        $inputRollout = new SearchIndexRolloutTransfer();
        $abortedRollout = new SearchIndexRolloutTransfer();
        $orchestratorMock = $this->getMockBuilder(RebuildOrchestratorInterface::class)->getMock();
        $orchestratorMock->expects($this->once())->method('abort')->with($inputRollout, 'no longer needed')->willReturn($abortedRollout);
        $facade = $this->createFacade($this->createFactoryMock(['createRebuildOrchestrator'], $orchestratorMock));

        // Act & Assert
        $this->assertSame($abortedRollout, $facade->abortRollout($inputRollout, 'no longer needed'));
    }

    public function testPruneScopeDelegatesToTheIndexPruner(): void
    {
        // Arrange
        $prunerMock = $this->createConfiguredMock(IndexPrunerInterface::class, [
            'pruneScope' => ['page-de-1'],
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createIndexPruner'], $prunerMock));

        // Act & Assert
        $this->assertSame(['page-de-1'], $facade->pruneScope($this->createScope()));
    }

    public function testCheckScopeHealthDelegatesToTheSearchIndexHealthChecker(): void
    {
        // Arrange
        $health = new SearchIndexHealthTransfer();
        $checkerMock = $this->createConfiguredMock(SearchIndexHealthCheckerInterface::class, [
            'checkScope' => $health,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createSearchIndexHealthChecker'], $checkerMock));

        // Act & Assert
        $this->assertSame($health, $facade->checkScopeHealth($this->createScope()));
    }

    public function testCheckAllManagedScopesHealthDelegatesToTheSearchIndexHealthChecker(): void
    {
        // Arrange
        $collection = new SearchIndexHealthCollectionTransfer();
        $checkerMock = $this->createConfiguredMock(SearchIndexHealthCheckerInterface::class, [
            'checkAllManagedScopes' => $collection,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createSearchIndexHealthChecker'], $checkerMock));

        // Act & Assert
        $this->assertSame($collection, $facade->checkAllManagedScopesHealth());
    }

    public function testGetIndicesForScopeDelegatesToTheScopeIndexOverview(): void
    {
        // Arrange
        $collection = new SearchIndexPhysicalIndexCollectionTransfer();
        $overviewMock = $this->createConfiguredMock(ScopeIndexOverviewInterface::class, [
            'getIndicesForScope' => $collection,
        ]);
        $facade = $this->createFacade($this->createFactoryMock(['createScopeIndexOverview'], $overviewMock));

        // Act & Assert
        $this->assertSame($collection, $facade->getIndicesForScope($this->createScope()));
    }

    public function testRollbackToIndexDelegatesToTheAliasRollback(): void
    {
        // Arrange
        $rollout = new SearchIndexRolloutTransfer();
        $rollbackMock = $this->getMockBuilder(AliasRollbackInterface::class)->getMock();
        $rollbackMock->expects($this->once())->method('rollbackToIndex')->with($this->createScope(), 'page-de-1', 'andre')->willReturn($rollout);
        $facade = $this->createFacade($this->createFactoryMock(['createAliasRollback'], $rollbackMock));

        // Act & Assert
        $this->assertSame($rollout, $facade->rollbackToIndex($this->createScope(), 'page-de-1', 'andre'));
    }

    public function testDeleteIndexDelegatesToTheIndexDeleter(): void
    {
        // Arrange
        $deleterMock = $this->getMockBuilder(IndexDeleterInterface::class)->getMock();
        $deleterMock->expects($this->once())->method('deleteIndex')->with($this->createScope(), 'page-de-1', 'andre');
        $facade = $this->createFacade($this->createFactoryMock(['createIndexDeleter'], $deleterMock));

        // Act
        $facade->deleteIndex($this->createScope(), 'page-de-1', 'andre');
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())->setAliasName('page-de')->setSourceIdentifier('page')->setStoreName('DE');
    }

    protected function createFacade(SearchIndexAliasBusinessFactory $factoryMock): SearchIndexAliasFacade
    {
        $facade = new SearchIndexAliasFacade();
        $facade->setFactory($factoryMock);

        return $facade;
    }

    /**
     * @param array<string> $onlyMethods
     */
    protected function createFactoryMock(array $onlyMethods, mixed $returnValue): SearchIndexAliasBusinessFactory
    {
        $factoryMock = $this->getMockBuilder(SearchIndexAliasBusinessFactory::class)
            ->onlyMethods($onlyMethods)
            ->getMock();
        $factoryMock->method($onlyMethods[0])->willReturn($returnValue);

        return $factoryMock;
    }
}
