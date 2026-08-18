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
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices. Cluster-level mechanics (mapping/
 * settings cloning, reindex, bulk-load setting toggle) can't be meaningfully verified against a mock.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Adoption
 * @group IndexClonerTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class IndexClonerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_cloner_';

    protected Client $client;

    protected IndexCloner $indexCloner;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->indexCloner = new IndexCloner(new ElasticaClientProvider(new SearchElasticsearchConfig()));
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testCloneMappingAndSettingsCreatesTheTargetWithTheSourcesMapping(): void
    {
        $sourceIndexName = static::TEST_PREFIX . 'source1';
        $this->client->request($sourceIndexName, 'PUT', [
            'mappings' => ['properties' => ['sku' => ['type' => 'keyword']]],
        ]);
        $targetIndexName = static::TEST_PREFIX . 'target1';

        $this->indexCloner->cloneMappingAndSettings($sourceIndexName, $targetIndexName);

        $this->assertTrue($this->client->getIndex($targetIndexName)->exists());
        $this->assertSame(
            ['type' => 'keyword'],
            $this->indexCloner->getMapping($targetIndexName)['properties']['sku'],
        );
    }

    public function testCloneMappingAndSettingsThrowsWhenTheSourceIndexDoesNotExist(): void
    {
        $this->expectException(IndexCloneFailedException::class);

        $this->indexCloner->cloneMappingAndSettings(static::TEST_PREFIX . 'never_created', static::TEST_PREFIX . 'target_should_not_exist');
    }

    public function testReindexIntoCopiesEveryDocumentAndReturnsTheCreatedCount(): void
    {
        $sourceIndexName = static::TEST_PREFIX . 'reindex_source';
        $this->client->getIndex($sourceIndexName)->create();
        $this->client->getIndex($sourceIndexName)->addDocuments([
            new Document('1', ['sku' => 'ABC']),
            new Document('2', ['sku' => 'DEF']),
        ]);
        $this->client->getIndex($sourceIndexName)->refresh();
        $targetIndexName = static::TEST_PREFIX . 'reindex_target';
        $this->indexCloner->cloneMappingAndSettings($sourceIndexName, $targetIndexName);

        $createdCount = $this->indexCloner->reindexInto($sourceIndexName, $targetIndexName);

        $this->assertSame(2, $createdCount);
        $this->assertSame(2, $this->indexCloner->getDocumentCount($targetIndexName));
    }

    public function testGetDocumentCountReturnsZeroForAnEmptyIndex(): void
    {
        $indexName = static::TEST_PREFIX . 'empty';
        $this->client->getIndex($indexName)->create();

        $this->assertSame(0, $this->indexCloner->getDocumentCount($indexName));
    }

    public function testApplyMappingAddsANewFieldToAnExistingIndex(): void
    {
        $indexName = static::TEST_PREFIX . 'mapping_update';
        $this->client->getIndex($indexName)->create();

        $this->indexCloner->applyMapping($indexName, ['properties' => ['newField' => ['type' => 'keyword']]]);

        $this->assertSame('keyword', $this->indexCloner->getMapping($indexName)['properties']['newField']['type']);
    }

    public function testGetMappingReturnsAnEmptyArrayStructureForAnIndexWithNoExplicitMapping(): void
    {
        $indexName = static::TEST_PREFIX . 'no_mapping';
        $this->client->getIndex($indexName)->create();

        $mapping = $this->indexCloner->getMapping($indexName);

        $this->assertArrayNotHasKey('properties', $mapping);
    }

    public function testDisableRefreshAndReplicasForBulkLoadReturnsThePreviousSettingsAndAppliesTheBulkLoadOnes(): void
    {
        $indexName = static::TEST_PREFIX . 'bulk_toggle';
        $this->client->getIndex($indexName)->create();

        $previousSettings = $this->indexCloner->disableRefreshAndReplicasForBulkLoad($indexName);

        $this->assertArrayHasKey('refresh_interval', $previousSettings);
        $this->assertArrayHasKey('number_of_replicas', $previousSettings);

        $currentSettings = $this->client->request(sprintf('%s/_settings', $indexName), 'GET')->getData();
        $this->assertSame('-1', $currentSettings[$indexName]['settings']['index']['refresh_interval']);
        $this->assertSame('0', $currentSettings[$indexName]['settings']['index']['number_of_replicas']);
    }

    public function testGetFilteredSettingsReturnsTheIndexsSettingsWithoutClusterManagedKeys(): void
    {
        $indexName = static::TEST_PREFIX . 'filtered_settings';
        $this->client->getIndex($indexName)->create();

        $settings = $this->indexCloner->getFilteredSettings($indexName);

        $this->assertArrayNotHasKey('uuid', $settings);
        $this->assertArrayNotHasKey('provided_name', $settings);
        $this->assertArrayNotHasKey('creation_date', $settings);
        $this->assertArrayNotHasKey('version', $settings);
    }

    public function testCreateIndexWithMappingAndSettingsCreatesAnIndexWithExactlyTheGivenShape(): void
    {
        $targetIndexName = static::TEST_PREFIX . 'created_direct';

        $this->indexCloner->createIndexWithMappingAndSettings(
            $targetIndexName,
            ['properties' => ['sku' => ['type' => 'keyword']]],
            ['number_of_replicas' => '0'],
        );

        $this->assertTrue($this->client->getIndex($targetIndexName)->exists());
        $this->assertSame('keyword', $this->indexCloner->getMapping($targetIndexName)['properties']['sku']['type']);
        $currentSettings = $this->client->request(sprintf('%s/_settings', $targetIndexName), 'GET')->getData();
        $this->assertSame('0', $currentSettings[$targetIndexName]['settings']['index']['number_of_replicas']);
    }

    public function testCreateIndexWithMappingAndSettingsThrowsWhenTheTargetAlreadyExists(): void
    {
        $targetIndexName = static::TEST_PREFIX . 'already_exists';
        $this->client->getIndex($targetIndexName)->create();

        $this->expectException(IndexCloneFailedException::class);

        $this->indexCloner->createIndexWithMappingAndSettings($targetIndexName, [], []);
    }

    public function testRestoreSettingsAppliesTheGivenSettingsBackOntoTheIndex(): void
    {
        $indexName = static::TEST_PREFIX . 'restore';
        $this->client->getIndex($indexName)->create();
        $previousSettings = $this->indexCloner->disableRefreshAndReplicasForBulkLoad($indexName);

        $this->indexCloner->restoreSettings($indexName, $previousSettings);

        $currentSettings = $this->client->request(sprintf('%s/_settings', $indexName), 'GET')->getData();
        $this->assertSame($previousSettings['number_of_replicas'], $currentSettings[$indexName]['settings']['index']['number_of_replicas']);
    }
}
