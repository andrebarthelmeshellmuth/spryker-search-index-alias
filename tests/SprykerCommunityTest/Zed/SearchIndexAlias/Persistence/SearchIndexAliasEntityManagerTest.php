<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Persistence;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;

/**
 * INTEGRATION TEST — real database, real rows, never mocked: the one behavior actually worth protecting
 * is the `active_scope_key` concurrency guard (a real DB-enforced UNIQUE index, see the entity manager's
 * own docblock) and correct terminal/non-terminal transitions, neither of which a mocked query builder
 * could confirm.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Persistence
 * @group SearchIndexAliasEntityManagerTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 */
class SearchIndexAliasEntityManagerTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_source';

    /**
     * @var string
     */
    protected const TEST_STORE_NAME = 'PHPUNIT';

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

    public function testCreateRolloutPersistsAndReturnsTheGeneratedId(): void
    {
        $resultTransfer = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $this->assertNotNull($resultTransfer->getIdSearchIndexRollout());

        $entity = SpySearchIndexRolloutQuery::create()->findOneByIdSearchIndexRollout($resultTransfer->getIdSearchIndexRolloutOrFail());
        $this->assertNotNull($entity);
        $this->assertSame(SearchIndexAliasConfig::STATUS_BUILDING, $entity->getStatus());
        $this->assertSame(static::TEST_SOURCE_IDENTIFIER, $entity->getSourceIdentifier());
    }

    public function testCreateRolloutSetsActiveScopeKeyForANonTerminalStatus(): void
    {
        (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $entity = SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->findOne();

        $this->assertSame(
            static::TEST_SOURCE_IDENTIFIER . ':' . static::TEST_STORE_NAME,
            $entity->getActiveScopeKey(),
        );
    }

    public function testCreateRolloutLeavesActiveScopeKeyNullForATerminalStatus(): void
    {
        (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_ABORTED));

        $entity = SpySearchIndexRolloutQuery::create()
            ->filterBySourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->findOne();

        $this->assertNull($entity->getActiveScopeKey());
    }

    public function testCreateRolloutThrowsConcurrentRolloutExceptionWhenAnotherNonTerminalRolloutAlreadyExistsForTheSameScope(): void
    {
        (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $this->expectException(ConcurrentRolloutException::class);

        (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));
    }

    public function testCreateRolloutDoesNotThrowWhenAPriorRolloutForTheSameScopeIsAlreadyTerminal(): void
    {
        (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_FLIPPED));

        $resultTransfer = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $this->assertNotNull($resultTransfer->getIdSearchIndexRollout());
    }

    public function testUpdateRolloutChangesTheStatusOfAnExistingRow(): void
    {
        $created = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $created->setStatus(SearchIndexAliasConfig::STATUS_READY);
        (new SearchIndexAliasEntityManager())->updateRollout($created);

        $entity = SpySearchIndexRolloutQuery::create()->findOneByIdSearchIndexRollout($created->getIdSearchIndexRolloutOrFail());
        $this->assertSame(SearchIndexAliasConfig::STATUS_READY, $entity->getStatus());
    }

    public function testUpdateRolloutToATerminalStatusClearsActiveScopeKey(): void
    {
        $created = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $created->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED);
        (new SearchIndexAliasEntityManager())->updateRollout($created);

        $entity = SpySearchIndexRolloutQuery::create()->findOneByIdSearchIndexRollout($created->getIdSearchIndexRolloutOrFail());
        $this->assertNull($entity->getActiveScopeKey());
    }

    public function testUpdateRolloutToATerminalStatusAllowsANewNonTerminalRolloutForTheSameScopeAfterward(): void
    {
        // Proves the concurrency guard genuinely releases once a rollout completes -- not just that the
        // column reads null, but that a real second INSERT succeeds where it would otherwise collide.
        $created = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));
        $created->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED);
        (new SearchIndexAliasEntityManager())->updateRollout($created);

        $resultTransfer = (new SearchIndexAliasEntityManager())->createRollout($this->createRolloutTransfer(SearchIndexAliasConfig::STATUS_BUILDING));

        $this->assertNotNull($resultTransfer->getIdSearchIndexRollout());
    }

    /**
     * @param string $status
     */
    protected function createRolloutTransfer(string $status): SearchIndexRolloutTransfer
    {
        return (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
                    ->setStoreName(static::TEST_STORE_NAME)
                    ->setAliasName('phpunit_alias'),
            )
            ->setStatus($status)
            ->setLiveIndexName('phpunit_alias_live');
    }
}
