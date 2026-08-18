<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Index;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolverInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToStoreFacadeInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

class IndexEnumerator implements IndexEnumeratorInterface
{
    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToStoreFacadeInterface $storeFacade
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolverInterface $canonicalIndexNameResolver
     * @param \Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig $searchElasticsearchConfig
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     */
    public function __construct(
        protected SearchIndexAliasToStoreFacadeInterface $storeFacade,
        protected CanonicalIndexNameResolverInterface $canonicalIndexNameResolver,
        protected SearchElasticsearchConfig $searchElasticsearchConfig,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
    ) {
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexScopeTransfer>
     */
    public function enumerateScopes(): array
    {
        $scopes = [];

        foreach ($this->storeFacade->getAllStores() as $storeTransfer) {
            $storeName = $storeTransfer->getNameOrFail();

            foreach ($this->getManagedSourceIdentifiers() as $sourceIdentifier) {
                $scope = $this->buildScopeIfSupported($sourceIdentifier, $storeName);

                if ($scope === null) {
                    continue;
                }

                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findScope(string $sourceIdentifier, string $storeName): ?SearchIndexScopeTransfer
    {
        return $this->buildScopeIfSupported($sourceIdentifier, $storeName);
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    protected function buildScopeIfSupported(string $sourceIdentifier, string $storeName): ?SearchIndexScopeTransfer
    {
        if (!$this->canonicalIndexNameResolver->isSupported($sourceIdentifier, $storeName)) {
            return null;
        }

        return (new SearchIndexScopeTransfer())
            ->setSourceIdentifier($sourceIdentifier)
            ->setStoreName($storeName)
            ->setAliasName($this->canonicalIndexNameResolver->resolve($sourceIdentifier, $storeName));
    }

    /**
     * @return array<string>
     */
    protected function getManagedSourceIdentifiers(): array
    {
        $managed = $this->searchIndexAliasConfig->getManagedSourceIdentifiers();

        if ($managed !== []) {
            return $managed;
        }

        return $this->searchElasticsearchConfig->getSupportedSourceIdentifiers();
    }
}
