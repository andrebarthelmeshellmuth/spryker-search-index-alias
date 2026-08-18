<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasCheckInstallationConsole;

/**
 * PORTABLE — the pure string-processing core of `checkZedTranslationCatalogComplete()`: extracting
 * `trans`-filtered keys from source text, and diffing them against the real catalog. This is the one
 * install-check branch {@see SearchIndexAliasCheckInstallationConsoleTest} cannot exercise (it only ever
 * sees this demoshop's real, currently-complete state) -- these tests prove the diff logic itself would
 * actually catch a real gap, by fabricating one rather than needing this package's own source to be
 * broken.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasCheckInstallationConsoleTranslationLogicTest
 * Add your own group annotations below this line
 * @group Portable
 */
class SearchIndexAliasCheckInstallationConsoleTranslationLogicTest extends Unit
{
    public function testExtractTranslationKeysReadsATwigTransFilterString(): void
    {
        $keys = $this->exposeExtractTranslationKeys("<p>{{ 'Rebuild' | trans }}</p>");

        $this->assertSame(['Rebuild'], $keys);
    }

    public function testExtractTranslationKeysReadsAPhpTransCallString(): void
    {
        $keys = $this->exposeExtractTranslationKeys("\$this->trans('Deleted index \"%s\".');");

        $this->assertContains('Deleted index "%s".', $keys);
    }

    public function testExtractTranslationKeysSkipsAKeyContainingAPlaceholderBrace(): void
    {
        // A placeholder-style key (curly braces inside the string itself, filled in at runtime) cannot
        // be matched against a static catalog entry -- see the method's own doc block for why this is
        // deliberate, not a missed case.
        $keys = $this->exposeExtractTranslationKeys("{{ 'Rebuild {name}' | trans }}");

        $this->assertSame([], $keys);
    }

    public function testExtractTranslationKeysHandlesMultipleKeysInOneSource(): void
    {
        $source = "{{ 'Source' | trans }} {{ 'Store' | trans }} {{ 'Actions' | trans }}";

        $keys = $this->exposeExtractTranslationKeys($source);

        $this->assertSame(['Source', 'Store', 'Actions'], $keys);
    }

    /**
     * The real, checked-in `data/translation/Zed/en_US.csv` -- confirms the CSV-reading half of the
     * diff independently of the source-scanning half, against this package's own real catalog file (a
     * plain filesystem read, no host shop needed).
     */
    public function testReadZedTranslationCatalogKeysReadsTheRealEnUsCatalog(): void
    {
        $keys = $this->exposeReadZedTranslationCatalogKeys('en_US');

        $this->assertNotNull($keys);
        $this->assertContains('Rebuild', $keys);
        $this->assertContains('Search Index Alias', $keys);
    }

    public function testReadZedTranslationCatalogKeysReturnsNullForANonExistentLocale(): void
    {
        $keys = $this->exposeReadZedTranslationCatalogKeys('xx_XX');

        $this->assertNull($keys);
    }

    /**
     * The actual regression-guard scenario `checkZedTranslationCatalogComplete()` exists to catch:
     * fabricates a source string using a key that genuinely does NOT exist in the real catalog, and
     * confirms the diff correctly flags it as missing -- proving the check would catch a real gap
     * (like the untranslated-table-headers bug this check was built in response to), without needing
     * this package's own real source to actually be broken to prove it.
     */
    public function testDiffingExtractedKeysAgainstTheRealCatalogCatchesAGenuinelyMissingOne(): void
    {
        $usedKeys = $this->exposeExtractTranslationKeys(
            "{{ 'Rebuild' | trans }} {{ 'This string was never added to the catalog' | trans }}",
        );
        $catalogKeys = $this->exposeReadZedTranslationCatalogKeys('en_US');

        $this->assertNotNull($catalogKeys);
        $missingKeys = array_values(array_diff($usedKeys, $catalogKeys));

        $this->assertSame(['This string was never added to the catalog'], $missingKeys);
    }

    protected function exposeExtractTranslationKeys(string $source): array
    {
        return $this->createConsole()->exposeExtractTranslationKeys($source);
    }

    protected function exposeReadZedTranslationCatalogKeys(string $locale): ?array
    {
        return $this->createConsole()->exposeReadZedTranslationCatalogKeys($locale);
    }

    protected function createConsole(): SearchIndexAliasCheckInstallationConsole
    {
        return new class extends SearchIndexAliasCheckInstallationConsole {
            /**
             * @return array<string>
             */
            public function exposeExtractTranslationKeys(string $source): array
            {
                return $this->extractTranslationKeys($source);
            }

            /**
             * @return array<string>|null
             */
            public function exposeReadZedTranslationCatalogKeys(string $locale): ?array
            {
                return $this->readZedTranslationCatalogKeys($locale);
            }
        };
    }
}
