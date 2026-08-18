<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Index;

use Codeception\Test\Unit;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Zed\Store\Business\StoreFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumerator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToStoreFacadeBridge;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real host-shop Store facade + real search config, so it enumerates this project's
 * OWN actually-configured stores/source identifiers, not a fake fixture. `enumerateScopes()`'s whole
 * point (per its own docblock) is to reflect the host project's real config, so testing it against
 * anything less than the real config would not prove the thing that matters.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Index
 * @group IndexEnumeratorTest
 * Add your own group annotations below this line
 * @group NeedsProject
 */
class IndexEnumeratorTest extends Unit
{
    public function testEnumerateScopesReturnsAtLeastOneRealScopeForThisProject(): void
    {
        // This project genuinely has a "page" source identifier configured for at least one real store
        // (verified throughout this whole engagement's live testing) -- a real, non-empty result here is
        // the one thing worth asserting without hardcoding every store/source combination this project
        // happens to have today.
        $scopes = $this->createIndexEnumerator()->enumerateScopes();

        $this->assertNotEmpty($scopes);

        $pageScopes = array_filter($scopes, fn ($searchIndexScopeTransfer): bool => $searchIndexScopeTransfer->getSourceIdentifier() === 'page');
        $this->assertNotEmpty($pageScopes, 'Expected at least one "page" scope, matching this project\'s real search config.');
    }

    public function testFindScopeReturnsAMatchingScopeForARealSupportedCombination(): void
    {
        $searchIndexScopeTransfer = $this->createIndexEnumerator()->findScope('page', 'DE');

        $this->assertNotNull($searchIndexScopeTransfer);
        $this->assertSame('page', $searchIndexScopeTransfer->getSourceIdentifier());
        $this->assertSame('DE', $searchIndexScopeTransfer->getStoreName());
        $this->assertNotNull($searchIndexScopeTransfer->getAliasName());
    }

    public function testFindScopeReturnsNullForACombinationThisProjectDoesNotSupport(): void
    {
        $searchIndexScopeTransfer = $this->createIndexEnumerator()->findScope('definitely_not_a_real_source_identifier', 'DE');

        $this->assertNull($searchIndexScopeTransfer);
    }

    protected function createIndexEnumerator(): IndexEnumerator
    {
        return new IndexEnumerator(
            new SearchIndexAliasToStoreFacadeBridge(new StoreFacade()),
            new CanonicalIndexNameResolver(new SearchElasticsearchConfig()),
            new SearchElasticsearchConfig(),
            new SearchIndexAliasConfig(),
        );
    }
}
