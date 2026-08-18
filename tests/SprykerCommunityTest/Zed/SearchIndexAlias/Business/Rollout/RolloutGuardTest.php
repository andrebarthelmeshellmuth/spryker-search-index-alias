<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rollout;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;

/**
 * INTEGRATION TEST — real database. This is the application-level courtesy check (the real enforcement
 * is the DB's own UNIQUE index, covered by {@see \SprykerCommunityTest\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerTest}) —
 * what's worth protecting here is that a real active row is correctly detected/ignored based on status.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rollout
 * @group RolloutGuardTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 */
class RolloutGuardTest extends Unit
{
    /**
     * @var string
     */
    protected const TEST_SOURCE_IDENTIFIER = 'phpunit_guard_source';

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

    public function testAssertNoActiveRolloutDoesNotThrowWhenNoRolloutExistsForTheScope(): void
    {
        $this->expectNotToPerformAssertions();

        (new RolloutGuard(new SearchIndexAliasRepository()))->assertNoActiveRollout($this->createScope());
    }

    public function testAssertNoActiveRolloutDoesNotThrowWhenOnlyTerminalRolloutsExist(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_FLIPPED);

        $this->expectNotToPerformAssertions();

        (new RolloutGuard(new SearchIndexAliasRepository()))->assertNoActiveRollout($this->createScope());
    }

    public function testAssertNoActiveRolloutThrowsWhenANonTerminalRolloutExistsForTheScope(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_BUILDING);

        $this->expectException(ConcurrentRolloutException::class);

        (new RolloutGuard(new SearchIndexAliasRepository()))->assertNoActiveRollout($this->createScope());
    }

    public function testAssertNoActiveRolloutExceptionMessageNamesTheOffendingScope(): void
    {
        $this->seedRollout(SearchIndexAliasConfig::STATUS_BUILDING);

        try {
            (new RolloutGuard(new SearchIndexAliasRepository()))->assertNoActiveRollout($this->createScope());
            $this->fail('Expected ConcurrentRolloutException was not thrown.');
        } catch (ConcurrentRolloutException $concurrentRolloutException) {
            $this->assertStringContainsString('phpunit_guard_alias', $concurrentRolloutException->getMessage());
            $this->assertStringContainsString(SearchIndexAliasConfig::STATUS_BUILDING, $concurrentRolloutException->getMessage());
        }
    }

    protected function createScope(): SearchIndexScopeTransfer
    {
        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier(static::TEST_SOURCE_IDENTIFIER)
            ->setStoreName('PHPUNIT')
            ->setAliasName('phpunit_guard_alias');
    }

    protected function seedRollout(string $status): SearchIndexRolloutTransfer
    {
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope($this->createScope())
            ->setStatus($status);

        return (new SearchIndexAliasEntityManager())->createRollout($searchIndexRolloutTransfer);
    }
}
