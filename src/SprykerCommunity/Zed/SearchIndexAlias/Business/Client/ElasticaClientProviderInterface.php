<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Client;

use Elastica\Client;

/**
 * @see \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider for why this exists
 * instead of going through the Client-layer `Client\Search`/`Client\Catalog` facades.
 */
interface ElasticaClientProviderInterface
{
    public function getClient(): Client;
}
