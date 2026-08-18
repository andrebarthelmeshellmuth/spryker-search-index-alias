<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use Elastica\Request;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;

class PhysicalIndexLister implements PhysicalIndexListerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface $elasticaClientProvider
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface $indexNameBuilder
     */
    public function __construct(
        protected ElasticaClientProviderInterface $elasticaClientProvider,
        protected IndexNameBuilderInterface $indexNameBuilder,
    ) {
    }

    /**
     * @param string $aliasName
     *
     * @return array<string>
     */
    public function listIndexNamesForAlias(string $aliasName): array
    {
        return array_values(array_filter(
            $this->listAllIndexNames(),
            fn (string $indexName): bool => $this->indexNameBuilder->belongsToAlias($indexName, $aliasName),
        ));
    }

    /**
     * @return array<string>
     */
    protected function listAllIndexNames(): array
    {
        $response = $this->elasticaClientProvider->getClient()->request('_cat/indices', Request::GET, [], ['format' => 'json', 'h' => 'index']);

        return array_column($response->getData(), 'index');
    }
}
