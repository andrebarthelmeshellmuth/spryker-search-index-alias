<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class IndexNameBuilder implements IndexNameBuilderInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $config
     */
    public function __construct(protected SearchIndexAliasConfig $config)
    {
    }

    /**
     * @param string $aliasName
     */
    public function buildTargetIndexName(string $aliasName): string
    {
        return sprintf(
            '%s_%s',
            $aliasName,
            date(SearchIndexAliasConfig::TIMESTAMP_FORMAT),
        );
    }

    /**
     * @param string $indexName
     * @param string $aliasName
     */
    public function belongsToAlias(string $indexName, string $aliasName): bool
    {
        return (bool)preg_match(
            sprintf('/^%s_\d{8}_\d{6}$/', preg_quote($aliasName, '/')),
            $indexName,
        );
    }
}
