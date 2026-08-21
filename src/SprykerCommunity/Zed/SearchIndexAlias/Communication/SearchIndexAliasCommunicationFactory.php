<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication;

use GuzzleHttp\Client as GuzzleClient;
use Spryker\Client\Queue\QueueClient;
use Spryker\Client\RabbitMq\RabbitMqConfig as ClientRabbitMqConfig;
use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use Spryker\Zed\RabbitMq\RabbitMqConfig as ZedRabbitMqConfig;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Acl\BackOfficeAccessAnalyzer;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Acl\BackOfficeAccessAnalyzerInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\AbortForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\AliasScopeForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\DeleteIndexForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RebuildForm;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RollbackForm;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientBridge;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Client\SearchIndexAliasToQueueClientInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToAclFacadeInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade\SearchIndexAliasToTranslatorFacadeInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasDependencyProvider;
use Symfony\Component\Form\FormInterface;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig getConfig()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
class SearchIndexAliasCommunicationFactory extends AbstractCommunicationFactory
{
    public function getAclFacade(): SearchIndexAliasToAclFacadeInterface
    {
        return $this->getProvidedDependency(SearchIndexAliasDependencyProvider::FACADE_ACL);
    }

    public function createBackOfficeAccessAnalyzer(): BackOfficeAccessAnalyzerInterface
    {
        return new BackOfficeAccessAnalyzer($this->getAclFacade());
    }

    public function getTranslatorFacade(): SearchIndexAliasToTranslatorFacadeInterface
    {
        return $this->getProvidedDependency(SearchIndexAliasDependencyProvider::FACADE_TRANSLATOR);
    }

    /**
     * Diagnostic-only duplication of the Business factory's own construction (see
     * `SearchIndexAliasBusinessFactory::createElasticaClientProvider()`) -- these stateless infra clients
     * have no real "business" logic of their own, so building a second instance from Communication for
     * `search-index-alias:check-installation` to ping is simpler and safer than reaching across layers.
     */
    public function createElasticaClientProvider(): ElasticaClientProviderInterface
    {
        return new ElasticaClientProvider($this->createSearchElasticsearchConfig());
    }

    public function createRabbitMqManagementClient(): RabbitMqManagementClientInterface
    {
        return new RabbitMqManagementClient(
            $this->createGuzzleClient(),
            $this->createZedRabbitMqConfig(),
            $this->createBrokerConnectionProvider(),
        );
    }

    /**
     * Same diagnostic-only duplication rationale as `createElasticaClientProvider()` above.
     */
    public function createQueueClient(): SearchIndexAliasToQueueClientInterface
    {
        return new SearchIndexAliasToQueueClientBridge($this->createSprykerQueueClient());
    }

    protected function createSearchElasticsearchConfig(): SearchElasticsearchConfig
    {
        return new SearchElasticsearchConfig();
    }

    protected function createSprykerQueueClient(): QueueClient
    {
        return new QueueClient();
    }

    protected function createGuzzleClient(): GuzzleClient
    {
        return new GuzzleClient();
    }

    protected function createZedRabbitMqConfig(): ZedRabbitMqConfig
    {
        return new ZedRabbitMqConfig();
    }

    protected function createBrokerConnectionProvider(): BrokerConnectionProvider
    {
        return new BrokerConnectionProvider($this->createClientRabbitMqConfig());
    }

    protected function createClientRabbitMqConfig(): ClientRabbitMqConfig
    {
        return new ClientRabbitMqConfig();
    }

    /**
     * @param string $aliasName
     * @param string $redirectTo
     */
    public function createAliasScopeForm(string $aliasName, string $redirectTo = ''): FormInterface
    {
        return $this->getFormFactory()->create(AliasScopeForm::class, [
            AliasScopeForm::FIELD_ALIAS_NAME => $aliasName,
            AliasScopeForm::FIELD_REDIRECT_TO => $redirectTo,
        ]);
    }

    /**
     * @param string $aliasName
     * @param string $redirectTo
     */
    public function createAbortForm(string $aliasName, string $redirectTo = ''): FormInterface
    {
        return $this->getFormFactory()->create(AbortForm::class, [
            AbortForm::FIELD_ALIAS_NAME => $aliasName,
            AbortForm::FIELD_REASON => 'aborted via Zed GUI',
            AbortForm::FIELD_REDIRECT_TO => $redirectTo,
        ]);
    }

    /**
     * @param string $aliasName
     * @param string $targetIndexName
     * @param string $redirectTo
     */
    public function createRollbackForm(string $aliasName, string $targetIndexName, string $redirectTo = ''): FormInterface
    {
        return $this->getFormFactory()->create(RollbackForm::class, [
            RollbackForm::FIELD_ALIAS_NAME => $aliasName,
            RollbackForm::FIELD_TARGET_INDEX_NAME => $targetIndexName,
            RollbackForm::FIELD_REDIRECT_TO => $redirectTo,
        ]);
    }

    /**
     * @param string $aliasName
     * @param string $indexName
     * @param string $redirectTo
     */
    public function createDeleteIndexForm(string $aliasName, string $indexName, string $redirectTo = ''): FormInterface
    {
        return $this->getFormFactory()->create(DeleteIndexForm::class, [
            DeleteIndexForm::FIELD_ALIAS_NAME => $aliasName,
            DeleteIndexForm::FIELD_INDEX_NAME => $indexName,
            DeleteIndexForm::FIELD_REDIRECT_TO => $redirectTo,
        ]);
    }

    /**
     * @param string $aliasName
     * @param string $redirectTo
     */
    public function createRebuildForm(string $aliasName, string $redirectTo = ''): FormInterface
    {
        return $this->getFormFactory()->create(RebuildForm::class, [
            RebuildForm::FIELD_ALIAS_NAME => $aliasName,
            RebuildForm::FIELD_REDIRECT_TO => $redirectTo,
        ]);
    }
}
