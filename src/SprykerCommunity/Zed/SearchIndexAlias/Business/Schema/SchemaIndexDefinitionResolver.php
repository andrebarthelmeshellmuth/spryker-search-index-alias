<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Schema;

use Spryker\Zed\SearchElasticsearch\Business\Definition\Builder\IndexDefinitionBuilderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\SchemaDefinitionNotFoundException;

/**
 * Wraps core's own `IndexDefinitionBuilderInterface` (`spryker/search-elasticsearch`, already a hard
 * dependency of this package) rather than reading/merging `Shared/Search/Schema/*.json` files directly:
 * a project's "page" source is typically declared across SEVERAL such files (core's own base
 * definition, any community package that contributes to it, and the project's own override) that core's
 * own loader discovers and deep-merges. Reimplementing that discovery+merge here would risk silently
 * diverging from what a real `search:setup` run actually produces -- confirmed by reading this
 * demoshop's own tree, which has four separate `page.json` contributors. See
 * `SearchIndexAliasBusinessFactory::createSchemaIndexDefinitionResolver()` for how the builder is wired
 * from core's own Loader/Finder/Reader/Merger/SourceIdentifier collaborators.
 *
 * Mapping shape note: a schema JSON file's own `mappings` key is still written in the legacy,
 * mapping-TYPE-wrapped shape (`{"page": {"properties": {...}}}`) core has kept for backward
 * compatibility, whereas a modern (mapping-type-less) cluster's live `_mapping` response -- and
 * `IndexCloner::getMapping()` -- is already flat (`{"properties": {...}}`). Core's own (deprecated but
 * still shipped) `MappingBuilder::getMappingData()` unwraps this the same way: `array_shift()` the one
 * (and only) top-level type entry. Replicated inline here rather than depending on `MappingBuilder`
 * itself, since that class is built around `Elastica\Index`/`Elastica\Mapping` objects, not plain arrays.
 */
class SchemaIndexDefinitionResolver implements SchemaIndexDefinitionResolverInterface
{
    /**
     * @param \Spryker\Zed\SearchElasticsearch\Business\Definition\Builder\IndexDefinitionBuilderInterface $indexDefinitionBuilder
     */
    public function __construct(protected IndexDefinitionBuilderInterface $indexDefinitionBuilder)
    {
    }

    /**
     * @param string $aliasName
     * @param string $storeName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\SchemaDefinitionNotFoundException
     *
     * @return array{mapping: array<string, mixed>, settings: array<string, mixed>}
     */
    public function resolveMappingAndSettings(string $aliasName, string $storeName): array
    {
        foreach ($this->indexDefinitionBuilder->build($storeName) as $indexDefinitionTransfer) {
            if ($indexDefinitionTransfer->getIndexName() !== $aliasName) {
                continue;
            }

            return [
                'mapping' => $this->unwrapMappingType($indexDefinitionTransfer->getMappings()),
                'settings' => $indexDefinitionTransfer->getSettings(),
            ];
        }

        throw new SchemaDefinitionNotFoundException(sprintf(
            'No Shared/Search/Schema/*.json definition resolves to alias "%s" for store "%s" -- ' .
            'confirm a schema JSON file for this source identifier exists somewhere in the project or ' .
            'its installed packages.',
            $aliasName,
            $storeName,
        ));
    }

    /**
     * @param array<string, mixed> $mappings
     *
     * @return array<string, mixed>
     */
    protected function unwrapMappingType(array $mappings): array
    {
        return $mappings === [] ? [] : (array)array_shift($mappings);
    }
}
