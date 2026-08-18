<?php

declare(strict_types = 1);

/**
 * Manual, live-cluster smoke test for IndexAdopter -- see smoke_alias_manager.php's own header for why
 * this isn't part of CI. Builds a throwaway "pre-adoption" concrete index with real documents and a
 * realistic mapping, then runs the full adopt() flow: clone mapping/settings, reindex, verify
 * convergence, atomic swap -- and confirms the alias reads back the same documents afterwards.
 *
 *   php tools/smoke_index_adopter.php
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
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexAdopter;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Adoption\IndexCloner;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Alias\AliasManager;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Index\IndexNameBuilder;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

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

$aliasManager = new AliasManager($provider);
$indexCloner = new IndexCloner($provider);

$config = new class extends SearchIndexAliasConfig {
};
$indexNameBuilder = new IndexNameBuilder($config);
$adopter = new IndexAdopter($aliasManager, $indexCloner, $indexNameBuilder);

function line(string $label, callable $fn): void
{
    echo str_pad($label, 60, '.');
    try {
        $result = $fn();
        echo ' OK ' . ($result !== null ? '(' . (is_array($result) ? json_encode($result) : $result) . ')' : '') . "\n";
    } catch (Throwable $e) {
        echo ' FAIL: ' . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

$preAdoptionAlias = 'smoke_adopt_page';

// cleanup from any previous run: delete the concrete index and any alias-derived indices
foreach ([$preAdoptionAlias] as $name) {
    if (!$client->getIndex($name)->exists()) {
        continue;
    }

    try {
        $client->getIndex($name)->delete();
    } catch (Throwable) {
    }
}
foreach ($aliasManager->getIndicesForAlias($preAdoptionAlias) as $existing) {
    $client->getIndex($existing)->delete();
}

echo "=== IndexAdopter smoke test against live OpenSearch ===\n";

line('create pre-adoption concrete index with a realistic mapping', function () use ($client, $preAdoptionAlias) {
    $client->getIndex($preAdoptionAlias)->create([
        'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
        'mappings' => [
            'properties' => [
                'store' => ['type' => 'keyword'],
                'full-text' => ['type' => 'text'],
                'integer-facet' => ['type' => 'object', 'dynamic' => true],
            ],
        ],
    ]);

    return null;
});

line('seed 250 documents', function () use ($client, $preAdoptionAlias) {
    $index = $client->getIndex($preAdoptionAlias);
    $documents = [];
    for ($i = 1; $i <= 250; $i++) {
        $documents[] = $index->createDocument((string)$i, ['store' => 'DE', 'full-text' => 'product number ' . $i]);
    }
    $index->addDocuments($documents);
    $index->refresh();

    return null;
});

line('needsAdoption(scope) -> true (concrete index, not yet aliased)', function () use ($adopter, $preAdoptionAlias) {
    $scope = (new SearchIndexScopeTransfer())->setAliasName($preAdoptionAlias)->setSourceIdentifier('page')->setStoreName('DE');

    return $adopter->needsAdoption($scope) ? 'true' : 'FALSE';
});

$targetIndexName = null;

line('adopt(scope) -- clone mapping, reindex, converge, atomic swap', function () use ($adopter, $preAdoptionAlias) {
    $scope = (new SearchIndexScopeTransfer())->setAliasName($preAdoptionAlias)->setSourceIdentifier('page')->setStoreName('DE');

    return $adopter->adopt($scope);
});

line('getIndicesForAlias(alias) now resolves to the NEW physical index', function () use ($aliasManager, $preAdoptionAlias, &$targetIndexName) {
    $indices = $aliasManager->getIndicesForAlias($preAdoptionAlias);

    return $indices === [$targetIndexName] ? 'matches: ' . implode(',', $indices) : 'MISMATCH: ' . implode(',', $indices);
});

line('document count and content survived the adoption', function () use ($client, $preAdoptionAlias) {
    $count = $client->request($preAdoptionAlias . '/_count', 'GET')->getData()['count'] ?? 0;
    $doc = $client->request($preAdoptionAlias . '/_doc/1', 'GET')->getData()['_source'] ?? null;

    if ($count !== 250) {
        return "COUNT MISMATCH: $count";
    }
    if (($doc['full-text'] ?? null) !== 'product number 1') {
        return 'DOCUMENT CONTENT MISMATCH: ' . json_encode($doc);
    }

    return '250 docs, content verified';
});

line('adopt(scope) again is now REFUSED (already adopted)', function () use ($adopter, $preAdoptionAlias) {
    $scope = (new SearchIndexScopeTransfer())->setAliasName($preAdoptionAlias)->setSourceIdentifier('page')->setStoreName('DE');

    try {
        $adopter->adopt($scope);
    } catch (\SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException) {
        return 'correctly refused';
    }

    return 'DID NOT THROW -- BUG';
});

// cleanup
echo "\n=== cleanup ===\n";
foreach ($aliasManager->getIndicesForAlias($preAdoptionAlias) as $existing) {
    $client->getIndex($existing)->delete();
    echo "deleted $existing\n";
}
echo "done.\n";
