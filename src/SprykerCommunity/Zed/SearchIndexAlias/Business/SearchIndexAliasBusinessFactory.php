<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business;

use GuzzleHttp\Client as GuzzleClient;
use Spryker\Client\RabbitMq\RabbitMqConfig as ClientRabbitMqConfig;
use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use Spryker\Zed\RabbitMq\RabbitMqConfig as ZedRabbitMqConfig;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Builder\IndexDefinitionBuilder;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Builder\IndexDefinitionBuilderInterface;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Finder\SchemaDefinitionFinder;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Loader\IndexDefinitionLoader;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Merger\IndexDefinitionMerger;
use Spryker\Zed\SearchElasticsearch\Business\Definition\Reader\IndexDefinitionReader;
use Spryker\Zed\SearchElasticsearch\Business\SourceIdentifier\SourceIdentifier;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexAdopter;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexAdopterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexClonerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoader;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoaderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\DeployFlipRunner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\DeployFlipRunnerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Deploy\PendingRollbackTargetManagerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Health\SearchIndexHealthChecker;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Health\SearchIndexHealthCheckerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumerator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexEnumeratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexLister;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\PhysicalIndexListerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\ScopeIndexOverview;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\ScopeIndexOverviewInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifier;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifierInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrainInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolverInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexDeleter;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexDeleterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexPruner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Prune\IndexPrunerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestrator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestConsumer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestConsumerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollback;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollback\AliasRollbackInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuardInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarterInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Schema\PlainJsonUtilEncodingService;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Schema\SchemaIndexDefinitionResolver;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Schema\SchemaIndexDefinitionResolverInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToStoreFacadeInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasDependencyProvider;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig getConfig()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManagerInterface getEntityManager()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepositoryInterface getRepository()
 */
class SearchIndexAliasBusinessFactory extends AbstractBusinessFactory
{
    public function createAliasManager(): AliasManagerInterface
    {
        return new AliasManager($this->createElasticaClientProvider());
    }

    public function createElasticaClientProvider(): ElasticaClientProviderInterface
    {
        return new ElasticaClientProvider($this->createSearchElasticsearchConfig());
    }

    public function createIndexNameBuilder(): IndexNameBuilderInterface
    {
        return new IndexNameBuilder($this->getConfig());
    }

    public function createIndexEnumerator(): IndexEnumeratorInterface
    {
        return new IndexEnumerator(
            $this->getStoreFacade(),
            $this->createCanonicalIndexNameResolver(),
            $this->createSearchElasticsearchConfig(),
            $this->getConfig(),
        );
    }

    public function createCanonicalIndexNameResolver(): CanonicalIndexNameResolverInterface
    {
        return new CanonicalIndexNameResolver($this->createSearchElasticsearchConfig());
    }

    public function createIndexCloner(): IndexClonerInterface
    {
        return new IndexCloner($this->createElasticaClientProvider());
    }

    public function createIndexAdopter(): IndexAdopterInterface
    {
        return new IndexAdopter(
            $this->createAliasManager(),
            $this->createIndexCloner(),
            $this->createIndexNameBuilder(),
        );
    }

    /**
     * Shared-across-modules Config class, deliberately instantiated directly rather than injected via
     * the DependencyProvider -- see CanonicalIndexNameResolver's own class doc block for why this
     * package reads `SearchElasticsearchConfig` rather than depending on that module's internal
     * `SourceIdentifier` class or a Facade method it does not expose. A project that overrides its own
     * `search-elasticsearch` config (e.g. `Pyz\Zed\SearchElasticsearch\SearchElasticsearchConfig`) with
     * anything relevant to naming (`getSupportedSourceIdentifiers()`, `getIndexPrefix()`,
     * `getClientConfig()`) should override this method in its own
     * `Pyz\Zed\SearchIndexAlias\Business\SearchIndexAliasBusinessFactory` to return that class instead
     * of core's own -- otherwise this package silently resolves names against the un-overridden config.
     */
    public function createSearchElasticsearchConfig(): SearchElasticsearchConfig
    {
        return new SearchElasticsearchConfig();
    }

    public function getStoreFacade(): SearchIndexAliasToStoreFacadeInterface
    {
        return $this->getProvidedDependency(SearchIndexAliasDependencyProvider::FACADE_STORE);
    }

    public function getQueueClient(): SearchIndexAliasToQueueClientInterface
    {
        return $this->getProvidedDependency(SearchIndexAliasDependencyProvider::CLIENT_QUEUE);
    }

    public function createRolloutGuard(): RolloutGuardInterface
    {
        return new RolloutGuard($this->getRepository());
    }

    public function createRolloutStarter(): RolloutStarterInterface
    {
        return new RolloutStarter(
            $this->createRolloutGuard(),
            $this->createAliasManager(),
            $this->getEntityManager(),
        );
    }

    public function createRolloutFinisher(): RolloutFinisherInterface
    {
        return new RolloutFinisher($this->getEntityManager());
    }

    public function createBulkLoader(): BulkLoaderInterface
    {
        return new BulkLoader($this->createElasticaClientProvider(), $this->getConfig());
    }

    public function createMirrorQueueBinder(): MirrorQueueBinderInterface
    {
        return new MirrorQueueBinder($this->createRabbitMqManagementClient(), $this->getConfig());
    }

    public function createMirrorQueueDrain(): MirrorQueueDrainInterface
    {
        return new MirrorQueueDrain($this->createBrokerConnectionProvider(), $this->createElasticaClientProvider());
    }

    public function createRabbitMqManagementClient(): RabbitMqManagementClientInterface
    {
        return new RabbitMqManagementClient(
            $this->createGuzzleClient(),
            $this->createZedRabbitMqConfig(),
            $this->createBrokerConnectionProvider(),
        );
    }

    public function createGuzzleClient(): GuzzleClient
    {
        return new GuzzleClient();
    }

    public function createBrokerConnectionProvider(): BrokerConnectionProviderInterface
    {
        return new BrokerConnectionProvider($this->createClientRabbitMqConfig());
    }

    /**
     * See `createSearchElasticsearchConfig()`'s own doc block for the general reasoning -- same
     * shared-config pattern, applied to the RabbitMQ Management HTTP API's connection details.
     */
    public function createZedRabbitMqConfig(): ZedRabbitMqConfig
    {
        return new ZedRabbitMqConfig();
    }

    /**
     * `Spryker\Client\RabbitMq\RabbitMqConfig` (NOT the Client Facade) is the only `@api` source for the
     * real AMQP connection parameters from Zed -- see `BrokerConnectionProvider`'s own class doc block.
     */
    public function createClientRabbitMqConfig(): ClientRabbitMqConfig
    {
        return new ClientRabbitMqConfig();
    }

    public function createMappingDiffClassifier(): MappingDiffClassifierInterface
    {
        return new MappingDiffClassifier();
    }

    public function createRebuildOrchestrator(): RebuildOrchestratorInterface
    {
        return new RebuildOrchestrator(
            $this->createRolloutStarter(),
            $this->createRolloutFinisher(),
            $this->createIndexNameBuilder(),
            $this->createIndexCloner(),
            $this->createMappingDiffClassifier(),
            $this->createBulkLoader(),
            $this->createMirrorQueueBinder(),
            $this->createMirrorQueueDrain(),
            $this->createAliasManager(),
            $this->getEntityManager(),
            $this->createRebuildRequestPublisher(),
            $this->getConfig()->isAutoFlipEnabled(),
            $this->getTargetIndexSettingsExpanderPlugins(),
            $this->createSchemaIndexDefinitionResolver(),
        );
    }

    /**
     * Reconstructs core's own (`spryker/search-elasticsearch`, already a hard dependency of this
     * package) schema-JSON discovery+merge pipeline standalone -- see `SchemaIndexDefinitionResolver`'s
     * own doc block for why this is reused rather than reimplemented. Every collaborator here is core's
     * own class, unmodified; only `PlainJsonUtilEncodingService` is this package's own (a
     * dependency-free stand-in for `spryker/util-encoding-service`, which isn't otherwise required here).
     */
    public function createSchemaIndexDefinitionResolver(): SchemaIndexDefinitionResolverInterface
    {
        return new SchemaIndexDefinitionResolver($this->createCoreIndexDefinitionBuilder());
    }

    protected function createCoreIndexDefinitionBuilder(): IndexDefinitionBuilderInterface
    {
        return new IndexDefinitionBuilder(
            $this->createCoreIndexDefinitionLoader(),
            $this->createCoreIndexDefinitionMerger(),
        );
    }

    protected function createCoreIndexDefinitionMerger(): IndexDefinitionMerger
    {
        return new IndexDefinitionMerger();
    }

    protected function createCoreIndexDefinitionLoader(): IndexDefinitionLoader
    {
        return new IndexDefinitionLoader(
            $this->createCoreSchemaDefinitionFinder(),
            $this->createCoreIndexDefinitionReader(),
            $this->createCoreSourceIdentifier(),
        );
    }

    protected function createCoreSchemaDefinitionFinder(): SchemaDefinitionFinder
    {
        return new SchemaDefinitionFinder($this->createSearchElasticsearchConfig());
    }

    protected function createCoreIndexDefinitionReader(): IndexDefinitionReader
    {
        return new IndexDefinitionReader($this->createPlainJsonUtilEncodingService());
    }

    protected function createCoreSourceIdentifier(): SourceIdentifier
    {
        return new SourceIdentifier($this->createSearchElasticsearchConfig());
    }

    protected function createPlainJsonUtilEncodingService(): PlainJsonUtilEncodingService
    {
        return new PlainJsonUtilEncodingService();
    }

    /**
     * @return array<\SprykerCommunity\Zed\SearchIndexAlias\Dependency\Plugin\TargetIndexSettingsExpanderPluginInterface>
     */
    public function getTargetIndexSettingsExpanderPlugins(): array
    {
        return $this->getProvidedDependency(SearchIndexAliasDependencyProvider::PLUGINS_TARGET_INDEX_SETTINGS_EXPANDER);
    }

    public function createRebuildRequestPublisher(): RebuildRequestPublisherInterface
    {
        return new RebuildRequestPublisher($this->getQueueClient(), $this->getConfig());
    }

    public function createRebuildRequestConsumer(): RebuildRequestConsumerInterface
    {
        return new RebuildRequestConsumer(
            $this->getQueueClient(),
            $this->getConfig(),
            $this->getRepository(),
            $this->createRebuildOrchestrator(),
        );
    }

    public function createIndexPruner(): IndexPrunerInterface
    {
        return new IndexPruner(
            $this->createPhysicalIndexLister(),
            $this->createAliasManager(),
            $this->getConfig(),
            $this->getEntityManager(),
            $this->getRepository(),
        );
    }

    public function createIndexDeleter(): IndexDeleterInterface
    {
        return new IndexDeleter(
            $this->createAliasManager(),
            $this->getEntityManager(),
            $this->getRepository(),
        );
    }

    public function createPhysicalIndexLister(): PhysicalIndexListerInterface
    {
        return new PhysicalIndexLister($this->createElasticaClientProvider(), $this->createIndexNameBuilder());
    }

    public function createSearchIndexHealthChecker(): SearchIndexHealthCheckerInterface
    {
        return new SearchIndexHealthChecker(
            $this->createAliasManager(),
            $this->createIndexCloner(),
            $this->createIndexEnumerator(),
        );
    }

    public function createScopeIndexOverview(): ScopeIndexOverviewInterface
    {
        return new ScopeIndexOverview(
            $this->createPhysicalIndexLister(),
            $this->createAliasManager(),
            $this->createIndexCloner(),
            $this->getRepository(),
        );
    }

    public function createAliasRollback(): AliasRollbackInterface
    {
        return new AliasRollback(
            $this->createRolloutStarter(),
            $this->createRolloutFinisher(),
            $this->createAliasManager(),
            $this->createRolloutGuard(),
        );
    }

    public function createDeployFlipRunner(): DeployFlipRunnerInterface
    {
        return new DeployFlipRunner(
            $this->createIndexEnumerator(),
            $this->getRepository(),
            $this->createRebuildOrchestrator(),
            $this->createAliasRollback(),
            $this->createPendingRollbackTargetManager(),
        );
    }

    public function createPendingRollbackTargetManager(): PendingRollbackTargetManagerInterface
    {
        return new PendingRollbackTargetManager(
            $this->createAliasManager(),
            $this->getRepository(),
            $this->getEntityManager(),
            $this->createRolloutFinisher(),
        );
    }
}
