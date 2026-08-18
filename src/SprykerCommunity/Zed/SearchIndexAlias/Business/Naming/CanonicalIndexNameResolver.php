<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Business\Naming;

use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;

/**
 * Deliberate, faithful reimplementation of
 * `Spryker\Zed\SearchElasticsearch\Business\SourceIdentifier\SourceIdentifier::translateToIndexName()`
 * (verified against search-elasticsearch 1.23), NOT a dependency on that class directly.
 *
 * That class is an internal Business-layer collaborator of another module, not exposed through
 * `SearchElasticsearchFacadeInterface` -- depending on it directly would mean depending on another
 * module's implementation detail rather than its public contract, with no compatibility guarantee across
 * releases. `SearchElasticsearchConfig` (used below) is, by contrast, explicitly meant to be shared
 * across modules in Spryker's architecture (plain PHP, no DI entanglement, marked `@api` where it
 * matters) -- so this class reads the exact same config a project already maintains and reproduces core's
 * own algorithm against it, guaranteeing byte-identical names without an internal-class dependency.
 *
 * If a project overrides its own `SourceIdentifier`/`IndexNameResolver` with materially different
 * naming logic (not just different config values), this class will silently diverge from it -- there is
 * no way around that short of depending on the internal class after all. Verified live 2026-08-15
 * against this package's own reference install (spryker-shop/b2b-demo-marketplace, real
 * `SPRYKER_SEARCH_INDEX_PREFIX=spryker_b2b_marketplace_dev` and its configured source identifiers): this
 * exact algorithm produced `spryker_b2b_marketplace_dev_de_page`, `..._at_page`, `..._de_merchant` and
 * `..._at_product-review` for the corresponding (sourceIdentifier, storeName) pairs, each matching a real
 * index name present in `_cat/indices` on that shop's live OpenSearch cluster.
 */
class CanonicalIndexNameResolver implements CanonicalIndexNameResolverInterface
{
    /**
     * @var string
     */
    protected const STORE_PREFIX_DELIMITER = '_';

    /**
     * @param \Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig $searchElasticsearchConfig
     */
    public function __construct(protected SearchElasticsearchConfig $searchElasticsearchConfig)
    {
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function resolve(string $sourceIdentifier, string $storeName): string
    {
        if ($this->isPrefixedWithStoreName($sourceIdentifier)) {
            return $this->buildIndexName($sourceIdentifier, null);
        }

        return $this->buildIndexName($sourceIdentifier, $storeName);
    }

    /**
     * @param string $sourceIdentifier
     * @param string $storeName
     */
    public function isSupported(string $sourceIdentifier, string $storeName): bool
    {
        $configSourceIdentifier = $this->findMatchingConfigSourceIdentifier($sourceIdentifier);

        if ($configSourceIdentifier === null) {
            return false;
        }

        if ($sourceIdentifier === $configSourceIdentifier) {
            return true;
        }

        return mb_strtolower($storeName . static::STORE_PREFIX_DELIMITER . $configSourceIdentifier) === $sourceIdentifier;
    }

    /**
     * @param string $sourceIdentifier
     */
    protected function isPrefixedWithStoreName(string $sourceIdentifier): bool
    {
        $configSourceIdentifier = $this->findMatchingConfigSourceIdentifier($sourceIdentifier);

        if ($configSourceIdentifier === null) {
            return false;
        }

        return mb_strpos($sourceIdentifier, $configSourceIdentifier) > 0;
    }

    /**
     * @param string $sourceIdentifier
     * @param string|null $currentStore
     */
    protected function buildIndexName(string $sourceIdentifier, ?string $currentStore): string
    {
        $indexParameters = [
            $this->searchElasticsearchConfig->getIndexPrefix(),
            $currentStore,
            $sourceIdentifier,
        ];

        return mb_strtolower(implode('_', array_filter($indexParameters)));
    }

    /**
     * @param string $sourceIdentifier
     */
    protected function findMatchingConfigSourceIdentifier(string $sourceIdentifier): ?string
    {
        foreach ($this->searchElasticsearchConfig->getSupportedSourceIdentifiers() as $supportedSourceIdentifier) {
            if (preg_match(sprintf('/(.+%s)?%s$/', static::STORE_PREFIX_DELIMITER, preg_quote($supportedSourceIdentifier, '/')), $sourceIdentifier)) {
                return $supportedSourceIdentifier;
            }
        }

        return null;
    }
}
