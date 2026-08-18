<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception;

use RuntimeException;

/**
 * Thrown when creating a rollout row would violate the `active_scope_key` unique index -- i.e. a
 * non-terminal rollout for this exact scope already exists. See the schema's own comment on that column
 * for why this is a database-enforced constraint, not just an application-level check-then-insert.
 */
class ConcurrentRolloutException extends RuntimeException
{
}
