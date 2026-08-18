<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Index;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Index
 * @group IndexNameBuilderTest
 * Add your own group annotations below this line
 * @group Portable
 */
class IndexNameBuilderTest extends Unit
{
    public function testBuildTargetIndexNameAppendsAnUnderscoreDelimitedTimestampToTheAliasName(): void
    {
        $indexName = (new IndexNameBuilder(new SearchIndexAliasConfig()))->buildTargetIndexName('myshop_de_page');

        $this->assertMatchesRegularExpression('/^myshop_de_page_\d{8}_\d{6}$/', $indexName);
    }

    public function testBuildTargetIndexNamePreservesTheAliasNameVerbatim(): void
    {
        $indexName = (new IndexNameBuilder(new SearchIndexAliasConfig()))->buildTargetIndexName('myshop_de_page');

        $this->assertStringStartsWith('myshop_de_page_', $indexName);
    }

    public function testBelongsToAliasAcceptsAGenuineTargetIndexName(): void
    {
        $indexNameBuilder = new IndexNameBuilder(new SearchIndexAliasConfig());
        $indexName = $indexNameBuilder->buildTargetIndexName('myshop_de_page');

        $this->assertTrue($indexNameBuilder->belongsToAlias($indexName, 'myshop_de_page'));
    }

    public function testBelongsToAliasRejectsAnIndexBelongingToADifferentAlias(): void
    {
        $indexNameBuilder = new IndexNameBuilder(new SearchIndexAliasConfig());
        $indexName = $indexNameBuilder->buildTargetIndexName('myshop_de_merchant');

        $this->assertFalse($indexNameBuilder->belongsToAlias($indexName, 'myshop_de_page'));
    }

    public function testBelongsToAliasRejectsAnAliasNameThatIsAPrefixOfAnotherAlias(): void
    {
        // "myshop_de_page" must not match "myshop_de_pages_20260101_120000" — a naive substring/prefix
        // check would wrongly accept this; the anchored regex must not.
        $indexNameBuilder = new IndexNameBuilder(new SearchIndexAliasConfig());

        $this->assertFalse($indexNameBuilder->belongsToAlias('myshop_de_pages_20260101_120000', 'myshop_de_page'));
    }

    public function testBelongsToAliasRejectsTheConcreteAliasNameItself(): void
    {
        $indexNameBuilder = new IndexNameBuilder(new SearchIndexAliasConfig());

        $this->assertFalse($indexNameBuilder->belongsToAlias('myshop_de_page', 'myshop_de_page'));
    }

    public function testBelongsToAliasRejectsAMalformedTimestampSuffix(): void
    {
        $indexNameBuilder = new IndexNameBuilder(new SearchIndexAliasConfig());

        $this->assertFalse($indexNameBuilder->belongsToAlias('myshop_de_page_not_a_timestamp', 'myshop_de_page'));
    }
}
