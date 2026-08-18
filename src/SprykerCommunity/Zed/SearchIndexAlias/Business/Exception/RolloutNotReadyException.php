<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Exception;

use RuntimeException;

/**
 * Thrown by RolloutFinisher::markFlipPending()/unmarkFlipPending() when called on a rollout whose status
 * is not READY -- flip-pending is only a meaningful concept while a target index is built, verified, and
 * waiting for the flip.
 */
class RolloutNotReadyException extends RuntimeException
{
}
