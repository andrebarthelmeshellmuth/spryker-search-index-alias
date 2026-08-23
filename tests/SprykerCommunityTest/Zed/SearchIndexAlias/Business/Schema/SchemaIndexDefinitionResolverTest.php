<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Schema;

use Codeception\Test\Unit;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Builder\IndexDefinitionBuilder;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Finder\SchemaDefinitionFinder;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Loader\IndexDefinitionLoader;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Merger\IndexDefinitionMerger;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Reader\IndexDefinitionReader;
use Spryker\Zed\SearchElasticsearch\Business\SourceIdentifier\SourceIdentifier;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\SchemaDefinitionNotFoundException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Schema\PlainJsonUtilEncodingService;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Schema\SchemaIndexDefinitionResolver;

/**
 * Real, unmocked pass through core's own (`spryker/search-elasticsearch`) schema-JSON discovery+merge
 * pipeline -- no cluster call, purely file-based, so this project's own `Shared/Search/Schema/*.json`
 * definitions (and every community package's contribution to them, see this class's own doc block for
 * the confirmed real 4-file "page" example) are what's actually exercised here.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Schema
 * @group SchemaIndexDefinitionResolverTest
 * Add your own group annotations below this line
 */
class SchemaIndexDefinitionResolverTest extends Unit
{
    public function testResolveMappingAndSettingsReturnsTheRealMergedPageDefinitionForDe(): void
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();
        $aliasName = (new CanonicalIndexNameResolver($searchElasticsearchConfig))->resolve('page', 'DE');
        $resolver = $this->createResolver();

        $definition = $resolver->resolveMappingAndSettings($aliasName, 'DE');

        $this->assertArrayHasKey('mapping', $definition);
        $this->assertArrayHasKey('settings', $definition);
        // Real project schema: the "page" mapping always declares at least a "properties" key -- see
        // this class's own doc block for the mapping-type-unwrap this asserts implicitly (a still-wrapped
        // {"page": {"properties": {...}}} shape would fail this assertion instead).
        $this->assertArrayHasKey('properties', $definition['mapping']);
        $this->assertNotSame([], $definition['mapping']['properties']);
    }

    public function testResolveMappingAndSettingsThrowsSchemaDefinitionNotFoundExceptionForAnUnknownAlias(): void
    {
        $resolver = $this->createResolver();

        $this->expectException(SchemaDefinitionNotFoundException::class);

        $resolver->resolveMappingAndSettings('phpunit_no_such_alias_exists', 'DE');
    }

    protected function createResolver(): SchemaIndexDefinitionResolver
    {
        $searchElasticsearchConfig = new SearchElasticsearchConfig();

        return new SchemaIndexDefinitionResolver(new IndexDefinitionBuilder(
            new IndexDefinitionLoader(
                new SchemaDefinitionFinder($searchElasticsearchConfig),
                new IndexDefinitionReader(new PlainJsonUtilEncodingService()),
                new SourceIdentifier($searchElasticsearchConfig),
            ),
            new IndexDefinitionMerger(),
        ));
    }
}
