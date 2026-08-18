<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class SearchIndexAliasConfig extends AbstractBundleConfig
{
    /**
     * Appended to a scope's canonical alias name to build a physical index name, e.g.
     * `de_page` -> `de_page_20260815_143012`. `Ymd_His` (not `c`/ISO-8601): the result must be a legal
     * Elasticsearch/OpenSearch index-name segment, which forbids `:` and disallows starting with `-`
     * that ISO-8601's offset notation risks.
     *
     * @var string
     */
    public const TIMESTAMP_FORMAT = 'Ymd_His';

    /**
     * Prefix for the temporary RabbitMQ queue bound to a scope's sync exchange(s) during a rebuild
     * (see README "How it works" -- captures live deltas from the moment BUILDING starts, replayed onto
     * the target index after the bulk load). Suffixed with the scope's alias name and a rollout ID to
     * stay unique across concurrent rollouts of different scopes.
     *
     * @var string
     */
    public const MIRROR_QUEUE_NAME_PREFIX = 'search-index-alias.mirror.';

    /**
     * A single, static, durable RabbitMQ queue (unlike the per-rollout mirror queue above) that GUI-
     * triggered rebuild requests are published to, so the HTTP request that clicked "Rebuild" doesn't
     * have to wait for the rebuild itself -- see RebuildOrchestrator::requestRebuildAsync() and the
     * `search-index-alias:rebuild-worker` console command that drains it. Plain AMQP, not the RabbitMQ
     * Management API this package uses for the mirror queue, since this one never needs exchange
     * binding -- it's a plain worker queue, published to directly by name.
     *
     * @var string
     */
    public const REBUILD_REQUEST_QUEUE_NAME = 'search-index-alias.rebuild-requests';

    /**
     * Number of non-live indices to keep per scope after a successful flip (see IndexPruner). The
     * currently-aliased index is never counted against this budget and is never a candidate for
     * deletion, regardless of this value -- a real counting bug this package's design deliberately
     * avoids: naively counting the live index against the budget can prune it under the wrong conditions.
     *
     * @var int
     */
    public const DEFAULT_KEEP_INDICES = 3;

    /**
     * Which (store, sourceIdentifier) pairs this package manages. Returning an empty array (the
     * default) means "every sourceIdentifier from the host project's
     * `SearchElasticsearchConfig::getSupportedSourceIdentifiers()`, for every configured store" -- see
     * IndexEnumerator. Override in a project's `Pyz\Zed\SearchIndexAlias\SearchIndexAliasConfig` to
     * narrow that to a subset (e.g. only `page`, leaving smaller/rarely-changed indices like
     * `return_reason` on stock core's in-place mapping updates).
     *
     * @return array<string>
     */
    public function getManagedSourceIdentifiers(): array
    {
        return [];
    }

    /**
     * Maps a managed sourceIdentifier to the `spy_*_search` table(s) whose `data` column already holds
     * the fully rendered document for that index (see README "How it works" -- this is what lets a
     * rebuild bulk-load the target index directly from the database, bypassing the publish/sync queue
     * entirely). Spryker does not expose this table-to-index relationship anywhere at runtime -- it is
     * implicit in which `*PageSearchPublisherPlugin`/equivalent a project has registered -- so this MUST
     * be configured per project; there is no generic way to derive it.
     *
     * Ships the tables actually observed feeding the `page` source identifier on a stock
     * spryker-shop/b2b-demo-marketplace install as a working example/default -- table names verified via
     * `SHOW TABLES`, and (unlike an earlier draft of this method) every column actually verified via
     * `DESCRIBE` too: `spy_configurable_bundle_template_page_search` and `spy_product_set_page_search`
     * have NO `store` column at all (confirmed live: assuming one produced a real `Unknown column
     * 'store'` SQL error from BulkLoader), unlike every other table here. A project with a different set
     * of publish plugins registered against `page` (or that manages additional sourceIdentifiers beyond
     * `page`) MUST override this -- and should verify each table's real columns the same way, not assume
     * the `store`/`locale` pattern holds everywhere.
     *
     * @return array<string, array<\SprykerCommunity\Zed\SearchIndexAlias\SpySearchSourceTable>>
     */
    public function getSpySearchSourceTables(): array
    {
        return [
            'page' => [
                new SpySearchSourceTable('spy_product_abstract_page_search'),
                new SpySearchSourceTable('spy_product_concrete_page_search'),
                new SpySearchSourceTable('spy_category_node_page_search'),
                new SpySearchSourceTable('spy_cms_page_search'),
                new SpySearchSourceTable('spy_configurable_bundle_template_page_search', storeColumn: null),
                new SpySearchSourceTable('spy_product_set_page_search', storeColumn: null),
            ],
        ];
    }

    /**
     * Maps a managed sourceIdentifier to the name of the RabbitMQ exchange its publish/sync pipeline
     * writes to -- the mirror queue binds here to capture live deltas during a rebuild (see README "How
     * it works"). Like `getSpySearchSourceTables()`, Spryker exposes no generic way to derive this; it
     * must be configured per project. Defaults to `sync.search.<sourceIdentifier>`, which matches this
     * package's own `page` -> `sync.search.product` case only via the explicit override below -- the
     * naming is NOT actually uniform across resources (confirmed live: category/cms/merchant all have
     * their own distinctly-named exchanges), so relying on the guessed default for any managed
     * sourceIdentifier beyond what's been explicitly verified is a mistake waiting to happen.
     *
     * @return array<string, string>
     */
    public function getSyncExchangeNames(): array
    {
        return [
            'page' => 'sync.search.product',
        ];
    }

    /**
     * @param string $sourceIdentifier
     */
    public function getSyncExchangeNameForSourceIdentifier(string $sourceIdentifier): string
    {
        return $this->getSyncExchangeNames()[$sourceIdentifier] ?? sprintf('sync.search.%s', $sourceIdentifier);
    }

    public function getKeepIndicesCount(): int
    {
        return static::DEFAULT_KEEP_INDICES;
    }

    /**
     * Whether a rebuild that reaches READY flips automatically, or waits for an explicit
     * `search-index-alias:flip` / GUI confirmation. `false` (manual) is the safer default for a first
     * install; a project confident in its verification gate can override this to `true` for
     * fully-unattended deploy-pipeline rebuilds.
     */
    public function isAutoFlipEnabled(): bool
    {
        return false;
    }

    public function getRebuildRequestQueueName(): string
    {
        return static::REBUILD_REQUEST_QUEUE_NAME;
    }
}
