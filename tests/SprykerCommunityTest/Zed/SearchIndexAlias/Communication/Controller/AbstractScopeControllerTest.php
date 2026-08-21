<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Controller;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Service\Container\ContainerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller\AbstractScopeController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * PORTABLE — requireValidForm() and resolveScopeOrRedirect() are the two guard clauses every scope action
 * in RolloutController/DeployFlagController is built on (see those classes and their own docblocks). Both
 * end up calling addErrorMessage()/redirectResponse() from the Spryker Kernel base, which need an
 * `Application`/Container to be set — a plain mock satisfying `has()`/`get()` is enough to exercise the
 * real logic without a host shop, since neither guard clause does anything with the container beyond what
 * that base class already does for every controller action across this package.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Controller
 * @group AbstractScopeControllerTest
 * Add your own group annotations below this line
 * @group Portable
 */
class AbstractScopeControllerTest extends Unit
{
    public function testRequireValidFormReturnsNullWhenSubmittedAndValid(): void
    {
        $formMock = $this->createMock(FormInterface::class);
        $formMock->method('isSubmitted')->willReturn(true);
        $formMock->method('isValid')->willReturn(true);

        $this->assertNull($this->requireValidForm($formMock));
    }

    public function testRequireValidFormRedirectsToIndexWhenNotSubmitted(): void
    {
        $formMock = $this->createMock(FormInterface::class);
        $formMock->method('isSubmitted')->willReturn(false);
        $formMock->method('isValid')->willReturn(true);

        $redirectResponse = $this->requireValidForm($formMock);

        $this->assertInstanceOf(RedirectResponse::class, $redirectResponse);
        $this->assertSame('/search-index-alias/index', $redirectResponse->getTargetUrl());
    }

    public function testRequireValidFormRedirectsToIndexWhenSubmittedButInvalid(): void
    {
        $formMock = $this->createMock(FormInterface::class);
        $formMock->method('isSubmitted')->willReturn(true);
        $formMock->method('isValid')->willReturn(false);

        $redirectResponse = $this->requireValidForm($formMock);

        $this->assertInstanceOf(RedirectResponse::class, $redirectResponse);
        $this->assertSame('/search-index-alias/index', $redirectResponse->getTargetUrl());
    }

    public function testResolveScopeOrRedirectReturnsTheScopeWhenFound(): void
    {
        $searchIndexScopeTransfer = (new SearchIndexScopeTransfer())->setAliasName('page-DE');

        $result = $this->resolveScopeOrRedirect('page-DE', [$searchIndexScopeTransfer], '/search-index-alias/index?source=page');

        $this->assertSame($searchIndexScopeTransfer, $result);
    }

    public function testResolveScopeOrRedirectRedirectsToTheGivenUrlWhenNotFound(): void
    {
        $result = $this->resolveScopeOrRedirect('unknown-alias', [], '/search-index-alias/index?source=page');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/search-index-alias/index?source=page', $result->getTargetUrl());
    }

    protected function requireValidForm(FormInterface $form): ?RedirectResponse
    {
        return $this->buildScopeController([])->exposeRequireValidForm($form);
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     */
    protected function resolveScopeOrRedirect(string $aliasName, array $managedScopes, string $redirectUrl): SearchIndexScopeTransfer|RedirectResponse
    {
        return $this->buildScopeController($managedScopes)->exposeResolveScopeOrRedirect($aliasName, $redirectUrl);
    }

    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $managedScopes
     */
    protected function buildScopeController(array $managedScopes): AbstractScopeController
    {
        $facadeMock = $this->createMock(SearchIndexAliasFacadeInterface::class);
        $facadeMock->method('getManagedScopes')->willReturn($managedScopes);

        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('has')->willReturn(false);

        $scopeController = new class extends AbstractScopeController {
            /**
             * @var \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface
             */
            public $injectedFacade;

            public function exposeRequireValidForm(FormInterface $form): ?RedirectResponse
            {
                return $this->requireValidForm($form);
            }

            public function exposeResolveScopeOrRedirect(string $aliasName, string $redirectUrl): SearchIndexScopeTransfer|RedirectResponse
            {
                return $this->resolveScopeOrRedirect($aliasName, $redirectUrl);
            }

            protected function getFacade(): SearchIndexAliasFacadeInterface
            {
                return $this->injectedFacade;
            }
        };

        $scopeController->injectedFacade = $facadeMock;
        $scopeController->setApplication($containerMock);

        return $scopeController;
    }
}
