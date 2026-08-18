<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\MappingDiff;

use Codeception\Test\Unit;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifier;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group MappingDiff
 * @group MappingDiffClassifierTest
 * Add your own group annotations below this line
 * @group Portable
 */
class MappingDiffClassifierTest extends Unit
{
    public function testClassifyReturnsNoneForIdenticalMappings(): void
    {
        $mapping = ['properties' => ['sku' => ['type' => 'keyword']]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($mapping, $mapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_NONE, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame([], $searchIndexMappingDiffTransfer->getAddedFields());
        $this->assertSame([], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyReturnsAdditiveForANewFieldOnTheTarget(): void
    {
        $liveMapping = ['properties' => ['sku' => ['type' => 'keyword']]];
        $targetMapping = ['properties' => ['sku' => ['type' => 'keyword'], 'variantFacet' => ['type' => 'nested']]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['variantFacet'], $searchIndexMappingDiffTransfer->getAddedFields());
        $this->assertSame([], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyReturnsBreakingForARetypedField(): void
    {
        $liveMapping = ['properties' => ['variantFacet' => ['type' => 'object']]];
        $targetMapping = ['properties' => ['variantFacet' => ['type' => 'nested']]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['variantFacet'], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyReturnsBreakingForAFieldRemovedFromTheTarget(): void
    {
        $liveMapping = ['properties' => ['sku' => ['type' => 'keyword'], 'legacyField' => ['type' => 'text']]];
        $targetMapping = ['properties' => ['sku' => ['type' => 'keyword']]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['legacyField'], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyPrefersBreakingOverAdditiveWhenBothArePresent(): void
    {
        $liveMapping = ['properties' => ['sku' => ['type' => 'keyword']]];
        $targetMapping = ['properties' => ['sku' => ['type' => 'integer'], 'newField' => ['type' => 'text']]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['sku'], $searchIndexMappingDiffTransfer->getBreakingFields());
        $this->assertSame(['newField'], $searchIndexMappingDiffTransfer->getAddedFields());
    }

    public function testClassifyRecursesIntoNestedPropertiesAndFindsANoneAtDepth(): void
    {
        $mapping = [
            'properties' => [
                'variantFacet' => [
                    'type' => 'nested',
                    'properties' => [
                        'color' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($mapping, $mapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_NONE, $searchIndexMappingDiffTransfer->getClassification());
    }

    public function testClassifyRecursesIntoNestedPropertiesAndFindsABreakingRetypeAtDepth(): void
    {
        $liveMapping = [
            'properties' => [
                'variantFacet' => [
                    'type' => 'nested',
                    'properties' => [
                        'color' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ];
        $targetMapping = [
            'properties' => [
                'variantFacet' => [
                    'type' => 'nested',
                    'properties' => [
                        'color' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_BREAKING, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['variantFacet.color'], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyDoesNotRecurseFurtherIntoARetypedFieldsSubStructure(): void
    {
        // Once a field itself is retyped, its sub-structure comparison is meaningless -- the retype
        // alone must be reported, not also spurious "breaking" entries for its former children.
        $liveMapping = [
            'properties' => [
                'variantFacet' => [
                    'type' => 'object',
                    'properties' => [
                        'color' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ];
        $targetMapping = [
            'properties' => [
                'variantFacet' => [
                    'type' => 'nested',
                    'properties' => [
                        'size' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(['variantFacet'], $searchIndexMappingDiffTransfer->getBreakingFields());
    }

    public function testClassifyTreatsAMissingLivePropertiesKeyAsAnEmptyMapping(): void
    {
        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify([], ['properties' => ['sku' => ['type' => 'keyword']]]);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE, $searchIndexMappingDiffTransfer->getClassification());
        $this->assertSame(['sku'], $searchIndexMappingDiffTransfer->getAddedFields());
    }

    public function testClassifyDoesNotTreatAFieldWithNoDeclaredTypeOnEitherSideAsRetyped(): void
    {
        // A field can validly have no "type" key of its own (e.g. an object inferred purely from its
        // "properties") on both sides -- absence of type on both sides must not be misread as a retype.
        $liveMapping = ['properties' => ['meta' => ['properties' => ['a' => ['type' => 'keyword']]]]];
        $targetMapping = ['properties' => ['meta' => ['properties' => ['a' => ['type' => 'keyword']]]]];

        $searchIndexMappingDiffTransfer = (new MappingDiffClassifier())->classify($liveMapping, $targetMapping);

        $this->assertSame(SearchIndexAliasConfig::MAPPING_DIFF_NONE, $searchIndexMappingDiffTransfer->getClassification());
    }
}
