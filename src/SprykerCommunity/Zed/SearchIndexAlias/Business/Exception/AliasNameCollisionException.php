<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Exception;

use RuntimeException;

/**
 * Thrown when an alias name collides with an existing concrete (non-aliased) index and the caller did
 * not explicitly ask for adoption (see `AliasManagerInterface::adoptConcreteIndex()`). Elasticsearch
 * itself refuses this with `invalid_alias_name_exception` -- this wraps that into a clearer,
 * package-specific signal so a caller can distinguish "this scope has never been adopted yet" from any
 * other alias-operation failure.
 */
class AliasNameCollisionException extends RuntimeException
{
}
