<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Shared\SearchIndexAlias;

/**
 * Rollout status and classification constants, shared between Zed (writes them) and ZedGui (reads them
 * for the overview/history tables) — kept in Shared rather than duplicated in each so the two can never
 * drift out of sync with each other.
 */
class SearchIndexAliasConfig
{
    /**
     * No rollout in progress for this scope; live traffic is unaffected either way.
     *
     * @var string
     */
    public const STATUS_IDLE = 'idle';

    /**
     * Target index created, bulk load and/or mirror-queue replay in progress. Live index untouched.
     *
     * @var string
     */
    public const STATUS_BUILDING = 'building';

    /**
     * Target index has converged and passed verification; awaiting the flip (auto or manual-confirm).
     *
     * @var string
     */
    public const STATUS_READY = 'ready';

    /**
     * The atomic alias switch itself. Sub-second by design (see AliasManager) -- this status exists
     * mainly so a crash mid-flip is distinguishable from a clean BUILDING/READY/IDLE state on restart.
     *
     * @var string
     */
    public const STATUS_FLIPPING = 'flipping';

    /**
     * Alias now points at the target index. Terminal, successful state.
     *
     * @var string
     */
    public const STATUS_FLIPPED = 'flipped';

    /**
     * Rollout was aborted (by an operator or a failed pre-flight/verification check). Target index
     * dropped, mirror queue unbound. Terminal state; the live index was never touched.
     *
     * @var string
     */
    public const STATUS_ABORTED = 'aborted';

    /**
     * Unrecoverable error during BUILDING/READY/FLIPPING. Terminal state; requires operator attention
     * (see the `repair`/`abort` console commands and the GUI's Maintenance section).
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @var array<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_FLIPPED,
        self::STATUS_ABORTED,
        self::STATUS_FAILED,
    ];

    /**
     * A mapping diff that only adds fields. Safe to apply to the live index immediately (see D5a in
     * this package's design notes / README "How it works" — additive changes are accepted on a live
     * mapping with zero downtime).
     *
     * @var string
     */
    public const MAPPING_DIFF_ADDITIVE = 'additive';

    /**
     * A mapping diff that retypes or removes a field. MUST NOT be applied to the live index -- this is
     * the entire reason this package exists. See README "How it works" for the silent-and-permanent
     * failure mode this classification exists to prevent.
     *
     * @var string
     */
    public const MAPPING_DIFF_BREAKING = 'breaking';

    /**
     * No mapping change at all -- a rebuild triggered for data-only reasons (e.g. recovering from a
     * suspected drift), not a mapping change.
     *
     * @var string
     */
    public const MAPPING_DIFF_NONE = 'none';

    /**
     * A physical index's display status (`SearchIndexPhysicalIndexTransfer::status`) -- distinct from a
     * rollout's own STATUS_* above, since a physical index and a rollout event are not 1:1 (e.g. the
     * concrete index adopted at first setup has no rollout row of its own). Currently the live index.
     *
     * @var string
     */
    public const PHYSICAL_INDEX_STATUS_CURRENT = 'current';

    /**
     * Was the live index at some point (its rollout reached FLIPPED), since superseded by a later flip
     * or rollback.
     *
     * @var string
     */
    public const PHYSICAL_INDEX_STATUS_REPLACED = 'replaced';

    /**
     * Its rollout ended ABORTED or FAILED -- built (partially or fully) but never went live. Distinct
     * from REPLACED, which did serve traffic at some point. Safe cleanup candidates: nothing depends on
     * them, they just weren't deleted automatically (see RebuildOrchestrator::abort()).
     *
     * @var string
     */
    public const PHYSICAL_INDEX_STATUS_SKIPPED = 'skipped';

    /**
     * No correlated rollout row found (e.g. the concrete index adopted before this package's history
     * begins) -- not an error, just nothing more specific to say about it.
     *
     * @var string
     */
    public const PHYSICAL_INDEX_STATUS_UNKNOWN = 'unknown';
}
