<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface;

class IndexAdopter implements IndexAdopterInterface
{
    /**
     * Bounded re-reindex passes to catch up documents written between the previous pass starting and
     * finishing -- `_reindex` is upsert-safe on Spryker's stable document IDs, so a repeat pass is
     * always safe, just wasted work once counts have converged.
     *
     * @var int
     */
    protected const MAX_CATCH_UP_PASSES = 3;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface $aliasManager
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface $indexCloner
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface $indexNameBuilder
     */
    public function __construct(
        protected AliasManagerInterface $aliasManager,
        protected IndexClonerInterface $indexCloner,
        protected IndexNameBuilderInterface $indexNameBuilder,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException
     */
    public function adopt(SearchIndexScopeTransfer $searchIndexScopeTransfer): string
    {
        if (!$this->needsAdoption($searchIndexScopeTransfer)) {
            throw new AdoptionNotApplicableException(sprintf(
                'Scope "%s" is not a not-yet-adopted concrete index -- nothing to adopt.',
                $searchIndexScopeTransfer->getAliasNameOrFail(),
            ));
        }

        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $targetIndexName = $this->indexNameBuilder->buildTargetIndexName($aliasName);

        $this->indexCloner->cloneMappingAndSettings($aliasName, $targetIndexName);
        $this->reindexUntilConverged($aliasName, $targetIndexName);
        $this->aliasManager->adoptConcreteIndex($aliasName, $targetIndexName);

        return $targetIndexName;
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     */
    public function needsAdoption(SearchIndexScopeTransfer $searchIndexScopeTransfer): bool
    {
        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();

        if (!$this->aliasManager->indexExists($aliasName)) {
            return false;
        }

        // A name that exists AND already has aliases pointing elsewhere is not itself the concrete
        // index -- it is already-adopted (an alias, resolved by getIndicesForAlias() below, or a
        // same-named index that is itself aliased under a different name, which cannot happen through
        // this package but is checked defensively).
        return $this->aliasManager->getIndicesForAlias($aliasName) === []
            && $this->aliasManager->getAliasesForIndex($aliasName) === [];
    }

    /**
     * @param string $sourceIndexName
     * @param string $targetIndexName
     *
     * @throws \SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\IndexCloneFailedException
     */
    protected function reindexUntilConverged(string $sourceIndexName, string $targetIndexName): void
    {
        for ($pass = 1; $pass <= static::MAX_CATCH_UP_PASSES; $pass++) {
            $this->indexCloner->reindexInto($sourceIndexName, $targetIndexName);

            $sourceCount = $this->indexCloner->getDocumentCount($sourceIndexName);
            $targetCount = $this->indexCloner->getDocumentCount($targetIndexName);

            if ($sourceCount === $targetCount) {
                return;
            }
        }

        throw new IndexCloneFailedException(sprintf(
            'Document counts for "%s" and "%s" did not converge after %d reindex passes -- source is ' .
            'receiving writes faster than reindex can catch up. Retry adoption during a quieter window.',
            $sourceIndexName,
            $targetIndexName,
            static::MAX_CATCH_UP_PASSES,
        ));
    }
}
