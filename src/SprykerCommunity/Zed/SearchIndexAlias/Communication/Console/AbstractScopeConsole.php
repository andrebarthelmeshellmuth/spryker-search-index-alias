<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\Kernel\Communication\Console\Console;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
abstract class AbstractScopeConsole extends Console
{
    /**
     * @var string
     */
    public const ARGUMENT_ALIAS = 'alias';

    /**
     * @param string $aliasName
     */
    protected function findScopeByAlias(string $aliasName): ?SearchIndexScopeTransfer
    {
        foreach ($this->getFacade()->getManagedScopes() as $searchIndexScopeTransfer) {
            if ($searchIndexScopeTransfer->getAliasName() === $aliasName) {
                return $searchIndexScopeTransfer;
            }
        }

        return null;
    }
}
