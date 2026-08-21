<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RolloutNotReadyException;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\AliasScopeForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RollbackForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The deploy-time-flagging actions -- split out of RolloutController (which already covers the
 * immediate-action counterparts: flip/rollback) purely to keep both classes' size/complexity in line with
 * the rest of this package's phpmd budget. See README "Deploying" for what these do.
 */
class DeployFlagController extends AbstractScopeController
{
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function markFlipPendingAction(Request $request): RedirectResponse
    {
        return $this->handleFlipPendingToggle($request, true);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function unmarkFlipPendingAction(Request $request): RedirectResponse
    {
        return $this->handleFlipPendingToggle($request, false);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param bool $pending
     */
    protected function handleFlipPendingToggle(Request $request, bool $pending): RedirectResponse
    {
        $aliasScopeForm = $this->getFactory()->createAliasScopeForm('')->handleRequest($request);

        $redirectResponse = $this->requireValidForm($aliasScopeForm);

        if ($redirectResponse !== null) {
            return $redirectResponse;
        }

        $aliasScopeFormData = $aliasScopeForm->getData();
        /** @var string $aliasName */
        $aliasName = $aliasScopeFormData[AliasScopeForm::FIELD_ALIAS_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$aliasScopeFormData[AliasScopeForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->resolveScopeOrRedirect($aliasName, $redirectUrl);

        if ($searchIndexScopeTransfer instanceof RedirectResponse) {
            return $searchIndexScopeTransfer;
        }

        $searchIndexRolloutTransfer = $this->getFacade()->getActiveRollout($searchIndexScopeTransfer);

        if ($searchIndexRolloutTransfer === null) {
            $this->addErrorMessage(sprintf('No active rollout for "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        try {
            $pending
                ? $this->getFacade()->markFlipPending($searchIndexRolloutTransfer)
                : $this->getFacade()->unmarkFlipPending($searchIndexRolloutTransfer);
        } catch (RolloutNotReadyException $rolloutNotReadyException) {
            $this->addErrorMessage($rolloutNotReadyException->getMessage());

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage($pending
            ? sprintf('"%s" will flip on the next deploy (search-index-alias:deploy-flip).', $aliasName)
            : sprintf('"%s" is no longer flagged for deploy-time flip.', $aliasName));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function markRollbackPendingAction(Request $request): RedirectResponse
    {
        return $this->handleRollbackPendingToggle($request, true);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function unmarkRollbackPendingAction(Request $request): RedirectResponse
    {
        return $this->handleRollbackPendingToggle($request, false);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param bool $pending
     */
    protected function handleRollbackPendingToggle(Request $request, bool $pending): RedirectResponse
    {
        $rollbackForm = $this->getFactory()->createRollbackForm('', '')->handleRequest($request);

        $redirectResponse = $this->requireValidForm($rollbackForm);

        if ($redirectResponse !== null) {
            return $redirectResponse;
        }

        $rollbackFormData = $rollbackForm->getData();
        /** @var string $aliasName */
        $aliasName = $rollbackFormData[RollbackForm::FIELD_ALIAS_NAME];
        /** @var string $targetIndexName */
        $targetIndexName = $rollbackFormData[RollbackForm::FIELD_TARGET_INDEX_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$rollbackFormData[RollbackForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->resolveScopeOrRedirect($aliasName, $redirectUrl);

        if ($searchIndexScopeTransfer instanceof RedirectResponse) {
            return $searchIndexScopeTransfer;
        }

        if (!$pending) {
            $this->getFacade()->unmarkPendingRollback($searchIndexScopeTransfer);
            $this->addSuccessMessage(sprintf('"%s" is no longer flagged for a deploy-time rollback.', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        return $this->markRollbackPending($searchIndexScopeTransfer, $targetIndexName, $aliasName, $redirectUrl);
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     * @param string $aliasName
     * @param string $redirectUrl
     */
    protected function markRollbackPending(
        SearchIndexScopeTransfer $searchIndexScopeTransfer,
        string $targetIndexName,
        string $aliasName,
        string $redirectUrl,
    ): RedirectResponse {
        try {
            $this->getFacade()->markPendingRollback($searchIndexScopeTransfer, $targetIndexName, 'zed-gui');
        } catch (RollbackTargetNotApplicableException $rollbackTargetNotApplicableException) {
            $this->addErrorMessage($rollbackTargetNotApplicableException->getMessage());

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage(sprintf('"%s" will roll back to "%s" on the next deploy (search-index-alias:deploy-flip).', $aliasName, $targetIndexName));

        return $this->redirectResponse($redirectUrl);
    }
}
