<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption;

use Elastica\Exception\ResponseException;
use Elastica\Request;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException;

class IndexCloner implements IndexClonerInterface
{
    /**
     * Cluster-assigned/lifecycle settings that must NOT be copied onto a new index -- `uuid` and
     * `creation_date` are per-index identities, `provided_name` is derived from the index name itself
     * (and would otherwise claim the SOURCE's name inside the target's own settings), `version` is
     * written by the cluster on creation, and `resize`/`routing` reflect the source's own shard
     * allocation history, not something a fresh index should inherit. Confirmed necessary live: creating
     * an index from an unfiltered settings copy fails outright (Elasticsearch rejects several of these
     * as read-only/cluster-managed).
     *
     * @var array<string>
     */
    protected const NON_COPYABLE_SETTINGS = [
        'provided_name',
        'uuid',
        'creation_date',
        'version',
        'resize',
        'routing',
    ];

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface $elasticaClientProvider
     */
    public function __construct(protected ElasticaClientProviderInterface $elasticaClientProvider)
    {
    }

    /**
     * @param string $sourceIndexName
     * @param string $targetIndexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    public function cloneMappingAndSettings(string $sourceIndexName, string $targetIndexName): void
    {
        try {
            $mapping = $this->fetchMapping($sourceIndexName);
            $settings = $this->fetchFilteredSettings($sourceIndexName);
        } catch (ResponseException $responseException) {
            throw new IndexCloneFailedException(sprintf(
                'Could not read mapping/settings from "%s" to clone into "%s": %s',
                $sourceIndexName,
                $targetIndexName,
                $responseException->getMessage(),
            ), 0, $responseException);
        }

        $this->createIndexWithMappingAndSettings($targetIndexName, $mapping, $settings);
    }

    /**
     * @param string $targetIndexName
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $settings
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    public function createIndexWithMappingAndSettings(string $targetIndexName, array $mapping, array $settings): void
    {
        try {
            $response = $this->elasticaClientProvider->getClient()->request(
                $targetIndexName,
                Request::PUT,
                [
                    'settings' => ['index' => $settings],
                    'mappings' => $mapping,
                ],
            );
        } catch (ResponseException $responseException) {
            throw new IndexCloneFailedException(sprintf(
                'Could not create "%s": %s',
                $targetIndexName,
                $responseException->getMessage(),
            ), 0, $responseException);
        }

        if (!$response->isOk()) {
            throw new IndexCloneFailedException(sprintf(
                'Could not create "%s": cluster did not acknowledge the request (%s).',
                $targetIndexName,
                $response->getErrorMessage(),
            ));
        }
    }

    /**
     * @param string $sourceIndexName
     * @param string $targetIndexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    public function reindexInto(string $sourceIndexName, string $targetIndexName): int
    {
        try {
            // refresh=true on the _reindex call itself: without it, the target's newly-written
            // documents are not necessarily visible to a _count/_search run immediately afterwards --
            // confirmed live, this caused a spurious convergence-check failure in IndexAdopter even
            // though the reindex itself had fully succeeded.
            $response = $this->elasticaClientProvider->getClient()->request(
                '_reindex',
                Request::POST,
                [
                    'source' => ['index' => $sourceIndexName],
                    'dest' => ['index' => $targetIndexName],
                ],
                ['refresh' => 'true'],
            );
        } catch (ResponseException $responseException) {
            throw new IndexCloneFailedException(sprintf(
                'Reindex from "%s" into "%s" failed: %s',
                $sourceIndexName,
                $targetIndexName,
                $responseException->getMessage(),
            ), 0, $responseException);
        }

        $data = $response->getData();

        if (!empty($data['failures'])) {
            throw new IndexCloneFailedException(sprintf(
                'Reindex from "%s" into "%s" reported %d failure(s): %s',
                $sourceIndexName,
                $targetIndexName,
                count($data['failures']),
                json_encode($data['failures']),
            ));
        }

        return (int)($data['created'] ?? 0);
    }

    /**
     * @param string $indexName
     */
    public function getDocumentCount(string $indexName): int
    {
        $response = $this->elasticaClientProvider->getClient()->request(
            sprintf('%s/_count', $indexName),
            Request::GET,
        );

        return (int)($response->getData()['count'] ?? 0);
    }

    /**
     * @param string $indexName
     * @param array<string, mixed> $mappingProperties
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    public function applyMapping(string $indexName, array $mappingProperties): void
    {
        try {
            $response = $this->elasticaClientProvider->getClient()->request(
                sprintf('%s/_mapping', $indexName),
                Request::PUT,
                $mappingProperties,
            );
        } catch (ResponseException $responseException) {
            throw new IndexCloneFailedException(sprintf(
                'Could not apply mapping update to "%s": %s',
                $indexName,
                $responseException->getMessage(),
            ), 0, $responseException);
        }

        if (!$response->isOk()) {
            throw new IndexCloneFailedException(sprintf(
                'Could not apply mapping update to "%s": cluster did not acknowledge the request (%s).',
                $indexName,
                $response->getErrorMessage(),
            ));
        }
    }

    /**
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    public function getMapping(string $indexName): array
    {
        return $this->fetchMapping($indexName);
    }

    /**
     * Standard ES/OS bulk-load practice: disable automatic refresh and replicas for the duration of a
     * large load, restore both afterward -- amortizes real overhead that's invisible on a small catalog
     * but real at hundreds of thousands of documents (every batch otherwise competes with a background
     * refresh roughly once a second, and every write is replicated as it happens rather than once at the
     * end). Explicit `_refresh` calls (see `IndexCloner`/`BulkLoader`'s own final refresh) work
     * regardless of this setting -- it only controls the automatic periodic one.
     *
     * Returns the settings actually read from the cluster before changing them, not a guessed default,
     * so the caller can restore the target to exactly what `cloneMappingAndSettings()` gave it.
     *
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    public function disableRefreshAndReplicasForBulkLoad(string $indexName): array
    {
        $currentSettings = $this->fetchFilteredSettings($indexName);
        $previousSettings = [
            'refresh_interval' => $currentSettings['refresh_interval'] ?? '1s',
            'number_of_replicas' => $currentSettings['number_of_replicas'] ?? '1',
        ];

        $this->putSettings($indexName, [
            'refresh_interval' => '-1',
            'number_of_replicas' => '0',
        ]);

        return $previousSettings;
    }

    /**
     * @param string $indexName
     * @param array<string, mixed> $settings
     */
    public function restoreSettings(string $indexName, array $settings): void
    {
        $this->putSettings($indexName, $settings);
    }

    /**
     * @param string $indexName
     * @param array<string, mixed> $settings
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    protected function putSettings(string $indexName, array $settings): void
    {
        try {
            $response = $this->elasticaClientProvider->getClient()->request(
                sprintf('%s/_settings', $indexName),
                Request::PUT,
                ['index' => $settings],
            );
        } catch (ResponseException $responseException) {
            throw new IndexCloneFailedException(sprintf(
                'Could not update settings on "%s": %s',
                $indexName,
                $responseException->getMessage(),
            ), 0, $responseException);
        }

        if (!$response->isOk()) {
            throw new IndexCloneFailedException(sprintf(
                'Could not update settings on "%s": cluster did not acknowledge the request (%s).',
                $indexName,
                $response->getErrorMessage(),
            ));
        }
    }

    /**
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    protected function fetchMapping(string $indexName): array
    {
        $response = $this->elasticaClientProvider->getClient()->request(
            sprintf('%s/_mapping', $indexName),
            Request::GET,
        );

        $data = $response->getData();

        return $data[$indexName]['mappings'] ?? [];
    }

    /**
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    public function getFilteredSettings(string $indexName): array
    {
        return $this->fetchFilteredSettings($indexName);
    }

    /**
     * @param string $indexName
     *
     * @return array<string, mixed>
     */
    protected function fetchFilteredSettings(string $indexName): array
    {
        $response = $this->elasticaClientProvider->getClient()->request(
            sprintf('%s/_settings', $indexName),
            Request::GET,
        );

        $data = $response->getData();
        $settings = $data[$indexName]['settings']['index'] ?? [];

        foreach (static::NON_COPYABLE_SETTINGS as $nonCopyableSetting) {
            unset($settings[$nonCopyableSetting]);
        }

        return $settings;
    }
}
