<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
     * Every scope-acting form in this package (adopt/rebuild/flip/abort/rollback/flag-toggle) is CSRF-only
     * -- no real field validation beyond that -- so "submitted and valid" failing always means the same
     * thing: bounce back to the index with the same error message.
     *
     * @param \Symfony\Component\Form\FormInterface $form
     */
    protected function requireValidForm(FormInterface $form): ?RedirectResponse
    {
        if ($form->isSubmitted() && $form->isValid()) {
            return null;
        }

        $this->addErrorMessage('CSRF token is not valid.');

        return $this->redirectResponse(static::URL_INDEX);
    }

    /**
     * @param string $aliasName
     * @param string $redirectUrl
     */
    protected function resolveScopeOrRedirect(string $aliasName, string $redirectUrl): SearchIndexScopeTransfer|RedirectResponse
    {
        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer !== null) {
            return $searchIndexScopeTransfer;
        }

        $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

        return $this->redirectResponse($redirectUrl);
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
