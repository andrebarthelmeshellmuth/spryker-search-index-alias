<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToAclFacadeBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToStoreFacadeBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToTranslatorFacadeBridge;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig getConfig()
 */
class SearchIndexAliasDependencyProvider extends AbstractBundleDependencyProvider
{
    /**
     * @var string
     */
    public const FACADE_STORE = 'FACADE_STORE';

    /**
     * `RebuildRequestPublisher`/`RebuildRequestConsumer`'s dedicated rebuild-request work queue --
     * ordinary, permanent, single fixed name, exactly the shape `spryker/queue`'s `Client\Queue` is
     * designed for (unlike this package's OTHER raw-AMQP uses, `MirrorQueueBinder`/`MirrorQueueDrain`,
     * which need dynamic per-rollout exchange binding and high-volume `basic_get` draining that
     * `Client\Queue`/the RabbitMQ Management API don't support -- see those classes' own doc blocks).
     * Safe to call from Zed/console: `Client\Queue` never touches `Client\Store`/`Client\Locale`, so none
     * of the session-dependent crash this shop hit with `Client\Catalog`/`Client\Search` applies here.
     *
     * @var string
     */
    public const CLIENT_QUEUE = 'CLIENT_QUEUE';

    /**
     * Communication-layer only -- used exclusively by `search-index-alias:check-installation` (see
     * BackOfficeAccessAnalyzer). Nothing on the request path consults Acl directly.
     *
     * @var string
     */
    public const FACADE_ACL = 'FACADE_ACL';

    /**
     * Communication-layer only -- used exclusively by `search-index-alias:check-installation` to confirm
     * the project actually wired this package's Zed translation catalog. Nothing on the request path
     * consults this directly; Twig's own `trans` filter resolves through Zed's real translation service.
     *
     * @var string
     */
    public const FACADE_TRANSLATOR = 'FACADE_TRANSLATOR';

    /**
     * @var string
     */
    public const PLUGINS_TARGET_INDEX_SETTINGS_EXPANDER = 'PLUGINS_TARGET_INDEX_SETTINGS_EXPANDER';

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    #[\Override]
    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container = $this->addStoreFacade($container);
        $container = $this->addQueueClient($container);

        return $this->addTargetIndexSettingsExpanderPlugins($container);
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    #[\Override]
    public function provideCommunicationLayerDependencies(Container $container): Container
    {
        $container = parent::provideCommunicationLayerDependencies($container);
        $container = $this->addAclFacade($container);

        return $this->addTranslatorFacade($container);
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addStoreFacade(Container $container): Container
    {
        $container->set(static::FACADE_STORE, fn (Container $container): SearchIndexAliasToStoreFacadeBridge => new SearchIndexAliasToStoreFacadeBridge($container->getLocator()->store()->facade()));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addQueueClient(Container $container): Container
    {
        $container->set(static::CLIENT_QUEUE, fn (Container $container): SearchIndexAliasToQueueClientBridge => new SearchIndexAliasToQueueClientBridge($container->getLocator()->queue()->client()));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addTargetIndexSettingsExpanderPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_TARGET_INDEX_SETTINGS_EXPANDER, fn () => $this->getTargetIndexSettingsExpanderPlugins());

        return $container;
    }

    /**
     * Override on project level to plug per-scope target-index `settings.index` changes into every
     * rebuild trigger this package has -- e.g. the companion spryker-community/search-analyzer-config
     * package (not yet released) contributes edited decompound/synonym/stopword/stemmer/brand-exception
     * analyzer config this way. Empty by default: a rebuild's target settings are then an unmodified clone
     * of live's, identical to this package's behavior before this extension point existed.
     *
     * @return array<\SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin\TargetIndexSettingsExpanderPluginInterface>
     */
    protected function getTargetIndexSettingsExpanderPlugins(): array
    {
        return [];
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addAclFacade(Container $container): Container
    {
        $container->set(static::FACADE_ACL, fn (Container $container): SearchIndexAliasToAclFacadeBridge => new SearchIndexAliasToAclFacadeBridge($container->getLocator()->acl()->facade()));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     */
    protected function addTranslatorFacade(Container $container): Container
    {
        $container->set(static::FACADE_TRANSLATOR, fn (Container $container): SearchIndexAliasToTranslatorFacadeBridge => new SearchIndexAliasToTranslatorFacadeBridge($container->getLocator()->translator()->facade()));

        return $container;
    }
}
