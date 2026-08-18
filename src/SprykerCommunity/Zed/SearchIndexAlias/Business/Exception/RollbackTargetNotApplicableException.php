<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Exception;

use RuntimeException;

/**
 * Thrown by PendingRollbackTargetManager::mark() when the given target either doesn't exist or is
 * already the scope's live index -- both make "flip to this later" meaningless.
 */
class RollbackTargetNotApplicableException extends RuntimeException
{
}
