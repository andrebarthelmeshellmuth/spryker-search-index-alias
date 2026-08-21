<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Console;

use Codeception\Test\Unit;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasCheckInstallationConsole;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * PORTABLE — covers every check-installation helper that touches only the filesystem or plain PHP
 * reflection, none of Locator/getFactory()/getFacade(): `checkSiblingCommandsRegistered()`, the
 * navigation-XML readers (`readOwnNavigationModuleNames()`/`readOwnNavigationWrapperPageKeys()`/
 * `loadXml()`), `checkNavigationRegistered()`'s own merge/diff branches (exercised with canned reader
 * output rather than a real project navigation.xml, since this package's own standalone CI bootstrap has
 * none), and `collectUsedZedTranslationKeys()`'s real scan of this package's own source tree. Every other
 * check (Elasticsearch/RabbitMQ reachability, the Propel table, back-office ACL, the Zed translation
 * catalog being LOADED as opposed to complete) needs a real host shop and stays covered by
 * {@see SearchIndexAliasCheckInstallationConsoleTest} instead.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Console
 * @group SearchIndexAliasCheckInstallationConsolePortableChecksTest
 * Add your own group annotations below this line
 * @group Portable
 */
class SearchIndexAliasCheckInstallationConsolePortableChecksTest extends Unit
{
    public function testCheckSiblingCommandsRegisteredReportsSuccessWhenEveryClassExists(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();
        $output = new BufferedOutput();

        $this->invoke($console, 'checkSiblingCommandsRegistered', $output);

        $this->assertStringContainsString('all 12 console command classes are present', $output->fetch());
        $this->assertSame([], $this->readProperty($console, 'failures'));
    }

    public function testCheckSiblingCommandsRegisteredFailsWhenAClassIsMissing(): void
    {
        $console = new class extends SearchIndexAliasCheckInstallationConsole {
            protected const SIBLING_COMMAND_CLASSES = ['SprykerCommunity\NotARealClass'];
        };
        $output = new BufferedOutput();

        $this->invoke($console, 'checkSiblingCommandsRegistered', $output);

        $failures = $this->readProperty($console, 'failures');
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('SprykerCommunity\NotARealClass', $failures[0]);
    }

    public function testReadOwnNavigationModuleNamesReadsTheRealNavigationXml(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();

        $moduleNames = $this->invoke($console, 'readOwnNavigationModuleNames');

        $this->assertSame(['search-index-alias'], $moduleNames);
    }

    public function testReadOwnNavigationWrapperPageKeysReadsTheRealNavigationXml(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();

        $wrapperPageKeys = $this->invoke($console, 'readOwnNavigationWrapperPageKeys');

        $this->assertSame([
            'search-index-alias-gui' => [
                'search-index-alias-overview',
                'search-index-alias-history',
                'search-index-alias-adopt',
                'search-index-alias-rebuild',
                'search-index-alias-flip',
                'search-index-alias-abort',
            ],
        ], $wrapperPageKeys);
    }

    public function testLoadXmlReturnsNullForAnUnreadablePath(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();

        $this->assertNull($this->invoke($console, 'loadXml', __DIR__ . '/does-not-exist.xml'));
    }

    public function testLoadXmlParsesTheRealOwnNavigationXml(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();
        $ownNavigationPath = dirname((new ReflectionClass(SearchIndexAliasCheckInstallationConsole::class))->getFileName())
            . '/../navigation.xml';

        $xml = $this->invoke($console, 'loadXml', $ownNavigationPath);

        $this->assertNotNull($xml);
        $this->assertSame('Search Index Alias', (string)$xml->{'search-index-alias-gui'}->label);
    }

    public function testCheckNavigationRegisteredWarnsWhenEitherSideCannotBeRead(): void
    {
        $console = $this->buildConsoleWithCannedNavigationReaders([], null);
        $output = new BufferedOutput();

        $this->invoke($console, 'checkNavigationRegistered', $output);

        $this->assertCount(1, $this->readProperty($console, 'warnings'));
        $this->assertSame([], $this->readProperty($console, 'failures'));
    }

    public function testCheckNavigationRegisteredSucceedsForAFullLiteralCopy(): void
    {
        $console = $this->buildConsoleWithCannedNavigationReaders(
            ['search-index-alias-gui' => ['search-index-alias-overview', 'search-index-alias-history']],
            ['search-index-alias-gui' => ['search-index-alias-overview', 'search-index-alias-history']],
        );
        $output = new BufferedOutput();

        $this->invoke($console, 'checkNavigationRegistered', $output);

        $this->assertSame([], $this->readProperty($console, 'failures'));
        $this->assertSame([], $this->readProperty($console, 'warnings'));
        $this->assertStringContainsString('all 3 navigation entries are registered', $output->fetch());
    }

    public function testCheckNavigationRegisteredSucceedsForAChildlessCopy(): void
    {
        // An empty child array for the wrapper key means BreadcrumbNavigationMergeStrategy adopts its
        // leaf pages wholesale at build-cache time -- nothing missing despite the child list being empty.
        $console = $this->buildConsoleWithCannedNavigationReaders(
            ['search-index-alias-gui' => ['search-index-alias-overview', 'search-index-alias-history']],
            ['search-index-alias-gui' => []],
        );
        $output = new BufferedOutput();

        $this->invoke($console, 'checkNavigationRegistered', $output);

        $this->assertSame([], $this->readProperty($console, 'failures'));
        $this->assertSame([], $this->readProperty($console, 'warnings'));
    }

    public function testCheckNavigationRegisteredFailsWhenAWrapperIsEntirelyMissing(): void
    {
        $console = $this->buildConsoleWithCannedNavigationReaders(
            ['search-index-alias-gui' => ['search-index-alias-overview']],
            [],
        );
        $output = new BufferedOutput();

        $this->invoke($console, 'checkNavigationRegistered', $output);

        $failures = $this->readProperty($console, 'failures');
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('search-index-alias-gui', $failures[0]);
        $this->assertStringContainsString('search-index-alias-overview', $failures[0]);
    }

    public function testCheckNavigationRegisteredFailsWhenALeafPageIsMissingFromAFullCopy(): void
    {
        $console = $this->buildConsoleWithCannedNavigationReaders(
            ['search-index-alias-gui' => ['search-index-alias-overview', 'search-index-alias-history']],
            ['search-index-alias-gui' => ['search-index-alias-overview']],
        );
        $output = new BufferedOutput();

        $this->invoke($console, 'checkNavigationRegistered', $output);

        $failures = $this->readProperty($console, 'failures');
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('search-index-alias-history', $failures[0]);
        $this->assertStringNotContainsString('search-index-alias-overview,', $failures[0]);
    }

    public function testCollectUsedZedTranslationKeysScansThisPackagesOwnRealSource(): void
    {
        $console = new SearchIndexAliasCheckInstallationConsole();

        $keys = $this->invoke($console, 'collectUsedZedTranslationKeys');

        $this->assertNotEmpty($keys);
        $this->assertContains('Rebuild', $keys, 'A key known to be used by this package\'s own Zed GUI must be found by a real scan of its source tree.');
    }

    /**
     * @param array<string, array<int, string>> $ownWrapperPageKeys
     * @param array<string, array<int, string>>|null $projectWrapperChildKeys
     */
    protected function buildConsoleWithCannedNavigationReaders(
        array $ownWrapperPageKeys,
        ?array $projectWrapperChildKeys,
    ): SearchIndexAliasCheckInstallationConsole {
        return new class ($ownWrapperPageKeys, $projectWrapperChildKeys) extends SearchIndexAliasCheckInstallationConsole {
            /**
             * @param array<string, array<int, string>> $ownWrapperPageKeys
             * @param array<string, array<int, string>>|null $projectWrapperChildKeys
             */
            public function __construct(
                protected array $ownWrapperPageKeys,
                protected ?array $projectWrapperChildKeys,
            ) {
                parent::__construct();
            }

            protected function readOwnNavigationWrapperPageKeys(): array
            {
                return $this->ownWrapperPageKeys;
            }

            protected function readProjectNavigationWrapperChildKeys(): ?array
            {
                return $this->projectWrapperChildKeys;
            }
        };
    }

    /**
     * @return mixed
     */
    protected function invoke(SearchIndexAliasCheckInstallationConsole $console, string $methodName, mixed ...$arguments)
    {
        $method = new ReflectionMethod($console, $methodName);

        return $method->invoke($console, ...$arguments);
    }

    /**
     * @return mixed
     */
    protected function readProperty(SearchIndexAliasCheckInstallationConsole $console, string $propertyName)
    {
        $property = new ReflectionProperty($console, $propertyName);

        return $property->getValue($console);
    }
}
