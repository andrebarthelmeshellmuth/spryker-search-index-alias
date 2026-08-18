<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PageObject;

class OverviewPage
{
    /**
     * @var string
     */
    public const URL = '/search-index-alias';

    /**
     * @var string
     */
    public const SELECT_SOURCE_ID = 'source';

    /**
     * @var string
     */
    public const SELECT_STORE_ID = 'store';

    /**
     * @var string
     */
    public const CHECKBOX_OPTIMIZE_FOR_BULK_LOAD_ID = 'search_index_alias_rebuild_optimizeForBulkLoad';

    /**
     * @var string
     */
    public const REBUILD_BUTTON_TEXT = 'Rebuild';

    /**
     * The action-bar Flip button -- flips the CURRENT scope's active `ready` rollout live. Deliberately
     * distinct from the per-row "Flip to this index"/"Roll back to this index" buttons (both submit the
     * SAME `rollbackFormView`, to `/search-index-alias/rollout/rollback` -- see index.twig), which jump
     * directly to an already-built OLD index and are themselves blocked by RolloutGuard while this
     * scope's active rollout is still non-terminal.
     *
     * @var string
     */
    public const FLIP_BUTTON_TEXT = 'Flip';

    /**
     * @var string
     */
    public const ROLLBACK_TO_NEVER_LIVE_INDEX_BUTTON_TEXT = 'Flip to this index';

    /**
     * @var string
     */
    public const ABORT_BUTTON_TEXT = 'Abort';

    /**
     * @var string
     */
    public const FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT = 'Flag for next deploy';

    /**
     * @var string
     */
    public const UNFLAG_BUTTON_TEXT = 'Unflag';

    /**
     * The per-row rollback counterpart to FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT -- same label, different row
     * (an old physical index row, not the action bar), same underlying mechanism (flip-pending vs.
     * pending-rollback-target are mutually exclusive per scope, see PendingRollbackTargetManager).
     *
     * @var string
     */
    public const ROW_FLAG_FOR_NEXT_DEPLOY_BUTTON_TEXT = 'Flag for next deploy';

    /**
     * @var string
     */
    public const ROLLBACK_BUTTON_TEXT = 'Roll back to this index';

    /**
     * @var string
     */
    public const DELETE_BUTTON_TEXT = 'Delete';

    /**
     * @var string
     */
    public const FIELD_ABORT_REASON_ID = 'search_index_alias_abort_reason';

    /**
     * @var string
     */
    public const VIEW_HISTORY_LINK_TEXT = 'View rollout history';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_REBUILD_REQUESTED_PREFIX = 'Rebuild';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_FLIPPED_SUFFIX = 'now points at';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_ABORTED = 'aborted. Live traffic was never affected.';

    /**
     * @var string
     */
    public const CONSOLE_COMMAND_REBUILD_WORKER = 'search-index-alias:rebuild-worker --stop-when-empty';

    /**
     * @var string
     */
    public const CONSOLE_COMMAND_DEPLOY_FLIP = 'search-index-alias:deploy-flip';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_FLIP_PENDING_SUFFIX = 'will flip on the next deploy';

    /**
     * @var string
     */
    public const FLASH_MESSAGE_ROLLBACK_PENDING_SUFFIX = 'will roll back to';

    /**
     * The Overview page's cross-scope "Pending deploy flips" panel -- present regardless of the
     * Source/Store filter, showing exactly what search-index-alias:deploy-flip would act on right now.
     *
     * @var string
     */
    public const PENDING_DEPLOY_FLIPS_PANEL_HEADING = 'Pending deploy flips';

    /**
     * The active-rollout status line above the action bar, present only while a non-terminal rollout
     * exists for the selected scope -- see AbstractScopeController::findScopeByAlias() /
     * IndexController::buildScopeViewData().
     *
     * @var string
     */
    public const SELECTOR_ACTIVE_ROLLOUT_STATUS_LINE = "//*[contains(text(), 'target')]";

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public static function buildUrl(string $sourceIdentifier, string $storeName): string
    {
        return sprintf('%s?source=%s&store=%s', static::URL, urlencode($sourceIdentifier), urlencode($storeName));
    }
}
