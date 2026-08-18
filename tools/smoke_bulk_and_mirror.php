<?php

declare(strict_types = 1);

/**
 * Manual, live-cluster+broker+DB smoke test for BulkLoader + MirrorQueueBinder + MirrorQueueDrain --
 * see smoke_alias_manager.php's own header for why this isn't part of CI. Exercises the full P4 data
 * flow against real infrastructure: bulk-loads the real `de_page` scope's data (thousands of real rows)
 * into a throwaway target index, binds a mirror queue to the real `sync.search.product` exchange,
 * publishes synthetic write/delete messages to that exchange (simulating live traffic), drains them,
 * and verifies the target ends up correct -- then cleans up the queue, binding, and index.
 *
 *   php tools/smoke_bulk_and_mirror.php
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
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use GuzzleHttp\Client as GuzzleClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Propel\Runtime\Propel;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig as SharedSearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad\BulkLoader;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueDrain;
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

$bulkLoader = new BulkLoader($elasticaClientProvider, $config);
$mirrorQueueBinder = new MirrorQueueBinder($rabbitMqManagementClient, $config);
$mirrorQueueDrain = new MirrorQueueDrain($brokerConnectionProvider, $elasticaClientProvider);

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

$targetIndexName = 'smoke_bulkload_page';
$scope = (new SearchIndexScopeTransfer())
    ->setSourceIdentifier('page')
    ->setStoreName('DE')
    ->setAliasName('smoke_bulkload_page_alias');
$rollout = (new SearchIndexRolloutTransfer())
    ->setIdSearchIndexRollout(999999)
    ->setSearchIndexScope($scope)
    ->setStatus(SharedSearchIndexAliasConfig::STATUS_BUILDING);

// cleanup from any previous run
if ($elasticaClient->getIndex($targetIndexName)->exists()) {
    $elasticaClient->getIndex($targetIndexName)->delete();
}
try {
    $mirrorQueueBinder->unbind(SharedSearchIndexAliasConfig::MIRROR_QUEUE_NAME_PREFIX . 'smoke_bulkload_page_alias.999999');
} catch (Throwable) {
}

echo "=== BulkLoader + MirrorQueue smoke test against live cluster+broker+DB ===\n";

// Realistic orchestration order (matches what P5's RebuildOrchestrator will actually do): the target's
// mapping must exist and match the real one BEFORE any documents are written, or ES's own dynamic-type
// inference can pick a type from early rows that later, differently-typed rows then collide with (this
// is precisely what happened on the FIRST run of this smoke test, against a bare/unmapped target --
// left in place deliberately, see below).
line('clone real de_page mapping+settings onto the target (BulkLoader\'s real precondition)', function () use ($elasticaClientProvider, $targetIndexName) {
    $indexCloner = new \SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner($elasticaClientProvider);
    $indexCloner->cloneMappingAndSettings('spryker_b2b_marketplace_dev_de_page', $targetIndexName);

    return null;
});

$realCount = null;

line('real spy_product_abstract_page_search count for store=DE (reference)', function () {
    $statement = Propel::getConnection('zed')->prepare(
        "SELECT COUNT(*) AS c FROM spy_product_abstract_page_search WHERE store = 'DE' AND data IS NOT NULL",
    );
    $statement->execute();

    return (int)$statement->fetch(PDO::FETCH_ASSOC)['c'];
});

$writtenCount = null;

line('BulkLoader::load() -- real bulk load from spy_*_search tables', function () use ($bulkLoader, $scope, $targetIndexName, &$writtenCount) {
    $writtenCount = $bulkLoader->load($scope, $targetIndexName);

    return $writtenCount;
});

line('written count is at least the abstract-table count (loader also pulls concrete/category/etc)', function () use (&$writtenCount, &$realCount) {
    return $writtenCount >= $realCount ? "written=$writtenCount >= abstract-only=$realCount" : "SUSPICIOUSLY LOW: written=$writtenCount < abstract-only=$realCount";
});

line('target index document count matches BulkLoader\'s own reported count', function () use ($elasticaClient, $targetIndexName, &$writtenCount) {
    $count = $elasticaClient->request($targetIndexName . '/_count', 'GET')->getData()['count'] ?? 0;

    return $count === $writtenCount ? "matches ($count)" : "MISMATCH: index has $count, loader reported $writtenCount";
});

$mirrorQueueName = null;

line('MirrorQueueBinder::bind() -- declare + bind to real sync.search.product exchange', function () use ($mirrorQueueBinder, $rollout, &$mirrorQueueName) {
    $mirrorQueueName = $mirrorQueueBinder->bind($rollout);

    return $mirrorQueueName;
});

line('publish a synthetic WRITE message to sync.search.product (simulates live traffic)', function () {
    $connection = new AMQPStreamConnection(
        getenv('SPRYKER_BROKER_HOST') ?: 'broker',
        (int)(getenv('SPRYKER_BROKER_PORT') ?: 5672),
        getenv('SPRYKER_BROKER_USERNAME') ?: 'spryker',
        getenv('SPRYKER_BROKER_PASSWORD') ?: 'secret',
        'eu-docker',
    );
    $channel = $connection->channel();
    $body = json_encode([
    'write' => [
        'key' => 'smoke-mirror-test-key',
        'value' => ['store' => 'DE', 'full-text' => 'mirrored during rebuild'],
        'params' => ['type' => 'page'],
        'store' => 'DE',
    ]]);
    $channel->basic_publish(new AMQPMessage($body), 'sync.search.product', '');
    $channel->close();
    $connection->close();

    return null;
});

line('drain() picks up the write and applies it to the target', function () use ($mirrorQueueDrain, &$mirrorQueueName, $targetIndexName, $elasticaClient) {
    $drained = $mirrorQueueDrain->drain($mirrorQueueName, $targetIndexName);
    $doc = $elasticaClient->request($targetIndexName . '/_doc/smoke-mirror-test-key', 'GET')->getData()['_source'] ?? null;

    if ($doc === null || $doc['full-text'] !== 'mirrored during rebuild') {
        return "FAIL: drained=$drained, doc=" . json_encode($doc);
    }

    return "drained=$drained, document present and correct";
});

line('publish a DELETE message for the same key', function () {
    $connection = new AMQPStreamConnection(
        getenv('SPRYKER_BROKER_HOST') ?: 'broker',
        (int)(getenv('SPRYKER_BROKER_PORT') ?: 5672),
        getenv('SPRYKER_BROKER_USERNAME') ?: 'spryker',
        getenv('SPRYKER_BROKER_PASSWORD') ?: 'secret',
        'eu-docker',
    );
    $channel = $connection->channel();
    $body = json_encode([
    'delete' => [
        'key' => 'smoke-mirror-test-key',
        'params' => ['type' => 'page'],
        'store' => 'DE',
    ]]);
    $channel->basic_publish(new AMQPMessage($body), 'sync.search.product', '');
    $channel->close();
    $connection->close();

    return null;
});

line('drain() picks up the delete, document is gone', function () use ($mirrorQueueDrain, &$mirrorQueueName, $targetIndexName, $elasticaClient) {
    $drained = $mirrorQueueDrain->drain($mirrorQueueName, $targetIndexName);

    try {
        $elasticaClient->getIndex($targetIndexName)->getDocument('smoke-mirror-test-key');

        return "FAIL: document still present after delete drain (drained=$drained)";
    } catch (\Elastica\Exception\NotFoundException) {
        return "drained=$drained, document correctly gone";
    }
});

line('drain() on an empty queue returns 0', fn () => $mirrorQueueDrain->drain($mirrorQueueName, $targetIndexName));

// cleanup
echo "\n=== cleanup ===\n";
$mirrorQueueBinder->unbind($mirrorQueueName);
echo "deleted mirror queue $mirrorQueueName\n";
$elasticaClient->getIndex($targetIndexName)->delete();
echo "deleted index $targetIndexName\n";
echo "done.\n";
