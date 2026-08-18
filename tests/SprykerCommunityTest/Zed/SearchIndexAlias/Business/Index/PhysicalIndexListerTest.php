<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Index;

use Codeception\Test\Unit;
use Elastica\Client;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexLister;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices. `listIndexNamesForAlias()` scans EVERY
 * index in the cluster (`_cat/indices`) and filters by name pattern, so the one thing worth protecting
 * live is that the filter genuinely only matches indices belonging to the given alias, not a same-prefix
 * neighbor.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Index
 * @group PhysicalIndexListerTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class PhysicalIndexListerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_lister_';

    protected Client $client;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testListIndexNamesForAliasReturnsOnlyIndicesBelongingToThatAlias(): void
    {
        $aliasName = static::TEST_PREFIX . 'page';
        $this->client->getIndex($aliasName . '_20260101_120000')->create();
        $this->client->getIndex($aliasName . '_20260102_120000')->create();
        // A different alias that shares "page" as a suffix -- must NOT be matched.
        $this->client->getIndex(static::TEST_PREFIX . 'other_page_20260101_120000')->create();

        $indexNames = $this->createLister()->listIndexNamesForAlias($aliasName);

        $this->assertEqualsCanonicalizing(
            [$aliasName . '_20260101_120000', $aliasName . '_20260102_120000'],
            $indexNames,
        );
    }

    public function testListIndexNamesForAliasReturnsEmptyArrayWhenNoIndexBelongsToTheAlias(): void
    {
        $indexNames = $this->createLister()->listIndexNamesForAlias(static::TEST_PREFIX . 'never_created');

        $this->assertSame([], $indexNames);
    }

    protected function createLister(): PhysicalIndexLister
    {
        return new PhysicalIndexLister(
            new ElasticaClientProvider(new SearchElasticsearchConfig()),
            new IndexNameBuilder(new SearchIndexAliasConfig()),
        );
    }
}
