<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff;

use Generated\Shared\Transfer\SearchIndexMappingDiffTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * Pure, side-effect-free tree comparison -- no cluster/DB access, deliberately, so it can be unit-tested
 * against fixture mapping arrays without any live infrastructure. Safety-first: a field present in one
 * side but not the other, OR present in both with a different `type`, is always treated as breaking,
 * never guessed to be safe. See interface doc block for what this classification is (and is not) used
 * for in this package.
 */
class MappingDiffClassifier implements MappingDiffClassifierInterface
{
    /**
     * @param array<string, mixed> $liveMapping
     * @param array<string, mixed> $targetMapping
     */
    public function classify(array $liveMapping, array $targetMapping): SearchIndexMappingDiffTransfer
    {
        $liveProperties = $liveMapping['properties'] ?? [];
        $targetProperties = $targetMapping['properties'] ?? [];

        $added = [];
        $breaking = [];

        $this->walk($liveProperties, $targetProperties, '', $added, $breaking);

        $classification = SearchIndexAliasConfig::MAPPING_DIFF_NONE;

        if ($breaking !== []) {
            $classification = SearchIndexAliasConfig::MAPPING_DIFF_BREAKING;
        } elseif ($added !== []) {
            $classification = SearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE;
        }

        return (new SearchIndexMappingDiffTransfer())
            ->setClassification($classification)
            ->setAddedFields($added)
            ->setBreakingFields($breaking);
    }

    /**
     * @param array<string, mixed> $live
     * @param array<string, mixed> $target
     * @param string $prefix
     * @param array<int, string> $added
     * @param array<int, string> $breaking
     */
    protected function walk(array $live, array $target, string $prefix, array &$added, array &$breaking): void
    {
        foreach ($target as $field => $targetDefinition) {
            $this->processTargetField((string)$field, $targetDefinition, $live, $prefix, $added, $breaking);
        }

        foreach (array_keys($live) as $field) {
            if (array_key_exists($field, $target)) {
                continue;
            }

            $breaking[] = $this->buildPath($prefix, (string)$field);
        }
    }

    /**
     * @param string $field
     * @param array<string, mixed> $live
     * @param string $prefix
     * @param array<int, string> $added
     * @param array<int, string> $breaking
     */
    protected function processTargetField(
        string $field,
        mixed $targetDefinition,
        array $live,
        string $prefix,
        array &$added,
        array &$breaking,
    ): void {
        $path = $this->buildPath($prefix, $field);

        if (!array_key_exists($field, $live)) {
            $added[] = $path;

            return;
        }

        $liveDefinition = $live[$field];

        if ($this->isRetyped($liveDefinition, $targetDefinition)) {
            // Retyped -- do not recurse further into this field, its sub-structure comparison is
            // meaningless once the type itself no longer matches.
            $breaking[] = $path;

            return;
        }

        $liveSubProperties = is_array($liveDefinition) ? ($liveDefinition['properties'] ?? null) : null;
        $targetSubProperties = is_array($targetDefinition) ? ($targetDefinition['properties'] ?? null) : null;

        if (!is_array($liveSubProperties) || !is_array($targetSubProperties)) {
            return;
        }

        $this->walk($liveSubProperties, $targetSubProperties, $path, $added, $breaking);
    }

    /**
     * @param string $prefix
     * @param string $field
     */
    protected function buildPath(string $prefix, string $field): string
    {
        return $prefix === '' ? $field : sprintf('%s.%s', $prefix, $field);
    }

    protected function isRetyped(mixed $liveDefinition, mixed $targetDefinition): bool
    {
        $liveType = is_array($liveDefinition) ? ($liveDefinition['type'] ?? null) : null;
        $targetType = is_array($targetDefinition) ? ($targetDefinition['type'] ?? null) : null;

        return $liveType !== null && $targetType !== null && $liveType !== $targetType;
    }
}
