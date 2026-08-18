<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\BulkLoad;

use Elastica\Document;
use Elastica\Index;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use PDO;
use Propel\Runtime\Propel;
use RuntimeException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\SpySearchSourceTable;

class BulkLoader implements BulkLoaderInterface
{
    /**
     * Elasticsearch/OpenSearch's own recommended ballpark for a single `_bulk` request -- large enough
     * to amortize per-request overhead, small enough to stay well under the default `http.max_content_length`.
     *
     * @var int
     */
    protected const BATCH_SIZE = 1000;

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\Business\Client\ElasticaClientProviderInterface $elasticaClientProvider
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig $searchIndexAliasConfig
     * @param string $propelConnectionName
     */
    public function __construct(
        protected ElasticaClientProviderInterface $elasticaClientProvider,
        protected SearchIndexAliasConfig $searchIndexAliasConfig,
        protected string $propelConnectionName = 'zed',
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\SearchIndexScopeTransfer $searchIndexScopeTransfer
     * @param string $targetIndexName
     */
    public function load(SearchIndexScopeTransfer $searchIndexScopeTransfer, string $targetIndexName): int
    {
        $sourceIdentifier = $searchIndexScopeTransfer->getSourceIdentifierOrFail();
        $storeName = $searchIndexScopeTransfer->getStoreNameOrFail();
        $spySearchSourceTables = $this->searchIndexAliasConfig->getSpySearchSourceTables()[$sourceIdentifier] ?? [];

        $index = $this->elasticaClientProvider->getClient()->getIndex($targetIndexName);
        $totalWritten = 0;

        foreach ($spySearchSourceTables as $spySearchSourceTable) {
            $totalWritten += $this->loadTable($spySearchSourceTable, $storeName, $index);
        }

        $index->refresh();

        return $totalWritten;
    }

    /**
     * @param \SprykerCommunity\Zed\SearchIndexAlias\SpySearchSourceTable $spySearchSourceTable
     * @param string $storeName
     * @param \Elastica\Index $index
     *
     * @throws \RuntimeException
     */
    protected function loadTable(SpySearchSourceTable $spySearchSourceTable, string $storeName, Index $index): int
    {
        $connection = Propel::getConnection($this->propelConnectionName);

        $sql = sprintf(
            'SELECT `%s` AS `key_value`, `%s` AS `data_value` FROM `%s` WHERE `%s` IS NOT NULL',
            $spySearchSourceTable->getKeyColumn(),
            $spySearchSourceTable->getDataColumn(),
            $spySearchSourceTable->getTableName(),
            $spySearchSourceTable->getDataColumn(),
        );

        if ($spySearchSourceTable->getStoreColumn() !== null) {
            $sql .= sprintf(' AND `%s` = :storeName', $spySearchSourceTable->getStoreColumn());
        }

        // PDO::MYSQL_ATTR_USE_BUFFERED_QUERY defaults to true, which pulls the ENTIRE result set into
        // PHP/mysqlnd memory before this method's fetch loop even starts -- fine at a few thousand rows,
        // a real problem at a real catalog's scale (hundreds of thousands of rows, each carrying a
        // sizeable rendered `data` JSON blob). Disabling it makes the driver stream rows as fetch() is
        // called instead, bounding memory to roughly one row at a time. Safe here specifically because
        // this statement is always fully drained (the `while` loop below runs to completion) before
        // another query ever runs on the same connection -- unbuffered mode's real constraint.
        $statement = $connection->prepare($sql, [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);

        if ($statement === false) {
            throw new RuntimeException(sprintf('Failed to prepare bulk-load query for table "%s".', $spySearchSourceTable->getTableName()));
        }

        if ($spySearchSourceTable->getStoreColumn() !== null) {
            $statement->bindValue('storeName', $storeName);
        }

        $statement->execute();

        $written = 0;
        $documents = [];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode((string)$row['data_value'], true);

            if (!is_array($data) || $row['key_value'] === null) {
                continue;
            }

            $documents[] = new Document((string)$row['key_value'], $data);
            $written++;

            if (count($documents) < static::BATCH_SIZE) {
                continue;
            }

            $index->addDocuments($documents);
            $documents = [];
        }

        if ($documents !== []) {
            $index->addDocuments($documents);
        }

        return $written;
    }
}
