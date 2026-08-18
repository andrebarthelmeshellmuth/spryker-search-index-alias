<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rollout;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real database AND real Elasticsearch/OpenSearch, since start() both resolves the
 * scope's current live index from the cluster and persists the new BUILDING row.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rollout
 * @group RolloutStarterTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 * @group NeedsSearch
 */
class RolloutStarterTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_rollout_starter_source';

    protected function _before(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    protected function _after(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    public function testStartPersistsABuildingRolloutWithNoLiveIndexWhenTheAliasDoesNotExistYet(): void
    {
        // A genuinely nonexistent alias -- no adoption has happened for this fake scope, so
        // AliasManager::getIndicesForAlias() correctly returns [] (a 404 from Elasticsearch), and
        // liveIndexName must resolve to null, not throw.
        $result = $this->createRolloutStarter()->start($this->createScope('phpunit_rollout_starter_alias_never_adopted'));

        $this->assertSame(SearchIndexAliasConfig::STATUS_BUILDING, $result->getStatus());
        $this->assertNull($result->getLiveIndexName());
        $this->assertNotNull($result->getIdSearchIndexRollout());
        $this->assertNotNull($result->getStartedAt());
    }

    public function testStartRecordsTheGivenTriggeredByUser(): void
    {
        $result = $this->createRolloutStarter()->start(
            $this->createScope('phpunit_rollout_starter_alias_user'),
            'phpunit-test-user',
        );

        $this->assertSame('phpunit-test-user', $result->getTriggeredByUser());
    }

    public function testStartThrowsConcurrentRolloutExceptionWhenAnActiveRolloutAlreadyExistsForTheScope(): void
    {
        $searchIndexScopeTransfer = $this->createScope('phpunit_rollout_starter_alias_concurrent');
        $this->createRolloutStarter()->start($searchIndexScopeTransfer);

        $this->expectException(ConcurrentRolloutException::class);

        $this->createRolloutStarter()->start($searchIndexScopeTransfer);
    }

    protected function createScope(string $aliasName): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->setStoreName('PHPUNIT')
            ->setAliasName($aliasName);
    }

    protected function createRolloutStarter(): RolloutStarter
    {
        $searchIndexAliasRepository = new SearchIndexAliasRepository();
        $aliasManager = new AliasManager(new ElasticaClientProvider(new SearchElasticsearchConfig()));

        return new RolloutStarter(
            new RolloutGuard($searchIndexAliasRepository),
            $aliasManager,
            new SearchIndexAliasEntityManager(),
        );
    }
}
