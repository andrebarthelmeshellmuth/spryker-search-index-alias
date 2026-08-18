<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Health;

use Generated\Shared\Transfer\SearchIndexHealthCollectionTransfer;
use Generated\Shared\Transfer\SearchIndexHealthTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;

class SearchIndexHealthChecker implements SearchIndexHealthCheckerInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface $indexCloner
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface $indexEnumerator
     */
    public function __construct(
        protected AliasManagerInterface $aliasManager,
        protected IndexClonerInterface $indexCloner,
        protected IndexEnumeratorInterface $indexEnumerator,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function checkScope(SearchIndexScopeTransfer $searchIndexScopeTransfer): SearchIndexHealthTransfer
    {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $aliasedIndexNames = $this->aliasManager->getIndicesForAlias($aliasName);

        $issues = [];

        if ($aliasedIndexNames === []) {
            $issues[] = 'Alias does not exist yet -- this scope has not been adopted.';
        } elseif (count($aliasedIndexNames) > 1) {
            $issues[] = sprintf(
                'Alias points at %d indices simultaneously (drift, never produced by this package\'s own operations): %s.',
                count($aliasedIndexNames),
                implode(', ', $aliasedIndexNames),
            );
        }

        $documentCount = count($aliasedIndexNames) === 1 ? $this->indexCloner->getDocumentCount($aliasedIndexNames[0]) : 0;

        return (new SearchIndexHealthTransfer())
            ->setSearchIndexScope($searchIndexScopeTransfer)
            ->setIsHealthy($issues === [])
            ->setIssues($issues)
            ->setAliasedIndexNames($aliasedIndexNames)
            ->setDocumentCount($documentCount);
    }

    public function checkAllManagedScopes(): SearchIndexHealthCollectionTransfer
    {
        $searchIndexHealthCollectionTransfer = new SearchIndexHealthCollectionTransfer();

        foreach ($this->indexEnumerator->enumerateScopes() as $searchIndexScopeTransfer) {
            $searchIndexHealthCollectionTransfer->addSearchIndexHealth($this->checkScope($searchIndexScopeTransfer));
        }

        return $searchIndexHealthCollectionTransfer;
    }
}
