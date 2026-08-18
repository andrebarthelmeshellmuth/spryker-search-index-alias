<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Acl;

use Generated\Shared\Transfer\SearchIndexAliasBackOfficeAccessDiagnosisTransfer;

interface BackOfficeAccessAnalyzerInterface
{
    /**
     * @param array<string> $moduleNames
     */
    public function analyze(array $moduleNames): SearchIndexAliasBackOfficeAccessDiagnosisTransfer;
}
