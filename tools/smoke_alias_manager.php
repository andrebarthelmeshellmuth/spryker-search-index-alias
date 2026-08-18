<?php

declare(strict_types = 1);

/**
 * Manual, live-cluster smoke test for AliasManager -- NOT part of CI (which has no OpenSearch cluster
 * available; see ci.yml). Exercises every AliasManager operation, including both of this package's
 * atomicity claims (see the class doc blocks), against a real cluster: creates throwaway `smoke_*`
 * indices/alias, runs the full create -> switch -> delete-refusal -> collision -> adopt sequence, and
 * cleans up after itself even on failure paths.
 *
 * Run from inside a Zed/cli container with this package's own vendor/autoload.php reachable at
 * /data/vendor/autoload.php (adjust the two hardcoded paths below for a different install layout):
 *
 *   php tools/smoke_alias_manager.php
 */

require '/data/vendor/autoload.php';

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

use Elastica\Client;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasNameCollisionException;

$client = new Client(['host' => 'search', 'port' => 9200]);

$provider = new readonly class ($client) implements ElasticaClientProviderInterface {
    public function __construct(private Client $client)
    {
    }

    public function getClient(): Client
    {
        return $this->client;
    }
};

$manager = new AliasManager($provider);

function line(string $label, callable $fn): void
{
    echo str_pad($label, 55, '.');
    try {
        $result = $fn();
        echo ' OK ' . ($result !== null ? '(' . (is_array($result) ? implode(',', $result) : $result) . ')' : '') . "\n";
    } catch (Throwable $e) {
        echo ' FAIL: ' . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

// cleanup from any previous run
foreach (['smoke_v1', 'smoke_v2', 'smoke_concrete'] as $idx) {
    if (!$client->getIndex($idx)->exists()) {
        continue;
    }

    try {
        $client->getIndex($idx)->delete();
    } catch (Throwable) {
    }
}

echo "=== AliasManager smoke test against live OpenSearch ===\n";

line('create smoke_v1, smoke_v2 physical indices', function () use ($client) {
    $client->getIndex('smoke_v1')->create(['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]]);
    $client->getIndex('smoke_v2')->create(['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]]);

    return null;
});

line('createAlias(smoke_alias, smoke_v1)', function () use ($manager) {
    $manager->createAlias('smoke_alias', 'smoke_v1');

    return null;
});

line('getIndicesForAlias(smoke_alias) -> [smoke_v1]', fn () => $manager->getIndicesForAlias('smoke_alias'));

line('indexExists(smoke_v1) -> true', fn () => $manager->indexExists('smoke_v1') ? 'true' : 'FALSE');

line('getAliasesForIndex(smoke_v1) -> [smoke_alias]', fn () => $manager->getAliasesForIndex('smoke_v1'));

line('switchAlias(smoke_alias, smoke_v1 -> smoke_v2)', function () use ($manager) {
    $manager->switchAlias('smoke_alias', 'smoke_v1', 'smoke_v2');

    return null;
});

line('getIndicesForAlias(smoke_alias) -> [smoke_v2]', fn () => $manager->getIndicesForAlias('smoke_alias'));

line('deleteUnaliasedIndex(smoke_v1) -- now unaliased, should succeed', function () use ($manager) {
    $manager->deleteUnaliasedIndex('smoke_v1');

    return null;
});

line('deleteUnaliasedIndex(smoke_v2) -- STILL aliased, must be REFUSED', function () use ($manager) {
    try {
        $manager->deleteUnaliasedIndex('smoke_v2');
    } catch (\SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AliasOperationFailedException) {
        return 'correctly refused';
    }

    return 'DID NOT THROW -- BUG';
});

line('create smoke_concrete as a real concrete index (pre-adoption state)', function () use ($client) {
    $client->getIndex('smoke_concrete')->create(['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]]);

    return null;
});

line('createAlias(smoke_concrete, smoke_v2) throws AliasNameCollisionException specifically', function () use ($manager) {
    try {
        $manager->createAlias('smoke_concrete', 'smoke_v2');
    } catch (AliasNameCollisionException) {
        return 'correctly refused, correct exception type';
    }

    return 'DID NOT THROW -- BUG';
});

line('adoptConcreteIndex(smoke_concrete, smoke_v2) -- the remove_index trick', function () use ($manager) {
    $manager->adoptConcreteIndex('smoke_concrete', 'smoke_v2');

    return null;
});

line('getIndicesForAlias(smoke_concrete) -> [smoke_v2] (now an alias, not a concrete index)', fn () => $manager->getIndicesForAlias('smoke_concrete'));

// cleanup
echo "\n=== cleanup ===\n";
foreach (['smoke_v1', 'smoke_v2', 'smoke_concrete'] as $idx) {
    if (!$client->getIndex($idx)->exists()) {
        continue;
    }

    $client->getIndex($idx)->delete();
    echo "deleted $idx\n";
}
echo "done.\n";
