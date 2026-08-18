<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Adoption;

use Codeception\Test\Unit;
use Elastica\Client;
use Elastica\Document;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexAdopter;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch. Verifies the zero-downtime first-migration path: a
 * bare, un-aliased concrete index (the state a project's existing search index is genuinely in before
 * this package is ever installed) really ends up as a real alias pointing at a real, fully-populated
 * clone, with the original concrete index's own name reused as the alias name.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Adoption
 * @group IndexAdopterTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class IndexAdopterTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_adopter_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected IndexAdopter $indexAdopter;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
        $this->indexAdopter = new IndexAdopter(
            $this->aliasManager,
            new IndexCloner(new ElasticaClientProvider(new SearchElasticsearchConfig())),
            new IndexNameBuilder(new SearchIndexAliasConfig()),
        );
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testAdoptTurnsABareConcreteIndexIntoAnAliasPointingAtAFullyPopulatedClone(): void
    {
        $concreteIndexName = static::TEST_PREFIX . 'legacy';
        $this->client->getIndex($concreteIndexName)->create();
        $this->client->getIndex($concreteIndexName)->addDocuments([
            new Document('1', ['sku' => 'ABC']),
            new Document('2', ['sku' => 'DEF']),
        ]);
        $this->client->getIndex($concreteIndexName)->refresh();
        $searchIndexScopeTransfer = $this->createScope($concreteIndexName);

        $newIndexName = $this->indexAdopter->adopt($searchIndexScopeTransfer);

        $this->assertNotSame($concreteIndexName, $newIndexName);
        $this->assertSame([$newIndexName], $this->aliasManager->getIndicesForAlias($concreteIndexName));
        $countResponse = $this->client->request(sprintf('%s/_count', $newIndexName), 'GET')->getData();
        $this->assertSame(2, $countResponse['count']);
    }

    public function testNeedsAdoptionIsTrueForABareConcreteIndexThatIsNeitherAliasedNorAnAlias(): void
    {
        $concreteIndexName = static::TEST_PREFIX . 'candidate';
        $this->client->getIndex($concreteIndexName)->create();

        $this->assertTrue($this->indexAdopter->needsAdoption($this->createScope($concreteIndexName)));
    }

    public function testNeedsAdoptionIsFalseForAScopeThatIsAlreadyAnAlias(): void
    {
        $aliasName = static::TEST_PREFIX . 'already_adopted';
        $indexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($indexName)->create();
        $this->aliasManager->createAlias($aliasName, $indexName);

        $this->assertFalse($this->indexAdopter->needsAdoption($this->createScope($aliasName)));
    }

    public function testNeedsAdoptionIsFalseForAScopeWhereNeitherAnAliasNorAConcreteIndexExists(): void
    {
        $this->assertFalse($this->indexAdopter->needsAdoption($this->createScope(static::TEST_PREFIX . 'never_created')));
    }

    public function testAdoptThrowsAnAdoptionNotApplicableExceptionWhenTheScopeIsAlreadyAnAlias(): void
    {
        $aliasName = static::TEST_PREFIX . 'reject';
        $indexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($indexName)->create();
        $this->aliasManager->createAlias($aliasName, $indexName);

        $this->expectException(AdoptionNotApplicableException::class);

        $this->indexAdopter->adopt($this->createScope($aliasName));
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('phpunit_adopter_source')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }
}
