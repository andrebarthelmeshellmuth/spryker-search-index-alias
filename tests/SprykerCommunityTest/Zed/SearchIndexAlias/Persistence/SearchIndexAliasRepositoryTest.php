<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Persistence;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexDeletionTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletionQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real database, real rows. Seeds via the entity manager (already covered by its own
 * test) rather than raw Propel entities, so each test reads back exactly what a real rollout would have
 * written.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Persistence
 * @group SearchIndexAliasRepositoryTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 */
class SearchIndexAliasRepositoryTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_repo_source';

    /**
     * @var string
     */
    protected const TEST_STORE_NAME = 'PHPUNIT';

    protected function _before(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
        SpySearchIndexDeletionQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    protected function _after(): void
    {
        SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
        SpySearchIndexDeletionQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->delete();
    }

    public function testFindRolloutByIdReturnsTheMatchingTransfer(): void
    {
        $created = $this->seedRollout(SearchIndexAliasConfig::STATUS_BUILDING);

        $found = (new SearchIndexAliasRepository())->findRolloutById($created->getIdSearchIndexRolloutOrFail());

        $this->assertNotNull($found);
        $this->assertSame($created->getIdSearchIndexRollout(), $found->getIdSearchIndexRollout());
        $this->assertSame(SearchIndexAliasConfig::STATUS_BUILDING, $found->getStatus());
    }

    public function testFindRolloutByIdReturnsNullForANonExistentId(): void
    {
        $found = (new SearchIndexAliasRepository())->findRolloutById(999999999);

        $this->assertNull($found);
    }

    public function testFindLatestRolloutForScopeReturnsTheMostRecentlyCreatedRow(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);
        $second = $this->seedRollout(SearchIndexAliasConfig::STATUS_FAILED);

        $found = (new SearchIndexAliasRepository())->findLatestRolloutForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME);

        $this->assertNotNull($found);
        $this->assertSame($second->getIdSearchIndexRollout(), $found->getIdSearchIndexRollout());
    }

    public function testFindActiveRolloutForScopeReturnsANonTerminalRow(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);
        $active = $this->seedRollout(SearchIndexAliasConfig::STATUS_BUILDING);

        $found = (new SearchIndexAliasRepository())->findActiveRolloutForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME);

        $this->assertNotNull($found);
        $this->assertSame($active->getIdSearchIndexRollout(), $found->getIdSearchIndexRollout());
    }

    public function testFindActiveRolloutForScopeReturnsNullWhenOnlyTerminalRowsExist(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);

        $found = (new SearchIndexAliasRepository())->findActiveRolloutForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME);

        $this->assertNull($found);
    }

    public function testGetRolloutHistoryForScopeReturnsRowsNewestFirst(): void
    {
        $first = $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);
        $second = $this->seedRollout(SearchIndexAliasConfig::STATUS_FAILED);

        $history = (new SearchIndexAliasRepository())->getRolloutHistoryForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME);

        $this->assertCount(2, $history);
        $this->assertSame($second->getIdSearchIndexRollout(), $history[0]->getIdSearchIndexRollout());
        $this->assertSame($first->getIdSearchIndexRollout(), $history[1]->getIdSearchIndexRollout());
    }

    public function testGetRolloutHistoryForScopeRespectsTheLimit(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FAILED);
        $this->seedRollout(SearchIndexAliasConfig::STATUS_ABORTED);

        $history = (new SearchIndexAliasRepository())->getRolloutHistoryForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME, 2);

        $this->assertCount(2, $history);
    }

    public function testGetLatestRolloutPerScopeReturnsOnlyTheNewestRowForEachDistinctScope(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED, static::TEST_SOURCE_IDENTIFIER, 'DE');
        $newestDe = $this->seedRollout(SearchIndexAliasConfig::STATUS_FAILED, static::TEST_SOURCE_IDENTIFIER, 'DE');
        $onlyAt = $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED, static::TEST_SOURCE_IDENTIFIER, 'AT');

        $latestPerScope = (new SearchIndexAliasRepository())->getLatestRolloutPerScope();
        $latestIdsForThisSource = array_map(
            fn (SearchIndexRolloutTransfer $searchIndexRolloutTransfer): ?int => $searchIndexRolloutTransfer->getIdSearchIndexRollout(),
            array_filter(
                $latestPerScope,
                fn (SearchIndexRolloutTransfer $searchIndexRolloutTransfer): bool => $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getSourceIdentifier() === static::TEST_SOURCE_IDENTIFIER,
            ),
        );

        $this->assertContains($newestDe->getIdSearchIndexRollout(), $latestIdsForThisSource);
        $this->assertContains($onlyAt->getIdSearchIndexRollout(), $latestIdsForThisSource);
        $this->assertCount(2, $latestIdsForThisSource, 'One row per distinct (source_identifier, store_name), not one per row created.');
    }

    public function testGetDeletionHistoryForScopeReturnsNewestFirst(): void
    {
        $first = $this->seedDeletion('phpunit_repo_alias_1');
        $second = $this->seedDeletion('phpunit_repo_alias_2');

        $history = (new SearchIndexAliasRepository())->getDeletionHistoryForScope(static::TEST_SOURCE_IDENTIFIER, static::TEST_STORE_NAME);

        $this->assertCount(2, $history);
        $this->assertSame($second->getIdSearchIndexDeletion(), $history[0]->getIdSearchIndexDeletion());
        $this->assertSame($first->getIdSearchIndexDeletion(), $history[1]->getIdSearchIndexDeletion());
    }

    protected function seedDeletion(string $indexName): SearchIndexDeletionTransfer
    {
        return (new SearchIndexAliasEntityManager())->recordIndexDeletion(
            (new SearchIndexDeletionTransfer())
                ->setSearchIndexScope(
                    (new SearchIndexScopeTransfer())
                        ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
                        ->setStoreName(static::TEST_STORE_NAME)
                        ->setAliasName('phpunit_repo_alias'),
                )
                ->setIndexName($indexName),
        );
    }

    /**
     * @param string $status
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    protected function seedRollout(
        string $status,
        string $sourceIdentifier = self::TEST_SOURCE_IDENTIFIER,
        string $storeName = self::TEST_STORE_NAME,
    ): SearchIndexRolloutTransfer {
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier($sourceIdentifier)
                    ->setStoreName($storeName)
                    ->setAliasName('phpunit_repo_alias'),
            )
            ->setStatus($status)
            ->setLiveIndexName('phpunit_repo_alias_live');

        return (new SearchIndexAliasEntityManager())->createRollout($searchIndexRolloutTransfer);
    }
}
