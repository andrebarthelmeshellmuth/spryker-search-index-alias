<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Deploy\Fixture;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;

/**
 * A hand-written test double (this suite uses no mocking library) returning a fixed list of scopes,
 * instead of the real IndexEnumerator's real-project-config-driven list -- see DeployFlipRunnerTest's own
 * class doc block for why.
 */
class FakeIndexEnumerator implements IndexEnumeratorInterface
{
    /**
     * @param array<\Generated\Shared\Transfer\SearchIndexScopeTransfer> $searchIndexScopeTransfers
     */
    public function __construct(protected array $searchIndexScopeTransfers)
    {
    }

    /**
     * @return array<\Generated\Shared\Transfer\SearchIndexScopeTransfer>
     */
    public function enumerateScopes(): array
    {
        return $this->searchIndexScopeTransfers;
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function findScope(string $sourceIdentifier, string $storeName): ?SearchIndexScopeTransfer
    {
        foreach ($this->searchIndexScopeTransfers as $searchIndexScopeTransfer) {
            if ($searchIndexScopeTransfer->getSourceIdentifier() === $sourceIdentifier && $searchIndexScopeTransfer->getStoreName() === $storeName) {
                return $searchIndexScopeTransfer;
            }
        }

        return null;
    }
}
