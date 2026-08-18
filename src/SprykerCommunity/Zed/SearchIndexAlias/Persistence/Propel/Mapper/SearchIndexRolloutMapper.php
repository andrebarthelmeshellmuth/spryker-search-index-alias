<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\SearchIndexMappingDiffTransfer;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout;

/**
 * Pure structural mapping only -- entity columns are flat (source_identifier/store_name/alias_name,
 * mapping_diff_classification/added_fields/breaking_fields), the transfer nests those as
 * SearchIndexScopeTransfer/SearchIndexMappingDiffTransfer. Business rules (e.g. deriving
 * `active_scope_key` from status) belong in SearchIndexAliasEntityManager, not here.
 */
class SearchIndexRolloutMapper implements SearchIndexRolloutMapperInterface
{
    /**
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout $spySearchIndexRollout
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    public function mapEntityToTransfer(
        SpySearchIndexRollout $spySearchIndexRollout,
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
    ): SearchIndexRolloutTransfer {
        $searchIndexRolloutTransfer
            ->setIdSearchIndexRollout($spySearchIndexRollout->getIdSearchIndexRollout())
            ->setStatus($spySearchIndexRollout->getStatus())
            ->setFlipPending($spySearchIndexRollout->getFlipPending())
            ->setLiveIndexName($spySearchIndexRollout->getLiveIndexName())
            ->setTargetIndexName($spySearchIndexRollout->getTargetIndexName())
            ->setMirrorQueueName($spySearchIndexRollout->getMirrorQueueName())
            ->setExpectedDocumentCount($spySearchIndexRollout->getExpectedDocumentCount())
            ->setActualDocumentCount($spySearchIndexRollout->getActualDocumentCount())
            ->setStartedAt($spySearchIndexRollout->getStartedAt()?->format(DATE_ATOM))
            ->setFinishedAt($spySearchIndexRollout->getFinishedAt()?->format(DATE_ATOM))
            ->setTriggeredByUser($spySearchIndexRollout->getTriggeredByUser())
            ->setOutcome($spySearchIndexRollout->getOutcome())
            ->setFailureReason($spySearchIndexRollout->getFailureReason());

        $searchIndexRolloutTransfer->setSearchIndexScope(
            (new SearchIndexScopeTransfer())
                ->setSourceIdentifier($spySearchIndexRollout->getSourceIdentifier())
                ->setStoreName($spySearchIndexRollout->getStoreName())
                ->setAliasName($spySearchIndexRollout->getAliasName()),
        );

        if ($spySearchIndexRollout->getMappingDiffClassification() !== null) {
            $searchIndexRolloutTransfer->setSearchIndexMappingDiff(
                (new SearchIndexMappingDiffTransfer())
                    ->setClassification($spySearchIndexRollout->getMappingDiffClassification())
                    ->setAddedFields($this->decodeJsonList($spySearchIndexRollout->getMappingDiffAddedFields()))
                    ->setBreakingFields($this->decodeJsonList($spySearchIndexRollout->getMappingDiffBreakingFields())),
            );
        }

        return $searchIndexRolloutTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     * @param \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRollout $spySearchIndexRollout
     */
    public function mapTransferToEntity(
        SearchIndexRolloutTransfer $searchIndexRolloutTransfer,
        SpySearchIndexRollout $spySearchIndexRollout,
    ): SpySearchIndexRollout {
        $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();

        $spySearchIndexRollout
            ->setSourceIdentifier($searchIndexScopeTransfer->getSourceIdentifierOrFail())
            ->setStoreName($searchIndexScopeTransfer->getStoreNameOrFail())
            ->setAliasName($searchIndexScopeTransfer->getAliasNameOrFail())
            ->setStatus($searchIndexRolloutTransfer->getStatusOrFail())
            ->setFlipPending((bool)$searchIndexRolloutTransfer->getFlipPending())
            ->setLiveIndexName($searchIndexRolloutTransfer->getLiveIndexName())
            ->setTargetIndexName($searchIndexRolloutTransfer->getTargetIndexName())
            ->setMirrorQueueName($searchIndexRolloutTransfer->getMirrorQueueName())
            ->setExpectedDocumentCount($searchIndexRolloutTransfer->getExpectedDocumentCount())
            ->setActualDocumentCount($searchIndexRolloutTransfer->getActualDocumentCount())
            ->setStartedAt($searchIndexRolloutTransfer->getStartedAt())
            ->setFinishedAt($searchIndexRolloutTransfer->getFinishedAt())
            ->setTriggeredByUser($searchIndexRolloutTransfer->getTriggeredByUser())
            ->setOutcome($searchIndexRolloutTransfer->getOutcome())
            ->setFailureReason($searchIndexRolloutTransfer->getFailureReason());

        $searchIndexMappingDiffTransfer = $searchIndexRolloutTransfer->getSearchIndexMappingDiff();

        if ($searchIndexMappingDiffTransfer !== null) {
            $spySearchIndexRollout
                ->setMappingDiffClassification($searchIndexMappingDiffTransfer->getClassification())
                ->setMappingDiffAddedFields($this->encodeJsonList($searchIndexMappingDiffTransfer->getAddedFields()))
                ->setMappingDiffBreakingFields($this->encodeJsonList($searchIndexMappingDiffTransfer->getBreakingFields()));
        }

        return $spySearchIndexRollout;
    }

    /**
     * @param string|null $json
     *
     * @return array<string>
     */
    protected function decodeJsonList(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string> $list
     */
    protected function encodeJsonList(array $list): string
    {
        return json_encode($list) ?: '[]';
    }
}
