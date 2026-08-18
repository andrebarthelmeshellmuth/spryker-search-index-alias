<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller;

use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\AbortForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\AliasScopeForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\DeleteIndexForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RebuildForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RollbackForm;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class RolloutController extends AbstractScopeController
{
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function adoptAction(Request $request): RedirectResponse
    {
        $aliasScopeForm = $this->getFactory()->createAliasScopeForm('')->handleRequest($request);

        if (!$aliasScopeForm->isSubmitted() || !$aliasScopeForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $aliasScopeFormData = $aliasScopeForm->getData();
        /** @var string $aliasName */
        $aliasName = $aliasScopeFormData[AliasScopeForm::FIELD_ALIAS_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$aliasScopeFormData[AliasScopeForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        if (!$this->getFacade()->needsAdoption($searchIndexScopeTransfer)) {
            $this->addInfoMessage(sprintf('"%s" is already an alias -- nothing to adopt.', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        try {
            $newIndexName = $this->getFacade()->adopt($searchIndexScopeTransfer);
            $this->addSuccessMessage(sprintf('"%s" is now an alias pointing at "%s".', $aliasName, $newIndexName));
        } catch (AdoptionNotApplicableException $adoptionNotApplicableException) {
            $this->addErrorMessage($adoptionNotApplicableException->getMessage());
        }

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function rebuildAction(Request $request): RedirectResponse
    {
        $rebuildForm = $this->getFactory()->createRebuildForm('')->handleRequest($request);

        if (!$rebuildForm->isSubmitted() || !$rebuildForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $rebuildFormData = $rebuildForm->getData();
        /** @var string $aliasName */
        $aliasName = $rebuildFormData[RebuildForm::FIELD_ALIAS_NAME];
        $optimizeForBulkLoad = (bool)$rebuildFormData[RebuildForm::FIELD_OPTIMIZE_FOR_BULK_LOAD];
        $redirectUrl = $this->resolveRedirectUrl((string)$rebuildFormData[RebuildForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        try {
            $searchIndexRolloutTransfer = $this->getFacade()->requestRebuildAsync($searchIndexScopeTransfer, 'zed-gui', null, $optimizeForBulkLoad);
        } catch (ConcurrentRolloutException $concurrentRolloutException) {
            $this->addErrorMessage($concurrentRolloutException->getMessage());

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage(sprintf(
            'Rebuild %d for "%s" was requested (target=%s). The rebuild worker will pick it up shortly -- refresh this page to check progress.',
            $searchIndexRolloutTransfer->getIdSearchIndexRollout() ?? 0,
            $aliasName,
            $searchIndexRolloutTransfer->getTargetIndexName() ?? '-',
        ));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function flipAction(Request $request): RedirectResponse
    {
        $aliasScopeForm = $this->getFactory()->createAliasScopeForm('')->handleRequest($request);

        if (!$aliasScopeForm->isSubmitted() || !$aliasScopeForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $aliasScopeFormData = $aliasScopeForm->getData();
        /** @var string $aliasName */
        $aliasName = $aliasScopeFormData[AliasScopeForm::FIELD_ALIAS_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$aliasScopeFormData[AliasScopeForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        $searchIndexRolloutTransfer = $this->getFacade()->getActiveRollout($searchIndexScopeTransfer);

        if ($searchIndexRolloutTransfer === null || $searchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_READY) {
            $this->addErrorMessage(sprintf('"%s" has no rollout ready to flip.', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        $flippedSearchIndexRolloutTransfer = $this->getFacade()->flipRollout($searchIndexRolloutTransfer);

        if ($flippedSearchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_FLIPPED) {
            $this->addErrorMessage(sprintf('Flip failed: %s', $flippedSearchIndexRolloutTransfer->getFailureReason() ?? 'unknown reason'));

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage(sprintf('"%s" now points at "%s".', $aliasName, $flippedSearchIndexRolloutTransfer->getTargetIndexName() ?? '-'));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function abortAction(Request $request): RedirectResponse
    {
        $abortForm = $this->getFactory()->createAbortForm('')->handleRequest($request);

        if (!$abortForm->isSubmitted() || !$abortForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $abortFormData = $abortForm->getData();
        /** @var string $aliasName */
        $aliasName = $abortFormData[AbortForm::FIELD_ALIAS_NAME];
        /** @var string $reason */
        $reason = $abortFormData[AbortForm::FIELD_REASON];
        $redirectUrl = $this->resolveRedirectUrl((string)$abortFormData[AbortForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        $searchIndexRolloutTransfer = $this->getFacade()->getActiveRollout($searchIndexScopeTransfer);

        if ($searchIndexRolloutTransfer === null) {
            $this->addErrorMessage(sprintf('No active rollout for "%s" -- nothing to abort.', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        $abortedSearchIndexRolloutTransfer = $this->getFacade()->abortRollout($searchIndexRolloutTransfer, $reason);

        if ($abortedSearchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_ABORTED) {
            $this->addErrorMessage(sprintf('Abort did not complete cleanly: status=%s', $abortedSearchIndexRolloutTransfer->getStatus()));

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage(sprintf('Rollout for "%s" aborted. Live traffic was never affected.', $aliasName));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function rollbackAction(Request $request): RedirectResponse
    {
        $rollbackForm = $this->getFactory()->createRollbackForm('', '')->handleRequest($request);

        if (!$rollbackForm->isSubmitted() || !$rollbackForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $rollbackFormData = $rollbackForm->getData();
        /** @var string $aliasName */
        $aliasName = $rollbackFormData[RollbackForm::FIELD_ALIAS_NAME];
        /** @var string $targetIndexName */
        $targetIndexName = $rollbackFormData[RollbackForm::FIELD_TARGET_INDEX_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$rollbackFormData[RollbackForm::FIELD_REDIRECT_TO]);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->redirectResponse($redirectUrl);
        }

        try {
            $searchIndexRolloutTransfer = $this->getFacade()->rollbackToIndex($searchIndexScopeTransfer, $targetIndexName, 'zed-gui');
        } catch (ConcurrentRolloutException $concurrentRolloutException) {
            $this->addErrorMessage($concurrentRolloutException->getMessage());

            return $this->redirectResponse($redirectUrl);
        }

        if ($searchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_FAILED) {
            $this->addErrorMessage(sprintf('Rollback failed: %s', $searchIndexRolloutTransfer->getFailureReason() ?? 'unknown reason'));

            return $this->redirectResponse($redirectUrl);
        }

        $this->addSuccessMessage(sprintf('"%s" rolled back to "%s".', $aliasName, $targetIndexName));

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function deleteIndexAction(Request $request): RedirectResponse
    {
        $deleteIndexForm = $this->getFactory()->createDeleteIndexForm('', '')->handleRequest($request);

        if (!$deleteIndexForm->isSubmitted() || !$deleteIndexForm->isValid()) {
            $this->addErrorMessage('CSRF token is not valid.');

            return $this->redirectResponse(static::URL_INDEX);
        }

        $deleteIndexFormData = $deleteIndexForm->getData();
        /** @var string $indexName */
        $indexName = $deleteIndexFormData[DeleteIndexForm::FIELD_INDEX_NAME];
        $redirectUrl = $this->resolveRedirectUrl((string)$deleteIndexFormData[DeleteIndexForm::FIELD_REDIRECT_TO]);

        try {
            $this->getFacade()->deleteIndex($indexName);
            $this->addSuccessMessage(sprintf('Deleted index "%s".', $indexName));
        } catch (AliasOperationFailedException $aliasOperationFailedException) {
            $this->addErrorMessage($aliasOperationFailedException->getMessage());
        }

        return $this->redirectResponse($redirectUrl);
    }

    /**
     * A pure, read-only audit log -- one row per rollout event, no actions. Deliberately has no forms:
     * every action (adopt/rebuild/flip/abort/rollback/delete) lives on the Overview page instead, which
     * is index-oriented and can show what each action would actually apply to. This page exists purely
     * to answer "what happened, when, and who triggered it."
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return array<string, mixed>
     */
    public function historyAction(Request $request): array
    {
        $aliasName = (string)$request->query->get('alias', '');
        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $this->addErrorMessage(sprintf('No managed scope found for alias "%s".', $aliasName));

            return $this->viewResponse(['aliasName' => $aliasName, 'history' => []]);
        }

        return $this->viewResponse([
            'aliasName' => $aliasName,
            'history' => $this->getFacade()->getRolloutHistory($searchIndexScopeTransfer),
        ]);
    }
}
