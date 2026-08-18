<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface;

class RolloutGuard implements RolloutGuardInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface $searchIndexAliasRepository
     */
    public function __construct(protected SearchIndexAliasRepositoryInterface $searchIndexAliasRepository)
    {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException
     */
    public function assertNoActiveRollout(SearchIndexScopeTransfer $searchIndexScopeTransfer): void
    {
        $active = $this->searchIndexAliasRepository->findActiveRolloutForScope(
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
        );

        if ($active === null) {
            return;
        }

        throw new ConcurrentRolloutException(sprintf(
            'A rollout for scope "%s" (%s/%s) is already in progress (status: %s).',
            $searchIndexScopeTransfer->getAliasNameOrFail(),
            $searchIndexScopeTransfer->getSourceIdentifierOrFail(),
            $searchIndexScopeTransfer->getStoreNameOrFail(),
            $active->getStatus(),
        ));
    }
}
