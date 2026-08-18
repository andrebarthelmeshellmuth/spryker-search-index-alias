<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff;

use Generated\Shared\Transfer\SearchIndexMappingDiffTransfer;

interface MappingDiffClassifierInterface
{
    /**
     * Compares two Elasticsearch/OpenSearch `properties` mapping trees and classifies the difference.
     *
     * IMPORTANT: this package's own rebuild flow (RebuildOrchestrator) does NOT need this classification
     * to operate correctly -- the whole point of building a fresh physical index behind an alias is that
     * the target's mapping can be anything at all; nothing about it is ever applied in place to the live
     * index. This classifier exists for a DIFFERENT, still real risk: an operator deciding whether it is
     * safe to run `console search:setup` (which updates the LIVE index's mapping in place) BEFORE
     * kicking off a rebuild, e.g. to start collecting data for a new field early. See README "How it
     * works" for the silent-and-permanent failure mode that makes this check worth having on its own.
     *
     * @param array<string, mixed> $liveMapping The live index's current `properties` mapping.
     * @param array<string, mixed> $targetMapping The mapping being considered for that same index.
     */
    public function classify(array $liveMapping, array $targetMapping): SearchIndexMappingDiffTransfer;
}
