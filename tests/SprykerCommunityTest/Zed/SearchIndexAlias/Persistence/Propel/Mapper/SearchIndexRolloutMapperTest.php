<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Codeception\Test\Unit;
use DateTime;
use Generated\Shared\Transfer\SearchIndexMappingDiffTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexRolloutMapper;

/**
 * PURE mapping test — nothing here reads or writes a row, but `Orm\Zed\SearchIndexAlias\Persistence\
 * SpySearchIndexRollout` only exists once `propel:model:build` has run against a real host shop, so this
 * still needs `NeedsDatabase`, not `Portable`, even though no query actually executes.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Persistence
 * @group Propel
 * @group Mapper
 * @group SearchIndexRolloutMapperTest
 * Add your own group annotations below this line
 * @group NeedsDatabase
 */
class SearchIndexRolloutMapperTest extends Unit
{
    public function testMapTransferToEntityCopiesTheFlatScopeFieldsFromTheNestedScopeTransfer(): void
    {
        $searchIndexRolloutTransfer = (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier('page')
                    ->setStoreName('DE')
                    ->setAliasName('myshop_de_page'),
            )
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);

        $spySearchIndexRollout = (new SearchIndexRolloutMapper())->mapTransferToEntity($searchIndexRolloutTransfer, new SpySearchIndexRollout());

        $this->assertSame('page', $spySearchIndexRollout->getSourceIdentifier());
        $this->assertSame('DE', $spySearchIndexRollout->getStoreName());
        $this->assertSame('myshop_de_page', $spySearchIndexRollout->getAliasName());
        $this->assertSame(SearchIndexAliasConfig::STATUS_BUILDING, $spySearchIndexRollout->getStatus());
    }

    public function testMapTransferToEntityDefaultsFlipPendingToFalseWhenTheTransferLeavesItUnset(): void
    {
        $spySearchIndexRollout = (new SearchIndexRolloutMapper())->mapTransferToEntity($this->createMinimalTransfer(), new SpySearchIndexRollout());

        $this->assertFalse($spySearchIndexRollout->getFlipPending());
    }

    public function testMapTransferToEntityCopiesFlipPendingWhenSet(): void
    {
        $searchIndexRolloutTransfer = $this->createMinimalTransfer()->setFlipPending(true);

        $spySearchIndexRollout = (new SearchIndexRolloutMapper())->mapTransferToEntity($searchIndexRolloutTransfer, new SpySearchIndexRollout());

        $this->assertTrue($spySearchIndexRollout->getFlipPending());
    }

    public function testMapEntityToTransferCopiesFlipPending(): void
    {
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_READY)
            ->setFlipPending(true);

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $this->assertTrue($searchIndexRolloutTransfer->getFlipPending());
    }

    public function testMapTransferToEntityJsonEncodesTheMappingDiffFieldLists(): void
    {
        $searchIndexRolloutTransfer = $this->createMinimalTransfer()
            ->setSearchIndexMappingDiff(
                (new SearchIndexMappingDiffTransfer())
                    ->setClassification(SearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE)
                    ->setAddedFields(['variantFacet'])
                    ->setBreakingFields([]),
            );

        $spySearchIndexRollout = (new SearchIndexRolloutMapper())->mapTransferToEntity($searchIndexRolloutTransfer, new SpySearchIndexRollout());

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE, $spySearchIndexRollout->getMappingDiffClassification());
        $this->assertSame('["variantFacet"]', $spySearchIndexRollout->getMappingDiffAddedFields());
        $this->assertSame('[]', $spySearchIndexRollout->getMappingDiffBreakingFields());
    }

    public function testMapTransferToEntityLeavesMappingDiffColumnsUntouchedWhenTheTransferHasNoMappingDiff(): void
    {
        $spySearchIndexRollout = (new SearchIndexRolloutMapper())->mapTransferToEntity($this->createMinimalTransfer(), new SpySearchIndexRollout());

        $this->assertNull($spySearchIndexRollout->getMappingDiffClassification());
    }

    public function testMapEntityToTransferCopiesTheFlatScopeFieldsIntoANestedScopeTransfer(): void
    {
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_FLIPPED);

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();
        $this->assertSame('page', $searchIndexScopeTransfer->getSourceIdentifier());
        $this->assertSame('DE', $searchIndexScopeTransfer->getStoreName());
        $this->assertSame('myshop_de_page', $searchIndexScopeTransfer->getAliasName());
    }

    public function testMapEntityToTransferJsonDecodesTheMappingDiffFieldLists(): void
    {
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING)
            ->setMappingDiffClassification(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING)
            ->setMappingDiffAddedFields('[]')
            ->setMappingDiffBreakingFields('["legacyField","variantFacet.color"]');

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $searchIndexMappingDiffTransfer = $searchIndexRolloutTransfer->getSearchIndexMappingDiff();
        $this->assertNotNull($searchIndexMappingDiffTransfer);
        $this->assertSame(['legacyField', 'variantFacet.color'], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testMapEntityToTransferLeavesMappingDiffUnsetWhenTheEntityHasNoClassification(): void
    {
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $this->assertNull($searchIndexRolloutTransfer->getSearchIndexMappingDiff());
    }

    public function testMapEntityToTransferFormatsTimestampsAsAtomStrings(): void
    {
        $startedAt = new DateTime('2026-01-01 12:00:00');
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING)
            ->setStartedAt($startedAt);

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $this->assertSame($startedAt->format(DATE_ATOM), $searchIndexRolloutTransfer->getStartedAt());
    }

    public function testMapEntityToTransferLeavesFinishedAtNullWhenTheEntityHasNoFinishTime(): void
    {
        $spySearchIndexRollout = (new SpySearchIndexRollout())
            ->setSourceIdentifier('page')
            ->setStoreName('DE')
            ->setAliasName('myshop_de_page')
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);

        $searchIndexRolloutTransfer = (new SearchIndexRolloutMapper())->mapEntityToTransfer($spySearchIndexRollout, new SearchIndexRolloutTransfer());

        $this->assertNull($searchIndexRolloutTransfer->getFinishedAt());
    }

    protected function createMinimalTransfer(): SearchIndexRolloutTransfer
    {
        return (new SearchIndexRolloutTransfer())
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier('page')
                    ->setStoreName('DE')
                    ->setAliasName('myshop_de_page'),
            )
            ->setStatus(SearchIndexAliasConfig::STATUS_BUILDING);
    }
}
