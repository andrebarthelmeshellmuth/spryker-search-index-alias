<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Exception;

use RuntimeException;

/**
 * Thrown when IndexAdopter::adopt() is asked to adopt a scope that is not, in fact, a not-yet-adopted
 * concrete index -- either the alias already exists (nothing to adopt), or neither an alias nor a
 * concrete index exists yet under that name (a brand-new scope, which needs the installer plugin's path
 * instead -- see IndexAliasInstallerPlugin).
 */
class AdoptionNotApplicableException extends RuntimeException
{
}
