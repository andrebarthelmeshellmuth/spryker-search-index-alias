<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAliasGuiPresentation\PageObject;

class HistoryPage
{
    /**
     * @var string
     */
    public const URL = '/search-index-alias/rollout/history';

    /**
     * @var string
     */
    public const BACK_TO_OVERVIEW_LINK_TEXT = 'Back to overview';

    /**
     * @param string $aliasName
     */
    public static function buildUrl(string $aliasName): string
    {
        return sprintf('%s?alias=%s', static::URL, urlencode($aliasName));
    }
}
