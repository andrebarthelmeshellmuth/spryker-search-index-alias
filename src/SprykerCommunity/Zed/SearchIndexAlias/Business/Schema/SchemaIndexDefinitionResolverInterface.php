<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Schema;

interface SchemaIndexDefinitionResolverInterface
{
    /**
     * Resolves the FULL, merged mapping+settings for one alias straight from the project's own
     * `Shared/Search/Schema/<sourceIdentifier>.json` definition(s) -- the exact same source
     * `search:setup`/core's own installer builds from, reused via core's own
     * `IndexDefinitionBuilder`/`IndexDefinitionLoader`/`IndexDefinitionMerger` rather than
     * reimplemented, so multi-package schema contributions (core, other community packages, the
     * project's own override) are merged identically to how a real `search:setup` run would merge
     * them. Never touches the live cluster.
     *
     * @param string $aliasName
     * @param string $storeName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\SchemaDefinitionNotFoundException
     *
     * @return array{mapping: array<string, mixed>, settings: array<string, mixed>}
     */
    public function resolveMappingAndSettings(string $aliasName, string $storeName): array;
}
