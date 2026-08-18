<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption;

interface IndexClonerInterface
{
    /**
     * Creates $targetIndexName as a fresh, empty index with exactly $sourceIndexName's current mapping
     * and settings -- used for pure adoption (no mapping change intended, see IndexAdopter) so the
     * clone is byte-identical in shape to what's already live, not a dynamically-inferred mapping.
     *
     * @param string $sourceIndexName
     * @param string $targetIndexName
     */
    public function cloneMappingAndSettings(string $sourceIndexName, string $targetIndexName): void;

    /**
     * Server-side document copy via Elasticsearch's own `_reindex` API -- no documents pass through
     * this process, unlike the bulk-load-from-database path a mapping-changing rebuild uses (see
     * BulkLoader in P4). Appropriate only when source and target already share a compatible mapping
     * (true by construction here, since cloneMappingAndSettings() built the target from the source's
     * own mapping).
     *
     * @param string $sourceIndexName
     * @param string $targetIndexName
     *
     * @return int Number of documents the server reports as created in the target.
     */
    public function reindexInto(string $sourceIndexName, string $targetIndexName): int;

    /**
     * @param string $indexName
     */
    public function getDocumentCount(string $indexName): int;

    /**
     * Applies an additional mapping update on top of an already-created (typically freshly-cloned,
     * still-empty) index -- always safe regardless of MappingDiffClassifier's verdict, since a
     * zero-document index has no existing typed data any change could conflict with. Used by
     * RebuildOrchestrator to give a rebuild's target a mapping that differs from live's current one,
     * without ever touching live itself.
     *
     * @param string $indexName
     * @param array<string, mixed> $mappingProperties
     */
    public function applyMapping(string $indexName, array $mappingProperties): void;

    /**
     * @param string $indexName
     *
     * @return array<string, mixed> The index's current `properties` mapping.
     */
    public function getMapping(string $indexName): array;

    /**
     * Disables automatic refresh and replicas for the duration of a large bulk load -- standard ES/OS
     * practice, real overhead savings at hundreds of thousands of documents. Returns the settings
     * actually read from the cluster beforehand, for `restoreSettings()` to put back afterward.
     *
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    public function disableRefreshAndReplicasForBulkLoad(string $indexName): array;

    /**
     * @param string $indexName
     * @param array<string, mixed> $settings
     */
    public function restoreSettings(string $indexName, array $settings): void;

    /**
     * @param string $indexName
     *
     * @return array<string, mixed> The index's current `settings.index` block, filtered of cluster-managed
     *   keys (see `IndexCloner::NON_COPYABLE_SETTINGS`) so the result is safe to PUT onto a different index.
     */
    public function getFilteredSettings(string $indexName): array;

    /**
     * Creates $targetIndexName fresh with the given, already-assembled mapping and settings -- the
     * lower-level primitive `cloneMappingAndSettings()` itself is built on. Exists so a caller (see
     * `RebuildOrchestrator::buildTarget()`) can read live's mapping/settings, run the settings through its
     * own expansion (e.g. a `TargetIndexSettingsExpanderPluginInterface` stack), and only then create the
     * target -- without duplicating this method's request/response handling.
     *
     * @param string $targetIndexName
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $settings
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    public function createIndexWithMappingAndSettings(string $targetIndexName, array $mapping, array $settings): void;
}
