<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

interface IndexNameBuilderInterface
{
    /**
     * A new, guaranteed-unique-at-this-instant physical index name for the given alias, e.g.
     * `de_page` -> `de_page_20260815_143012`. Collisions within the same second are vanishingly
     * unlikely for a human/pipeline-triggered rebuild and are, in any case, rejected by Elasticsearch
     * itself (index already exists) rather than silently overwritten -- see AliasManager::createIndex().
     *
     * @param string $aliasName
     */
    public function buildTargetIndexName(string $aliasName): string;

    /**
     * Whether a physical index name looks like one this package created for the given alias (i.e.
     * `{aliasName}_{timestamp}`), used by IndexEnumerator/IndexPruner to tell "an index that belongs to
     * this scope's rollout history" apart from an unrelated index that merely shares a name prefix.
     *
     * @param string $indexName
     * @param string $aliasName
     */
    public function belongsToAlias(string $indexName, string $aliasName): bool;
}
