<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Health;

use Codeception\Test\Unit;
use Elastica\Client;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Health\SearchIndexHealthChecker;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;

/**
 * INTEGRATION TEST — real Elasticsearch/OpenSearch, real indices. Only `checkScope()` is exercised here;
 * `checkAllManagedScopes()` is a thin loop over `IndexEnumerator::enumerateScopes()`, which needs the
 * host project's own Store facade/search config wiring and is exercised there instead.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Health
 * @group SearchIndexHealthCheckerTest
 * Add your own group annotations below this line
 * @group NeedsSearch
 */
class SearchIndexHealthCheckerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_PREFIX = 'phpunit_health_';

    protected Client $client;

    protected AliasManager $aliasManager;

    protected SearchIndexHealthChecker $healthChecker;

    protected function _before(): void
    {
        $this->client = (new ElasticaClientProvider(new SearchElasticsearchConfig()))->getClient();
        $this->aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));
        $this->healthChecker = new SearchIndexHealthChecker(
            $this->aliasManager,
            new IndexCloner(new ElasticaClientProvider(new SearchElasticsearchConfig())),
            $this->createNullIndexEnumerator(),
        );
    }

    protected function _after(): void
    {
        foreach ($this->client->request('_cat/indices/' . static::TEST_PREFIX . '*?format=json')->getData() as $row) {
            $this->client->getIndex($row['index'])->delete();
        }
    }

    public function testCheckScopeReportsUnhealthyWhenTheAliasDoesNotExistYet(): void
    {
        $result = $this->healthChecker->checkScope($this->createScope(static::TEST_PREFIX . 'never_adopted'));

        $this->assertFalse($result->getIsHealthy());
        $this->assertNotEmpty($result->getIssues());
        $this->assertSame([], $result->getAliasedIndexNames());
    }

    public function testCheckScopeReportsHealthyForANormalSingleAliasedIndex(): void
    {
        $aliasName = static::TEST_PREFIX . 'healthy';
        $indexName = $aliasName . '_20260101_120000';
        $this->client->getIndex($indexName)->create();
        $this->client->getIndex($indexName)->refresh();
        $this->aliasManager->createAlias($aliasName, $indexName);

        $result = $this->healthChecker->checkScope($this->createScope($aliasName));

        $this->assertTrue($result->getIsHealthy());
        $this->assertSame([], $result->getIssues());
        $this->assertSame([$indexName], $result->getAliasedIndexNames());
        $this->assertSame(0, $result->getDocumentCount());
    }

    public function testCheckScopeReportsDriftWhenTheAliasPointsAtMultipleIndicesSimultaneously(): void
    {
        $aliasName = static::TEST_PREFIX . 'drifted';
        $firstIndexName = $aliasName . '_20260101_120000';
        $secondIndexName = $aliasName . '_20260102_120000';
        $this->client->getIndex($firstIndexName)->create();
        $this->client->getIndex($secondIndexName)->create();
        // Simulate drift directly via the raw alias API -- AliasManager's own operations never produce
        // this state, but real-world manual cluster intervention can.
        $this->client->request('_aliases', 'POST', [
        'actions' => [
            ['add' => ['index' => $firstIndexName, 'alias' => $aliasName]],
            ['add' => ['index' => $secondIndexName, 'alias' => $aliasName]],
        ]]);

        $result = $this->healthChecker->checkScope($this->createScope($aliasName));

        $this->assertFalse($result->getIsHealthy());
        $this->assertCount(1, $result->getIssues());
        $this->assertStringContainsString('2 indices', $result->getIssues()[0]);
        $this->assertCount(2, $result->getAliasedIndexNames());
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName($aliasName);
    }

    protected function createNullIndexEnumerator(): IndexEnumeratorInterface
    {
        return new class implements IndexEnumeratorInterface {
            public function enumerateScopes(): array
            {
                return [];
            }

            // phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter

            public function findScope(string $sourceIdentifier, string $storeName): ?SearchIndexScopeTransfer
            {
                // Never called by SearchIndexHealthChecker::checkScope(), the only method this test
                // exercises -- required by the interface regardless.
                return null;
            }

            // phpcs:enable
        };
    }
}
