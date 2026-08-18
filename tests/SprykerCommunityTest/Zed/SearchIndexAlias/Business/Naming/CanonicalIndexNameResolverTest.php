<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Naming;

use Codeception\Test\Unit;
use Spryker\Zed\SearchElasticsearch\SearchElasticsearchConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Naming\CanonicalIndexNameResolver;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Naming
 * @group CanonicalIndexNameResolverTest
 * Add your own group annotations below this line
 * @group Portable
 */
class CanonicalIndexNameResolverTest extends Unit
{
    public function testResolveBuildsStorePrefixedNameForASourceIdentifierNotAlreadyStorePrefixed(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertSame('myshop_de_page', $resolver->resolve('page', 'DE'));
    }

    public function testResolveDoesNotDoublePrefixASourceIdentifierAlreadyContainingAStoreName(): void
    {
        // The configured base identifier is "page", but the incoming sourceIdentifier is already the
        // store-prefixed variant "de_page" -- resolve() must not add a second store segment on top of
        // the one already present.
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertSame('myshop_de_page', $resolver->resolve('de_page', 'DE'));
    }

    public function testResolveLowercasesTheResult(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('MyShop', ['Page']));

        $this->assertSame('myshop_de_page', $resolver->resolve('Page', 'DE'));
    }

    public function testIsSupportedAcceptsAConfiguredSourceIdentifierUnchanged(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertTrue($resolver->isSupported('page', 'DE'));
    }

    public function testIsSupportedAcceptsAStorePrefixedVariantOfAConfiguredSourceIdentifier(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertTrue($resolver->isSupported('de_page', 'DE'));
    }

    public function testIsSupportedRejectsAStorePrefixMismatchedToTheGivenStore(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertFalse($resolver->isSupported('at_page', 'DE'));
    }

    public function testIsSupportedRejectsASourceIdentifierNotInTheConfiguredList(): void
    {
        $resolver = new CanonicalIndexNameResolver($this->createConfig('myshop', ['page']));

        $this->assertFalse($resolver->isSupported('merchant', 'DE'));
    }

    /**
     * @param string $indexPrefix
     * @param array<string> $supportedSourceIdentifiers
     */
    protected function createConfig(string $indexPrefix, array $supportedSourceIdentifiers): SearchElasticsearchConfig
    {
        return new class ($indexPrefix, $supportedSourceIdentifiers) extends SearchElasticsearchConfig {
            /**
             * @param string $indexPrefix
             * @param array<string> $supportedSourceIdentifiers
             */
            public function __construct(protected string $indexPrefix, protected array $supportedSourceIdentifiers)
            {
            }

            /**
             * @return array<string>
             */
            public function getSupportedSourceIdentifiers(): array
            {
                return $this->supportedSourceIdentifiers;
            }

            public function getIndexPrefix(): string
            {
                return $this->indexPrefix;
            }
        };
    }
}
