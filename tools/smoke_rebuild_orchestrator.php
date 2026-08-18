<?php

declare(strict_types = 1);

/**
 * Manual, live-cluster+broker+DB smoke test for RebuildOrchestrator (P5) -- see smoke_alias_manager.php's
 * own header for why this isn't part of CI. Exercises the full start -> ready -> flip flow, plus a
 * separate start -> abort flow, against real infrastructure: a throwaway alias/live-index pair standing
 * in for an already-adopted scope, a real bulk load of the real `page`/`DE` data (via BulkLoader, same
 * as smoke_bulk_and_mirror.php), and the real MappingDiffClassifier applied to a genuine additive mapping
 * change layered onto the target.
 *
 *   php tools/smoke_rebuild_orchestrator.php
 */

if (!defined('APPLICATION_ENV')) {
    define('APPLICATION_ENV', 'development');
}
if (!defined('APPLICATION_STORE')) {
    define('APPLICATION_STORE', 'DE');
}
if (!defined('APPLICATION_ROOT_DIR')) {
    define('APPLICATION_ROOT_DIR', '/data');
}
if (!defined('APPLICATION_SOURCE_DIR')) {
    define('APPLICATION_SOURCE_DIR', '/data/src');
}
if (!defined('APPLICATION')) {
    define('APPLICATION', 'ZED');
}

require '/data/vendor/autoload.php';

\Spryker\Shared\Config\Config::get('SOME_KEY_THAT_DOES_NOT_EXIST', 'trigger-init-only');

$autoloadPath = '/data/packages/spryker-community/search-index-alias/src';
spl_autoload_register(function (string $class) use ($autoloadPath): void {
    if (!str_starts_with($class, 'SprykerCommunity\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('SprykerCommunity\\')));
    $file = $autoloadPath . '/SprykerCommunity/' . $relative . '.php';
    if (!is_file($file)) {
        return;
    }

    require $file;
});

use Elastica\Client as ElasticaClient;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use GuzzleHttp\Client as GuzzleClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Propel\Runtime\Propel;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoader;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\MappingDiff\MappingDiffClassifier;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestrator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutFinisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutGuard;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rollout\RolloutStarter;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasEntityManager;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasPersistenceFactory;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\SearchIndexAliasRepository;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

// --- Propel connection (same recipe as smoke_persistence.php) ---
require '/data/data/cache/propel/generated-conf/loadDatabase.php';
$propelManager = new \Propel\Runtime\Connection\ConnectionManagerSingle('zed');
$propelManager->setConfiguration([
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
        getenv('SPRYKER_DB_HOST') ?: 'database',
        getenv('SPRYKER_DB_PORT') ?: '3306',
        getenv('SPRYKER_DB_DATABASE') ?: 'eu-docker',
    ),
    'user' => getenv('SPRYKER_DB_USERNAME') ?: 'spryker',
    'password' => getenv('SPRYKER_DB_PASSWORD') ?: 'secret',
]);
$propelServiceContainer = Propel::getServiceContainer();
$propelServiceContainer->setAdapterClass('zed', 'mysql');
$propelServiceContainer->setConnectionManager($propelManager);
$propelServiceContainer->setDefaultDatasource('zed');

// --- collaborators ---
$elasticaClient = new ElasticaClient(['host' => 'search', 'port' => 9200]);
$elasticaClientProvider = new readonly class ($elasticaClient) implements ElasticaClientProviderInterface {
    public function __construct(private ElasticaClient $client)
    {
    }

    public function getClient(): ElasticaClient
    {
        return $this->client;
    }
};

$config = new class extends SearchIndexAliasConfig {
};

$brokerConnectionProvider = new class implements BrokerConnectionProviderInterface {
    public function getConnection(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            getenv('SPRYKER_BROKER_HOST') ?: 'broker',
            (int)(getenv('SPRYKER_BROKER_PORT') ?: 5672),
            getenv('SPRYKER_BROKER_USERNAME') ?: 'spryker',
            getenv('SPRYKER_BROKER_PASSWORD') ?: 'secret',
            'eu-docker',
        );
    }

    public function getVirtualHost(): string
    {
        return 'eu-docker';
    }
};

$rabbitMqManagementClient = new RabbitMqManagementClient(
    new GuzzleClient(),
    new class extends \Spryker\Zed\RabbitMq\RabbitMqConfig {
    },
    $brokerConnectionProvider,
);

$entityManager = new SearchIndexAliasEntityManager();
$entityManager->setFactory(new SearchIndexAliasPersistenceFactory());
$repository = new SearchIndexAliasRepository();
$repository->setFactory(new SearchIndexAliasPersistenceFactory());

$aliasManager = new AliasManager($elasticaClientProvider);
$indexCloner = new IndexCloner($elasticaClientProvider);
$indexNameBuilder = new IndexNameBuilder($config);
$mappingDiffClassifier = new MappingDiffClassifier();
$bulkLoader = new BulkLoader($elasticaClientProvider, $config);
$mirrorQueueBinder = new MirrorQueueBinder($rabbitMqManagementClient, $config);
$mirrorQueueDrain = new MirrorQueueDrain($brokerConnectionProvider, $elasticaClientProvider);
$rolloutGuard = new RolloutGuard($repository);
$rolloutStarter = new RolloutStarter($rolloutGuard, $aliasManager, $entityManager);
$rolloutFinisher = new RolloutFinisher($entityManager);

function line(string $label, callable $fn): void
{
    echo str_pad($label, 65, '.');
    try {
        $result = $fn();
        echo ' OK ' . ($result !== null ? '(' . (is_array($result) ? json_encode($result) : $result) . ')' : '') . "\n";
    } catch (Throwable $e) {
        echo ' FAIL: ' . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

const ALIAS_NAME = 'smoke_rebuild_de_page_alias';
const LIVE_INDEX_NAME = 'smoke_rebuild_live_page';

$scope = (new SearchIndexScopeTransfer())
    ->setSourceIdentifier('page')
    ->setStoreName('DE')
    ->setAliasName(ALIAS_NAME);

// --- cleanup from any previous run ---
foreach ($repository->getRolloutHistoryForScope('page', 'DE') as $searchIndexRolloutTransfer) {
    if ($searchIndexRolloutTransfer->getSearchIndexScope()->getAliasName() !== ALIAS_NAME) {
        continue;
    }
    \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery::create()
        ->filterByIdSearchIndexRollout($searchIndexRolloutTransfer->getIdSearchIndexRollout())
        ->delete();
}
foreach ($aliasManager->getIndicesForAlias(ALIAS_NAME) as $indexName) {
    $elasticaClient->getIndex($indexName)->delete();
}
if ($elasticaClient->getIndex(LIVE_INDEX_NAME)->exists()) {
    $elasticaClient->getIndex(LIVE_INDEX_NAME)->delete();
}

echo "=== RebuildOrchestrator smoke test against live cluster+broker+DB ===\n";

line('set up a throwaway live index + alias (stand-in for an already-adopted scope)', function () use ($indexCloner, $aliasManager) {
    $indexCloner->cloneMappingAndSettings('spryker_b2b_marketplace_dev_de_page', LIVE_INDEX_NAME);
    $aliasManager->createAlias(ALIAS_NAME, LIVE_INDEX_NAME);

    return null;
});

$orchestrator = new RebuildOrchestrator(
    $rolloutStarter,
    $rolloutFinisher,
    $indexNameBuilder,
    $indexCloner,
    $mappingDiffClassifier,
    $bulkLoader,
    $mirrorQueueBinder,
    $mirrorQueueDrain,
    $aliasManager,
    $entityManager,
    false,
);

$readyRollout = null;

line('start() with an additive mapping change -- builds target, bulk-loads real page/DE data, converges, READY', function () use ($orchestrator, $scope, &$readyRollout) {
    $readyRollout = $orchestrator->start($scope, 'smoke-test', [
        'properties' => [
            'smoke-test-additive-field' => ['type' => 'keyword'],
        ],
    ]);

    if ($readyRollout->getStatus() !== SharedSearchIndexAliasConfig::STATUS_READY) {
        return 'NOT READY -- status=' . $readyRollout->getStatus() . ' failureReason=' . $readyRollout->getFailureReason();
    }

    return 'status=' . $readyRollout->getStatus() . ' target=' . $readyRollout->getTargetIndexName() . ' actualCount=' . $readyRollout->getActualDocumentCount();
});

line('mapping diff was classified additive (real diff between cloned live mapping and the layered field)', function () use (&$readyRollout) {
    $diff = $readyRollout?->getSearchIndexMappingDiff();
    if ($diff === null) {
        return 'NO DIFF STORED -- BUG';
    }

    return $diff->getClassification() === SharedSearchIndexAliasConfig::MAPPING_DIFF_ADDITIVE
        ? 'classification=' . $diff->getClassification() . ' addedFields=' . json_encode($diff->getAddedFields())
        : 'WRONG CLASSIFICATION: ' . $diff->getClassification();
});

line('target index actually has the new field mapped', function () use ($indexCloner, &$readyRollout) {
    $mapping = $indexCloner->getMapping($readyRollout->getTargetIndexNameOrFail());

    return isset($mapping['properties']['smoke-test-additive-field'])
        ? 'present, type=' . $mapping['properties']['smoke-test-additive-field']['type']
        : 'MISSING -- BUG';
});

line('target has real documents (bulk-loaded from spy_*_search tables, not empty)', function () use (&$readyRollout) {
    return (int)$readyRollout->getActualDocumentCount() > 0
        ? 'actualDocumentCount=' . $readyRollout->getActualDocumentCount()
        : 'ZERO DOCUMENTS -- BUG';
});

line('flip() -- atomic alias switch, mirror queue unbound, status FLIPPED', function () use ($orchestrator, $aliasManager, &$readyRollout) {
    $flipped = $orchestrator->flip($readyRollout);
    $readyRollout = $flipped;

    if ($flipped->getStatus() !== SharedSearchIndexAliasConfig::STATUS_FLIPPED) {
        return 'NOT FLIPPED -- status=' . $flipped->getStatus() . ' failureReason=' . $flipped->getFailureReason();
    }

    $indicesForAlias = $aliasManager->getIndicesForAlias(ALIAS_NAME);

    return $indicesForAlias === [$flipped->getTargetIndexName()]
        ? 'alias now points at ' . implode(',', $indicesForAlias)
        : 'ALIAS DID NOT SWITCH -- points at ' . json_encode($indicesForAlias);
});

line('mirror queue is gone after flip', function () use (&$readyRollout, $rabbitMqManagementClient) {
    try {
        $rabbitMqManagementClient->deleteQueue($readyRollout->getMirrorQueueNameOrFail());

        return 'STILL EXISTED -- a second delete succeeded, BUG';
    } catch (\SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\BrokerOperationFailedException) {
        return 'correctly gone (second delete failed as expected)';
    }
});

line('old live index is now unaliased (flip did not touch/delete it)', fn () => $aliasManager->getAliasesForIndex(LIVE_INDEX_NAME) === []
    ? 'correctly unaliased, still exists'
    : 'STILL ALIASED -- BUG');

// --- second scenario: start() then abort() ---
$abortedRollout = null;

line('start() a second rollout against the now-flipped alias', function () use ($orchestrator, $scope, &$abortedRollout) {
    $abortedRollout = $orchestrator->start($scope, 'smoke-test-2');

    return 'status=' . $abortedRollout->getStatus() . ' target=' . $abortedRollout->getTargetIndexName();
});

line('abort() -- target index dropped, mirror queue unbound, status ABORTED', function () use ($orchestrator, $aliasManager, &$abortedRollout) {
    $targetIndexName = $abortedRollout->getTargetIndexName();
    $aborted = $orchestrator->abort($abortedRollout, 'smoke-test cancellation');
    $abortedRollout = $aborted;

    if ($aborted->getStatus() !== SharedSearchIndexAliasConfig::STATUS_ABORTED) {
        return 'NOT ABORTED -- status=' . $aborted->getStatus();
    }

    return $aliasManager->indexExists($targetIndexName)
        ? 'TARGET INDEX STILL EXISTS -- BUG'
        : 'target index correctly dropped, live alias untouched';
});

line('live traffic (the alias) was never interrupted by the abort', function () use ($aliasManager, &$readyRollout) {
    return $aliasManager->getIndicesForAlias(ALIAS_NAME) === [$readyRollout->getTargetIndexName()]
        ? 'alias still correctly resolves post-abort'
        : 'ALIAS CHANGED -- BUG';
});

// --- cleanup ---
echo "\n=== cleanup ===\n";
// Remove the alias itself first (deleteUnaliasedIndex() refuses to drop an index that's still aliased).
$elasticaClient->request('_aliases', 'POST', [
    'actions' => [
        ['remove' => ['index' => '*', 'alias' => ALIAS_NAME]],
    ],
]);
foreach ($aliasManager->getIndicesForAlias(ALIAS_NAME) as $indexName) {
    echo "unexpected: still aliased index $indexName\n";
}
if ($elasticaClient->getIndex($readyRollout->getTargetIndexName())->exists()) {
    $elasticaClient->getIndex($readyRollout->getTargetIndexName())->delete();
    echo 'deleted target index ' . $readyRollout->getTargetIndexName() . "\n";
}
if ($elasticaClient->getIndex(LIVE_INDEX_NAME)->exists()) {
    $elasticaClient->getIndex(LIVE_INDEX_NAME)->delete();
    echo 'deleted old live index ' . LIVE_INDEX_NAME . "\n";
}
foreach ($repository->getRolloutHistoryForScope('page', 'DE') as $searchIndexRolloutTransfer) {
    if ($searchIndexRolloutTransfer->getSearchIndexScope()->getAliasName() !== ALIAS_NAME) {
        continue;
    }
    \Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery::create()
        ->filterByIdSearchIndexRollout($searchIndexRolloutTransfer->getIdSearchIndexRollout())
        ->delete();
    echo 'deleted rollout id ' . $searchIndexRolloutTransfer->getIdSearchIndexRollout() . "\n";
}
echo "done.\n";
