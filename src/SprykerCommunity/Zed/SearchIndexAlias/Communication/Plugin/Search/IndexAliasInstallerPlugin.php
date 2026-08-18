<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Plugin\Search;

use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use Psr\Log\LoggerInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\SearchExtension\Dependency\Plugin\InstallPluginInterface;
use Spryker\Zed\SearchExtension\Dependency\Plugin\StoreAwareInstallPluginInterface;

/**
 * Register this AFTER core's own `ElasticsearchIndexInstallerPlugin` in the project's
 * `InstallerDependencyProvider::getInstallerPlugins()` -- it depends on the concrete index that plugin
 * creates already existing.
 *
 * On a brand-new shop, this plugin's `adopt()` call runs on a just-created, empty concrete index --
 * mechanically identical to adopting a real, populated installation (see IndexAdopter), just faster
 * (nothing to reindex). This deliberately unifies "fresh install" and "adopt an existing installation"
 * into the same code path rather than a separate fresh-install branch: a brand-new shop never has a
 * visible "plain concrete index" phase at all, it goes straight from core's install step to
 * alias-managed.
 *
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig getConfig()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Communication\SearchIndexAliasCommunicationFactory getFactory()
 */
class IndexAliasInstallerPlugin extends AbstractPlugin implements InstallPluginInterface, StoreAwareInstallPluginInterface
{
    /**
     * @api
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param string|null $storeName
     */
    public function install(LoggerInterface $logger, ?string $storeName = null): void
    {
        foreach ($this->getFacade()->getManagedScopes() as $searchIndexScopeTransfer) {
            if ($storeName !== null && $searchIndexScopeTransfer->getStoreNameOrFail() !== $storeName) {
                continue;
            }

            $this->adoptIfNeeded($searchIndexScopeTransfer, $logger);
        }
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param \Psr\Log\LoggerInterface $logger
     */
    protected function adoptIfNeeded(SearchIndexScopeTransfer $searchIndexScopeTransfer, LoggerInterface $logger): void
    {
        if (!$this->getFacade()->needsAdoption($searchIndexScopeTransfer)) {
            return;
        }

        $aliasName = $searchIndexScopeTransfer->getAliasNameOrFail();
        $logger->info(sprintf('SearchIndexAlias: adopting "%s" into a managed alias...', $aliasName));

        $targetIndexName = $this->getFacade()->adopt($searchIndexScopeTransfer);

        $logger->info(sprintf('SearchIndexAlias: "%s" is now an alias pointing at "%s".', $aliasName, $targetIndexName));
    }
}
