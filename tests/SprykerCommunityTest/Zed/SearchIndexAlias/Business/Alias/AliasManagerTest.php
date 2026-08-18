<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Alias;

use Codeception\Test\Unit;
use Elastica\Client;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasNameCollisionException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices created and torn down per test. Every
 * mutation this class makes is a single atomic `_aliases` call (see the class's own docblock); the one
 * behavior most worth protecting live is that it genuinely IS atomic and that the collision/failure
 * exception mapping (`invalid_alias_name_exception` -> AliasNameCollisionException) is real, not assumed.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Alias
 * @group AliasManagerTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class AliasManagerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_alias_manager_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testCreateAliasPointsANewAliasAtAConcreteIndex(): void
    {
        $indexName = $this->createTestIndex();

        $this->aliasManager->createAlias(static::TEST_PREFIX . 'alias1', $indexName);

        $this->assertSame([$indexName], $this->aliasManager->getIndicesForAlias(static::TEST_PREFIX . 'alias1'));
    }

    public function testSwitchAliasAtomicallyMovesTheAliasFromOneIndexToAnother(): void
    {
        $oldIndexName = $this->createTestIndex();
        $newIndexName = $this->createTestIndex();
        $this->aliasManager->createAlias(static::TEST_PREFIX . 'alias2', $oldIndexName);

        $this->aliasManager->switchAlias(static::TEST_PREFIX . 'alias2', $oldIndexName, $newIndexName);

        $this->assertSame([$newIndexName], $this->aliasManager->getIndicesForAlias(static::TEST_PREFIX . 'alias2'));
        // The old index itself must survive the switch, just unaliased -- switchAlias never deletes.
        $this->assertTrue($this->aliasManager->indexExists($oldIndexName));
        $this->assertSame([], $this->aliasManager->getAliasesForIndex($oldIndexName));
    }

    public function testAdoptConcreteIndexTurnsAConcreteIndexIntoAnAliasInOneAtomicStep(): void
    {
        // The "remove_index" trick: aliasName itself starts out as a real concrete index (not yet an
        // alias at all) -- adoptConcreteIndex atomically deletes that concrete index and points the same
        // name, now as an alias, at the fresh target.
        $concreteIndexName = static::TEST_PREFIX . 'concrete_to_adopt';
        $this->client->getIndex($concreteIndexName)->create();
        $targetIndexName = $this->createTestIndex();

        $this->aliasManager->adoptConcreteIndex($concreteIndexName, $targetIndexName);

        // $concreteIndexName is now an ALIAS pointing at the target -- resolving it returns the target's
        // own index name, not itself.
        $this->assertSame([$targetIndexName], $this->aliasManager->getIndicesForAlias($concreteIndexName));
    }

    public function testGetIndicesForAliasReturnsAnEmptyArrayForANonExistentAlias(): void
    {
        $this->assertSame([], $this->aliasManager->getIndicesForAlias(static::TEST_PREFIX . 'never_created'));
    }

    public function testIndexExistsReturnsTrueForARealIndex(): void
    {
        $indexName = $this->createTestIndex();

        $this->assertTrue($this->aliasManager->indexExists($indexName));
    }

    public function testIndexExistsReturnsFalseForANonExistentIndex(): void
    {
        $this->assertFalse($this->aliasManager->indexExists(static::TEST_PREFIX . 'never_created'));
    }

    public function testGetAliasesForIndexReturnsEveryAliasPointingAtIt(): void
    {
        $indexName = $this->createTestIndex();
        $this->aliasManager->createAlias(static::TEST_PREFIX . 'alias3', $indexName);

        $this->assertSame([static::TEST_PREFIX . 'alias3'], $this->aliasManager->getAliasesForIndex($indexName));
    }

    public function testDeleteUnaliasedIndexDeletesARealUnaliasedIndex(): void
    {
        $indexName = $this->createTestIndex();

        $this->aliasManager->deleteUnaliasedIndex($indexName);

        $this->assertFalse($this->aliasManager->indexExists($indexName));
    }

    public function testDeleteUnaliasedIndexRefusesToDeleteAnIndexThatIsStillAliased(): void
    {
        $indexName = $this->createTestIndex();
        $this->aliasManager->createAlias(static::TEST_PREFIX . 'alias4', $indexName);

        $this->expectException(AliasOperationFailedException::class);

        $this->aliasManager->deleteUnaliasedIndex($indexName);

        // Cleanup: alias still points here, so _after()'s _cat/indices sweep would otherwise leak it.
        $this->client->request('_aliases', 'POST', ['actions' => [['remove' => ['index' => $indexName, 'alias' => static::TEST_PREFIX . 'alias4']]]]);
    }

    public function testCreateAliasThrowsAliasNameCollisionExceptionWhenTheNameIsAlreadyARealConcreteIndex(): void
    {
        $concreteIndexName = static::TEST_PREFIX . 'already_concrete';
        $this->client->getIndex($concreteIndexName)->create();
        $otherIndexName = $this->createTestIndex();

        $this->expectException(AliasNameCollisionException::class);

        $this->aliasManager->createAlias($concreteIndexName, $otherIndexName);
    }

    protected function createTestIndex(): string
    {
        $indexName = static::TEST_PREFIX . 'idx_' . bin2hex(random_bytes(4));
        $this->client->getIndex($indexName)->create();

        return $indexName;
    }
}
