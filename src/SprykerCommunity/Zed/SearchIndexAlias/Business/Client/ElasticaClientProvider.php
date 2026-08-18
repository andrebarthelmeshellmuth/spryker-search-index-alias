<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Client;

use Elastica\Client;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;

/**
 * Builds the same `Elastica\Client` core's own Zed `SearchElasticsearchBusinessFactory::getElasticsearchClient()`
 * builds -- same factory class (`Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory`,
 * a Shared-layer class explicitly meant to be reused, not an internal), same client config
 * (`SearchElasticsearchConfig::getClientConfig()`). No HTTP/session context involved anywhere in this
 * path -- this is the fully Zed-native connection, distinct from the Client-layer `Client\Search`/
 * `Client\Catalog` facades that crash when called outside a real request (see this package's README,
 * "How it works", for why that distinction matters for a console/cron-driven tool).
 *
 * `ElasticaClientFactory` caches the client on a static property, so this provider is safe to construct
 * repeatedly without opening a new connection each time.
 */
class ElasticaClientProvider implements ElasticaClientProviderInterface
{
    /**
     * @param \Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig $searchElasticsearchConfig
     */
    public function __construct(protected SearchElasticsearchConfig $searchElasticsearchConfig)
    {
    }

    public function getClient(): Client
    {
        return (new ElasticaClientFactory())->createClient(
            $this->searchElasticsearchConfig->getClientConfig(),
        );
    }
}
