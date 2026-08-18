<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Communication\SearchIndexAliasCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
abstract class AbstractScopeController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_INDEX = '/search-index-alias/index';

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

    /**
     * Only ever accepts a same-site relative path -- this value round-trips through a hidden form field,
     * so treat it the same as any other client-controllable input rather than trusting it outright (an
     * open-redirect via `//evil.example` or `https://...` is the classic failure mode for this pattern).
     *
     * @param string $redirectTo
     */
    protected function resolveRedirectUrl(string $redirectTo): string
    {
        if ($redirectTo === '' || !str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')) {
            return static::URL_INDEX;
        }

        return $redirectTo;
    }
}
