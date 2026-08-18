<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Alias;

use Elastica\Exception\ResponseException;
use Elastica\Request;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasNameCollisionException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException;

/**
 * The only class in this package that ever writes to `_aliases`. Every mutation is a single atomic
 * `_aliases` actions call -- see the interface's own doc blocks for the two spikes (atomic switch, and
 * the `remove_index` first-adoption trick) this class's design is derived from, both verified live
 * against OpenSearch 1.3.4.
 */
class AliasManager implements AliasManagerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface $elasticaClientProvider
     */
    public function __construct(protected ElasticaClientProviderInterface $elasticaClientProvider)
    {
    }

    /**
     * @param string $aliasName
     * @param string $indexName
     */
    public function createAlias(string $aliasName, string $indexName): void
    {
        $this->executeAliasActions([
            ['add' => ['index' => $indexName, 'alias' => $aliasName]],
        ], sprintf('create alias "%s" -> "%s"', $aliasName, $indexName));
    }

    /**
     * @param string $aliasName
     * @param string $fromIndexName
     * @param string $toIndexName
     */
    public function switchAlias(string $aliasName, string $fromIndexName, string $toIndexName): void
    {
        $this->executeAliasActions([
            ['remove' => ['index' => $fromIndexName, 'alias' => $aliasName]],
            ['add' => ['index' => $toIndexName, 'alias' => $aliasName]],
        ], sprintf('switch alias "%s" from "%s" to "%s"', $aliasName, $fromIndexName, $toIndexName));
    }

    /**
     * @param string $aliasName
     * @param string $toIndexName
     */
    public function adoptConcreteIndex(string $aliasName, string $toIndexName): void
    {
        $this->executeAliasActions([
            ['remove_index' => ['index' => $aliasName]],
            ['add' => ['index' => $toIndexName, 'alias' => $aliasName]],
        ], sprintf('adopt concrete index "%s" into alias pointing at "%s"', $aliasName, $toIndexName));
    }

    /**
     * @param string $aliasName
     *
     * @return array<string>
     */
    public function getIndicesForAlias(string $aliasName): array
    {
        try {
            $response = $this->elasticaClientProvider->getClient()->request(
                sprintf('_alias/%s', $aliasName),
                Request::GET,
            );
        } catch (ResponseException) {
            // 404: alias does not exist at all -- a legitimate, expected state (not yet adopted/created).
            return [];
        }

        return array_keys($response->getData());
    }

    /**
     * @param string $indexName
     */
    public function indexExists(string $indexName): bool
    {
        return $this->elasticaClientProvider->getClient()->getIndex($indexName)->exists();
    }

    /**
     * @param string $indexName
     *
     * @return array<string>
     */
    public function getAliasesForIndex(string $indexName): array
    {
        return $this->elasticaClientProvider->getClient()->getIndex($indexName)->getAliases();
    }

    /**
     * @param string $indexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    public function deleteUnaliasedIndex(string $indexName): void
    {
        $aliases = $this->getAliasesForIndex($indexName);

        if ($aliases !== []) {
            throw new AliasOperationFailedException(sprintf(
                'Refusing to delete index "%s": still aliased as %s.',
                $indexName,
                implode(', ', $aliases),
            ));
        }

        $response = $this->elasticaClientProvider->getClient()->getIndex($indexName)->delete();

        if (!$response->isOk()) {
            throw new AliasOperationFailedException(sprintf(
                'Failed to delete index "%s": %s',
                $indexName,
                $response->getErrorMessage(),
            ));
        }
    }

    /**
     * @param array<int, array<string, array<string, string>>> $actions
     * @param string $operationDescription
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasNameCollisionException
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException
     */
    protected function executeAliasActions(array $actions, string $operationDescription): void
    {
        try {
            $response = $this->elasticaClientProvider->getClient()->request(
                '_aliases',
                Request::POST,
                ['actions' => $actions],
            );
        } catch (ResponseException $responseException) {
            $errorType = $responseException->getResponse()->getFullError()['type'] ?? null;

            if ($errorType === 'invalid_alias_name_exception') {
                throw new AliasNameCollisionException(sprintf(
                    'Could not %s: a concrete (non-aliased) index already occupies that name. Use ' .
                    'adoptConcreteIndex() instead of createAlias() for first-time migration.',
                    $operationDescription,
                ), 0, $responseException);
            }

            throw new AliasOperationFailedException(
                sprintf('Could not %s: %s', $operationDescription, $responseException->getMessage()),
                0,
                $responseException,
            );
        }

        if (!$response->isOk()) {
            throw new AliasOperationFailedException(sprintf(
                'Could not %s: cluster did not acknowledge the request (%s).',
                $operationDescription,
                $response->getErrorMessage(),
            ));
        }
    }
}
