<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;

/**
 * Extension point for changing a rebuild's TARGET index `settings.index` block before it is created --
 * the only moment `analysis` (char_filter/token_filter/analyzer definitions) can differ from what's
 * already live, since analyzer settings are immutable on an existing index. Consulted once, inside
 * `RebuildOrchestrator::buildTarget()`, for every rebuild trigger this package has (synchronous `start()`,
 * the async request/consume pair, and `search-index-alias:deploy-flip`) -- registering a plugin here is
 * the only integration needed; no other part of this package needs to know a project has one installed.
 *
 * Deliberately NOT consulted by `IndexAdopter` -- adoption's contract is "make the current live index the
 * first blue/green generation, changing nothing", so it keeps cloning live faithfully via
 * `IndexClonerInterface::cloneMappingAndSettings()` directly.
 */
interface TargetIndexSettingsExpanderPluginInterface
{
    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer The scope the
     *   target index is being built for -- use `getStoreNameOrFail()`/`getSourceIdentifierOrFail()` to
     *   look up any per-scope configuration.
     * @param array<string, mixed> $settings The target's `settings.index` block, already cloned from the
     *   live index and filtered of cluster-managed keys (see `IndexCloner::NON_COPYABLE_SETTINGS`).
     *
     * @return array<string, mixed> The (possibly modified) `settings.index` block to create the target
     *   index with. Plugins that have nothing to contribute for this scope MUST return $settings
     *   unchanged, not an empty array -- the target is otherwise created with no settings at all.
     */
    public function expand(SearchIndexScopeTransfer $searchIndexScopeTransfer, array $settings): array;
}
