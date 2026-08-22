<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use RuntimeException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoaderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifierInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrainInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface;
use Throwable;

class RebuildOrchestrator implements RebuildOrchestratorInterface
{
    /**
     * Bounded mirror-queue drain passes during BUILDING -- each pass drains whatever is currently in
     * the queue and applies whatever of it belongs to this scope; a pass that applies 0 messages for
     * THIS scope means convergence has (very likely) been reached -- MirrorQueueDrain::drain() already
     * excludes other stores' traffic from that count (see its own docblock), so a busy multi-store shop
     * publishing to other scopes doesn't keep this loop spinning. Bounded the same way IndexAdopter's
     * catch-up loop is, for the same reason: a source producing writes faster than this can drain must
     * fail loudly instead of looping forever.
     *
     * @var int
     */
    protected const MAX_DRAIN_PASSES = 20;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarterInterface $rolloutStarter
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface $rolloutFinisher
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface $indexNameBuilder
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface $indexCloner
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifierInterface $mappingDiffClassifier
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoaderInterface $bulkLoader
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinderInterface $mirrorQueueBinder
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrainInterface $mirrorQueueDrain
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface $entityManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisherInterface $rebuildRequestPublisher
     * @param bool $isAutoFlipEnabled
     * @param array<\SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin\TargetIndexSettingsExpanderPluginInterface> $targetIndexSettingsExpanderPlugins
     */
    public function __construct(
        protected RolloutStarterInterface $rolloutStarter,
        protected RolloutFinisherInterface $rolloutFinisher,
        protected IndexNameBuilderInterface $indexNameBuilder,
        protected IndexClonerInterface $indexCloner,
        protected MappingDiffClassifierInterface $mappingDiffClassifier,
        protected BulkLoaderInterface $bulkLoader,
        protected MirrorQueueBinderInterface $mirrorQueueBinder,
        protected MirrorQueueDrainInterface $mirrorQueueDrain,
        protected AliasManagerInterface $aliasManager,
        protected SearchIndexAliasEntityManagerInterface $entityManager,
        protected RebuildRequestPublisherInterface $rebuildRequestPublisher,
        protected bool $isAutoFlipEnabled,
        protected array $targetIndexSettingsExpanderPlugins = [],
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function start(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer {
        $searchIndexRolloutTransfer = $this->rolloutStarter->start($searchIndexScopeTransfer, $triggeredByUser);

        if ($searchIndexRolloutTransfer->getLiveIndexName() === null) {
            return $this->failNoLiveIndex($searchIndexRolloutTransfer);
        }

        return $this->continueBuild($searchIndexRolloutTransfer, $searchIndexScopeTransfer, $targetMappingProperties, $optimizeForBulkLoad);
    }

    /**
     * The GUI-safe entry point: does only the fast part synchronously (the same `RolloutStarter` row
     * creation `start()` does -- a single INSERT, milliseconds) and publishes the actual work as a
     * request on `RebuildRequestPublisher`'s queue instead of running it inline. The caller (a Zed
     * Communication controller) gets a `building` rollout back immediately and can redirect without the
     * HTTP request ever blocking on the rebuild itself -- see the `search-index-alias:rebuild-worker`
     * console command that consumes this queue and actually performs the work via `executeQueuedRebuild()`.
     *
     * `start()` above is unaffected and stays fully synchronous -- appropriate for the console command,
     * which has no HTTP timeout to worry about and benefits from seeing the outcome immediately.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string|null $triggeredByUser
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function requestRebuildAsync(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?string $triggeredByUser = null,
        ?array $targetMappingProperties = null,
        bool $optimizeForBulkLoad = false,
    ): SearchIndexRolloutTransfer {
        $searchIndexRolloutTransfer = $this->rolloutStarter->start($searchIndexScopeTransfer, $triggeredByUser);

        if ($searchIndexRolloutTransfer->getLiveIndexName() === null) {
            return $this->failNoLiveIndex($searchIndexRolloutTransfer);
        }

        $this->rebuildRequestPublisher->publish($searchIndexRolloutTransfer, $searchIndexScopeTransfer, $targetMappingProperties, $optimizeForBulkLoad);

        return $searchIndexRolloutTransfer;
    }

    /**
     * The consumer side of `requestRebuildAsync()` -- called by `RebuildRequestConsumer` for a rollout
     * row that already exists (status `building`, created synchronously by the request that queued this).
     * Does NOT call `RolloutStarter` again; picks up exactly where `start()` would have continued.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    public function executeQueuedRebuild(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
    ): SearchIndexRolloutTransfer {
        if ($searchIndexRolloutTransfer->getLiveIndexName() === null) {
            return $this->failNoLiveIndex($searchIndexRolloutTransfer);
        }

        return $this->continueBuild($searchIndexRolloutTransfer, $searchIndexScopeTransfer, $targetMappingProperties, $optimizeForBulkLoad);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function flip(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $liveIndexName = $searchIndexRolloutTransfer->getLiveIndexNameOrFail();
        $targetIndexName = $searchIndexRolloutTransfer->getTargetIndexNameOrFail();
        $mirrorQueueName = $searchIndexRolloutTransfer->getMirrorQueueNameOrFail();

        try {
            // Final catch-up pass: anything published between the last BUILDING-phase drain and this
            // instant is still sitting in the mirror queue, since the source of truth was never the
            // live index -- this is what makes the flip below safe to make atomic and instant rather
            // than needing its own quiesce window.
            $this->mirrorQueueDrain->drain($mirrorQueueName, $targetIndexName, $searchIndexScopeTransfer->getStoreNameOrFail());

            $this->aliasManager->switchAlias($aliasName, $liveIndexName, $targetIndexName);
            $this->mirrorQueueBinder->unbind($mirrorQueueName);
        } catch (Throwable $throwable) {
            return $this->rolloutFinisher->markFailed($searchIndexRolloutTransfer, $throwable->getMessage());
        }

        return $this->rolloutFinisher->markFlipped($searchIndexRolloutTransfer);
    }

    /**
     * Leaves the target index in place -- the live index was never touched, so there's no urgency, and
     * the operator may want to inspect it or roll back to it later. It shows up as an ordinary
     * non-current row on the Overview table afterward, with its own Delete button, same as any other old
     * index -- deletion is a deliberate, separate action, not an automatic side effect of aborting.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $reason
     */
    public function abort(SearchIndexRolloutTransfer $searchIndexRolloutTransfer, string $reason): SearchIndexRolloutTransfer
    {
        $mirrorQueueName = $searchIndexRolloutTransfer->getMirrorQueueName();

        if ($mirrorQueueName !== null) {
            $this->mirrorQueueBinder->unbind($mirrorQueueName);
        }

        return $this->rolloutFinisher->markAborted($searchIndexRolloutTransfer, $reason);
    }

    /**
     * A scope with no live index yet is a first-adoption case, not a rebuild -- IndexAdopter (P2) is the
     * correct entry point there. Failing the rollout (rather than silently routing to adoption on the
     * caller's behalf) keeps every entry point's contract explicit: none of them operate on a scope
     * whose alias doesn't exist yet.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    protected function failNoLiveIndex(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): SearchIndexRolloutTransfer
    {
        return $this->rolloutFinisher->markFailed(
            $searchIndexRolloutTransfer,
            'Scope has no live index yet -- use adoption (IndexAdopter), not a rebuild, for first-time setup.',
        );
    }

    /**
     * The actual BUILDING work -- shared by the synchronous (`start()`) and asynchronous
     * (`executeQueuedRebuild()`) entry points, both of which have already confirmed a live index exists
     * before calling this.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param array<string, mixed>|null $targetMappingProperties
     * @param bool $optimizeForBulkLoad
     */
    protected function continueBuild(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        ?array $targetMappingProperties,
        bool $optimizeForBulkLoad,
    ): SearchIndexRolloutTransfer {
        $liveIndexName = $searchIndexRolloutTransfer->getLiveIndexNameOrFail();
        $targetIndexName = null;
        $bulkLoadSettingsToRestore = null;

        try {
            $targetIndexName = $this->buildTarget($searchIndexScopeTransfer, $searchIndexRolloutTransfer, $liveIndexName, $targetMappingProperties);
            $mirrorQueueName = $this->mirrorQueueBinder->bind($searchIndexRolloutTransfer);

            $searchIndexRolloutTransfer->setMirrorQueueName($mirrorQueueName);
            $searchIndexRolloutTransfer = $this->entityManager->updateRollout($searchIndexRolloutTransfer);

            if ($optimizeForBulkLoad) {
                $bulkLoadSettingsToRestore = $this->indexCloner->disableRefreshAndReplicasForBulkLoad($targetIndexName);
            }

            $expectedDocumentCount = $this->bulkLoader->load($searchIndexScopeTransfer, $targetIndexName);
            $searchIndexRolloutTransfer->setExpectedDocumentCount($expectedDocumentCount);
            $searchIndexRolloutTransfer = $this->entityManager->updateRollout($searchIndexRolloutTransfer);

            if ($bulkLoadSettingsToRestore !== null) {
                $this->indexCloner->restoreSettings($targetIndexName, $bulkLoadSettingsToRestore);
                $bulkLoadSettingsToRestore = null;
            }

            $this->drainUntilConverged($mirrorQueueName, $targetIndexName, $searchIndexScopeTransfer->getStoreNameOrFail());

            $actualDocumentCount = $this->indexCloner->getDocumentCount($targetIndexName);
            $searchIndexRolloutTransfer = $this->rolloutFinisher->markReady($searchIndexRolloutTransfer, $targetIndexName, $actualDocumentCount);
        } catch (Throwable $throwable) {
            if ($bulkLoadSettingsToRestore !== null && $targetIndexName !== null) {
                // Best-effort: a target abandoned mid-build with replicas/refresh still disabled is a
                // latent surprise for whatever handles it next (prune, a manual inspection). Restoring
                // is not itself allowed to mask the real failure below.
                try {
                    $this->indexCloner->restoreSettings($targetIndexName, $bulkLoadSettingsToRestore);
                } catch (Throwable) {
                }
            }

            return $this->rolloutFinisher->markFailed($searchIndexRolloutTransfer, $throwable->getMessage());
        }

        if ($this->isAutoFlipEnabled) {
            return $this->flip($searchIndexRolloutTransfer);
        }

        return $searchIndexRolloutTransfer;
    }

    /**
     * Builds the target index's shape: clone live's current mapping/settings -- running the settings
     * through this package's `TargetIndexSettingsExpanderPluginInterface` stack first (see that
     * interface's own doc block for why this is the only moment `analysis` can differ from live's) -- then
     * layer any caller-supplied mapping change on top (always safe -- the target has zero documents at
     * this point). Classifies the resulting diff against live purely for the rollout's own record and the
     * operator-facing pre-flight tooling (see MappingDiffClassifierInterface doc block) -- the
     * classification is never used to gate anything in this method itself.
     *
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param string $liveIndexName
     * @param array<string, mixed>|null $targetMappingProperties
     */
    protected function buildTarget(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        string $liveIndexName,
        ?array $targetMappingProperties,
    ): string {
        $targetIndexName = $this->indexNameBuilder->buildTargetIndexName($searchIndexScopeTransfer->getAliasNameOrFail());

        $liveMapping = $this->indexCloner->getMapping($liveIndexName);
        $targetSettings = $this->indexCloner->getFilteredSettings($liveIndexName);

        foreach ($this->targetIndexSettingsExpanderPlugins as $targetIndexSettingsExpanderPlugin) {
            $targetSettings = $targetIndexSettingsExpanderPlugin->expand($searchIndexScopeTransfer, $targetSettings);
        }

        $this->indexCloner->createIndexWithMappingAndSettings($targetIndexName, $liveMapping, $targetSettings);

        if ($targetMappingProperties !== null) {
            $this->indexCloner->applyMapping($targetIndexName, $targetMappingProperties);
        }

        $targetMapping = $this->indexCloner->getMapping($targetIndexName);
        $searchIndexMappingDiffTransfer = $this->mappingDiffClassifier->classify($liveMapping, $targetMapping);

        $searchIndexRolloutTransfer->setTargetIndexName($targetIndexName);
        $searchIndexRolloutTransfer->setSearchIndexMappingDiff($searchIndexMappingDiffTransfer);
        $this->entityManager->updateRollout($searchIndexRolloutTransfer);

        return $targetIndexName;
    }

    /**
     * @param string $mirrorQueueName
     * @param string $targetIndexName
     * @param string $storeName
     *
     * @throws \RuntimeException
     */
    protected function drainUntilConverged(string $mirrorQueueName, string $targetIndexName, string $storeName): void
    {
        for ($pass = 1; $pass <= static::MAX_DRAIN_PASSES; $pass++) {
            $drainedCount = $this->mirrorQueueDrain->drain($mirrorQueueName, $targetIndexName, $storeName);

            if ($drainedCount === 0) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'Mirror queue "%s" did not converge after %d drain passes -- the source is receiving writes ' .
            'faster than the drain can catch up. Retry the rebuild during a quieter window.',
            $mirrorQueueName,
            static::MAX_DRAIN_PASSES,
        ));
    }
}
