<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade;

interface SearchIndexAliasToTranslatorFacadeInterface
{
    /**
     * @param string $keyName
     * @param string $locale
     */
    public function has(string $keyName, string $locale): bool;
}
