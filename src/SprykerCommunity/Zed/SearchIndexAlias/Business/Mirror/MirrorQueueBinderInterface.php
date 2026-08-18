<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;

interface MirrorQueueBinderInterface
{
    /**
     * Declares a fresh, uniquely-named queue and binds it to the scope's sync exchange -- from this
     * instant on, the mirror queue receives a durable, broker-side copy of every message the live
     * publish/sync pipeline sends, alongside (not instead of) the real sync queue. See README "How it
     * works" for why this, plus the bulk load, is what lets a rebuild converge without ever touching the
     * live index.
     *
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     *
     * @return string The declared queue's name.
     */
    public function bind(SearchIndexRolloutTransfer $searchIndexRolloutTransfer): string;

    /**
     * Deletes the mirror queue -- called once the rollout reaches a terminal status (FLIPPED or
     * ABORTED), never before. Any messages still sitting in it at that point are simply discarded: once
     * flipped, the alias points at the target and the live sync pipeline keeps it current through its
     * own normal path; once aborted, the target is being dropped anyway.
     *
     * @param string $mirrorQueueName
     */
    public function unbind(string $mirrorQueueName): void;
}
