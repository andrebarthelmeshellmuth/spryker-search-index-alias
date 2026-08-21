<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias;

/**
 * Describes one `spy_*_search` table whose `data` column already holds a fully rendered Elasticsearch
 * document (see `SearchIndexAliasConfig::getSpySearchSourceTables()`). Plain config value object, not a
 * Propel model -- the bulk loader reads it with a raw connection since the table name is only known at
 * runtime, from config, not at compile time.
 *
 * Lives at the module root next to `SearchIndexAliasConfig`, not under `Business/BulkLoad/` where its only
 * internal consumer (`BulkLoader`) sits -- it's part of `getSpySearchSourceTables()`'s own public return
 * type, and that method MUST be overridden per project. Keeping it beside `Config` means a project's
 * override only ever needs a single module-root import to construct one, not a deep import into the
 * Business subtree for what is, from a project's point of view, purely a config-construction concern --
 * the same convention core Spryker itself uses for value objects that are part of a `*Config` class's
 * public signature.
 */
class SpySearchSourceTable
{
    /**
     * @param string $tableName
     * @param string $dataColumn Column holding the fully rendered JSON document.
     * @param string $keyColumn Column holding the same stable identifier used as the document's `_id` by
     *  the normal publish/sync pipeline (e.g. `product_abstract:de:de_de:365`) -- confirmed live to be
     *  literally named `key` on every `*_page_search`/`*_search` table inspected. Using it verbatim is
     *  what lets a later-arriving mirror-queue write correctly overwrite (not duplicate) a row this
     *  loader already wrote.
     * @param string|null $storeColumn Null for tables with no per-store scoping (e.g. `spy_merchant_search`)
     *  -- every row from such a table applies to every store's index for this sourceIdentifier.
     * @param string|null $localeColumn Null for tables with no per-locale scoping.
     */
    public function __construct(
        protected string $tableName,
        protected string $dataColumn = 'data',
        protected string $keyColumn = 'key',
        protected ?string $storeColumn = 'store',
        protected ?string $localeColumn = 'locale',
    ) {
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getDataColumn(): string
    {
        return $this->dataColumn;
    }

    public function getKeyColumn(): string
    {
        return $this->keyColumn;
    }

    public function getStoreColumn(): ?string
    {
        return $this->storeColumn;
    }

    public function getLocaleColumn(): ?string
    {
        return $this->localeColumn;
    }
}
