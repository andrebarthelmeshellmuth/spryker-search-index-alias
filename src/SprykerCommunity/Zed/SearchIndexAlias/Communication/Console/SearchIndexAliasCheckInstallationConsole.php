<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use DateTime;
use FilesystemIterator;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTargetQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use Spryker\Shared\Config\Config;
use Spryker\Shared\Kernel\KernelConstants;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Diagnoses a search-index-alias installation. Mirrors the sibling packages' own
 * `*:check-installation` commands (see {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCheckInstallationConsole}
 * for the fullest example of the pattern) -- almost every one of this package's own install steps fails
 * SILENTLY when missed, so this names the exact remedy for whatever is wrong rather than leaving an
 * operator to discover it the hard way (a rebuild that quietly writes zero documents, a page nobody in a
 * restricted role can reach).
 *
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Communication\SearchIndexAliasCommunicationFactory getFactory()
 */
class SearchIndexAliasCheckInstallationConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:check-installation';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Diagnoses a search-index-alias installation: core namespace, sibling console commands, the Propel table, navigation, Elasticsearch/RabbitMQ reachability, whether every managed scope has rebuild config, back-office ACL access, the Zed translation catalog, and stale flip-pending flags.';

    /**
     * @var string
     */
    protected const CORE_NAMESPACE = 'SprykerCommunity';

    /**
     * @var array<class-string>
     */
    protected const SIBLING_COMMAND_CLASSES = [
        SearchIndexAliasStatusConsole::class,
        SearchIndexAliasAdoptConsole::class,
        SearchIndexAliasRebuildConsole::class,
        SearchIndexAliasRebuildWorkerConsole::class,
        SearchIndexAliasFlipConsole::class,
        SearchIndexAliasAbortConsole::class,
        SearchIndexAliasPruneConsole::class,
        SearchIndexAliasHealthConsole::class,
        SearchIndexAliasRollbackConsole::class,
        SearchIndexAliasMarkFlipPendingConsole::class,
        SearchIndexAliasMarkRollbackPendingConsole::class,
        SearchIndexAliasDeployFlipConsole::class,
    ];

    /**
     * @var string
     */
    protected const OWN_NAVIGATION_XML_RELATIVE_PATH = '/../navigation.xml';

    /**
     * A key guaranteed to exist in data/translation/Zed/en_US.csv (the Overview page's own widget
     * title) -- resolving it through the real TranslatorFacade proves the project actually wired the
     * `spryker-community/*` glob and rebuilt the cache, not just that the CSV file exists on disk.
     *
     * @var string
     */
    protected const KNOWN_ZED_TRANSLATION_KEY = 'Search Index Alias';

    /**
     * @var int
     */
    protected const STALE_PENDING_FLIP_HOURS = 24;

    /**
     * @var string
     */
    protected const KNOWN_ZED_TRANSLATION_LOCALE = 'en_US';

    /**
     * @var string
     */
    protected const TRANSLATION_REFERENCE_LOCALE = 'en_US';

    /**
     * From this file's own __DIR__ (Communication/Console/) up to the package root.
     *
     * @var string
     */
    protected const PACKAGE_ROOT_RELATIVE_PATH = '/../../../../../..';

    /**
     * @var string
     */
    protected const PATTERN_TWIG_TRANS = '/(?<![\\w\\\\])([\'"])((?:\\\\.|(?!\\1).)*)\\1\\s*\\|\\s*trans/';

    /**
     * @var string
     */
    protected const PATTERN_PHP_TRANS = '/->(?:trans|translate)\\(\\s*([\'"])((?:\\\\.|(?!\\1).)*)\\1/';

    /**
     * @var array<string>
     */
    protected array $failures = [];

    /**
     * @var array<string>
     */
    protected array $warnings = [];

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);

        parent::configure();
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $input is mandated by the Console base class.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkCoreNamespace($output);
        $this->checkSiblingCommandsRegistered($output);
        $this->checkPropelTableExists($output);
        $this->checkNavigationRegistered($output);
        $this->checkElasticsearchReachable($output);
        $this->checkRabbitMqManagementApiReachable($output);
        $this->checkManagedScopesHaveRebuildConfig($output);
        $this->checkBackOfficeAccess($output);
        $this->checkZedTranslationRegistered($output);
        $this->checkZedTranslationCatalogComplete($output);
        $this->checkStalePendingFlips($output);

        $output->writeln('');

        foreach ($this->warnings as $warning) {
            $output->writeln(sprintf('<comment>! %s</comment>', $warning));
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $output->writeln(sprintf('<error>✗ %s</error>', $failure));
            }

            return static::CODE_ERROR;
        }

        $output->writeln('<info>Everything checkable from the CLI is in place.</info>');

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkCoreNamespace(OutputInterface $output): void
    {
        $coreNamespaces = Config::get(KernelConstants::CORE_NAMESPACES, []);

        if (in_array(static::CORE_NAMESPACE, $coreNamespaces, true)) {
            $output->writeln(sprintf('<info>✓</info> core namespace "%s" is registered', static::CORE_NAMESPACE));

            return;
        }

        $this->failures[] = sprintf(
            'Core namespace "%s" is NOT registered. Add it to KernelConstants::CORE_NAMESPACES in config/Shared/config_default.php.',
            static::CORE_NAMESPACE,
        );
    }

    /**
     * Deliberately does NOT use `$this->getApplication()->has(...)` -- confirmed live in this package's
     * own development that, from inside a running command's own `execute()`, the Application instance
     * this resolves to reports a much smaller command set than `vendor/bin/console list` shows for the
     * SAME environment (51 vs. the real total), so `has()` false-negatives on commands that are
     * genuinely registered and working. Checking this package's own `Communication/Console` directory
     * instead only confirms the package SHIPS these classes (always true for a released version) --
     * whether a project actually wired them into its own `ConsoleDependencyProvider` is not verifiable
     * from here and is called out as a manual step instead.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkSiblingCommandsRegistered(OutputInterface $output): void
    {
        $missingClasses = [];

        foreach (static::SIBLING_COMMAND_CLASSES as $commandClass) {
            if (class_exists($commandClass)) {
                continue;
            }

            $missingClasses[] = $commandClass;
        }

        if ($missingClasses !== []) {
            $this->failures[] = sprintf(
                'The following console command classes could not be autoloaded: %s. This indicates a broken installation of the package itself.',
                implode(', ', $missingClasses),
            );

            return;
        }

        $output->writeln(sprintf('<info>✓</info> all %d console command classes are present', count(static::SIBLING_COMMAND_CLASSES)));
        $output->writeln(sprintf('  (confirm they are wired into your project\'s ConsoleDependencyProvider by hand: `vendor/bin/console list search-index-alias` should list all %d)', count(static::SIBLING_COMMAND_CLASSES) + 1));
    }

    /**
     * A project that never ran `propel:install`/`propel:migrate` after requiring this package gets a
     * hard PHP fatal the first time any rollout is created, rather than a graceful error -- this surfaces
     * that up front.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkPropelTableExists(OutputInterface $output): void
    {
        try {
            (new SpySearchIndexRolloutQuery())->count();
        } catch (Throwable) {
            $this->failures[] = 'The "spy_search_index_rollout" table is missing or unreachable. Run `vendor/bin/console propel:install` (or propel:migrate) after installing this package.';

            return;
        }

        try {
            (new SpySearchIndexDeployRollbackTargetQuery())->count();
        } catch (Throwable) {
            $this->failures[] = 'The "spy_search_index_deploy_rollback_target" table is missing or unreachable. Run `vendor/bin/console propel:install` (or propel:migrate) after installing this package -- this table was added alongside "flip_pending" for deploy-time rollback flagging (see README, "Deploying") and needs the same migration as spy_search_index_rollout.';

            return;
        }

        $output->writeln('<info>✓</info> the "spy_search_index_rollout" and "spy_search_index_deploy_rollback_target" tables exist and are queryable');
    }

    /**
     * Zed navigation has no glob auto-discovery for `vendor/spryker-community/*`, so a project copies
     * this package's own `<search-index-alias-gui>` block into `config/Zed/navigation.xml` by hand -- and
     * the omission never errors, the entry is simply absent from the sidebar.
     *
     * Two copy patterns are both valid (see this package's own README, "Nesting under a shared parent"):
     * a full literal copy (every leaf page present verbatim), or a CHILDLESS copy of just the wrapper
     * entry (`label`/`title`/`bundle`/`controller`/`action`, no nested `<pages>`) relying on
     * `BreadcrumbNavigationMergeStrategy` to adopt this package's own leaf pages automatically at
     * build-cache time. Checking only for the flat presence of every leaf key (as an earlier version of
     * this check did) produces a false "missing" failure under the childless pattern, even though the
     * pages genuinely render -- so a childless-but-present wrapper counts as fully satisfied here.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkNavigationRegistered(OutputInterface $output): void
    {
        $expectedWrapperPageKeys = $this->readOwnNavigationWrapperPageKeys();
        $projectWrapperChildKeys = $this->readProjectNavigationWrapperChildKeys();

        if ($expectedWrapperPageKeys === [] || $projectWrapperChildKeys === null) {
            $this->warnings[] = 'Could not compare this package\'s navigation entries against the project\'s own. Confirm by hand that this package\'s pages appear in the Zed sidebar.';

            return;
        }

        $missingPageKeys = [];
        $totalExpected = 0;

        foreach ($expectedWrapperPageKeys as $wrapperKey => $leafKeys) {
            $totalExpected += 1 + count($leafKeys);

            if (!array_key_exists($wrapperKey, $projectWrapperChildKeys)) {
                $missingPageKeys[] = $wrapperKey;
                array_push($missingPageKeys, ...$leafKeys);

                continue;
            }

            $projectChildKeysForWrapper = $projectWrapperChildKeys[$wrapperKey];

            if ($projectChildKeysForWrapper === []) {
                // Childless copy -- BreadcrumbNavigationMergeStrategy adopts this wrapper's own leaf
                // pages wholesale, nothing more to check.
                continue;
            }

            foreach (array_diff($leafKeys, $projectChildKeysForWrapper) as $missingLeafKey) {
                $missingPageKeys[] = $missingLeafKey;
            }
        }

        if ($missingPageKeys === []) {
            $output->writeln(sprintf('<info>✓</info> all %d navigation entries are registered in config/Zed/navigation.xml', $totalExpected));

            return;
        }

        $this->failures[] = sprintf(
            'These navigation entries are missing from config/Zed/navigation.xml: %s. Copy the <search-index-alias-gui> block from this package\'s own Communication/navigation.xml (either in full, or just the wrapper entry left childless so its pages merge in automatically -- see the README), then run navigation:build-cache.',
            implode(', ', $missingPageKeys),
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkElasticsearchReachable(OutputInterface $output): void
    {
        try {
            $response = $this->getFactory()->createElasticaClientProvider()->getClient()->request('_cluster/health', 'GET');
        } catch (Throwable $throwable) {
            $this->failures[] = sprintf('Could not reach Elasticsearch/OpenSearch: %s', $throwable->getMessage());

            return;
        }

        // Deliberately NOT $response->isOk(): `_cluster/health`'s own JSON body has a `status` key
        // (the string "green"/"yellow"/"red") which collides with Elastica's isOk() implementation --
        // that method reads `$data['status']` FIRST and treats it as an HTTP status code range check
        // (`>= 200 && <= 300`) whenever the key is present, so it misreads this endpoint's response and
        // reports failure even on a perfectly healthy, reachable cluster. Confirmed live: a real
        // single-node dev cluster (status "yellow" -- expected, its replica shards have nowhere to be
        // assigned) is falsely reported as unreachable this way. The real HTTP status is on the Response
        // object itself, not the decoded body.
        $clusterStatus = $response->getData()['status'] ?? null;

        if ($response->getStatus() < 200 || $response->getStatus() >= 300 || $clusterStatus === 'red') {
            $this->failures[] = sprintf(
                'Elasticsearch/OpenSearch did not respond healthily to a cluster health check (HTTP %d, cluster status "%s").',
                $response->getStatus(),
                (string)$clusterStatus,
            );

            return;
        }

        $output->writeln(sprintf('<info>✓</info> Elasticsearch/OpenSearch is reachable (cluster status "%s")', (string)$clusterStatus));
    }

    /**
     * A round trip against the RabbitMQ Management HTTP API specifically -- separate from, and
     * additional to, the real AMQP connection every mirror-queue drain also needs. Confirmed in this
     * package's own development that these can genuinely disagree: a container may reach the broker over
     * AMQP (a mirror queue binds and drains fine) while the Management API config
     * (`SPRYKER_BROKER_API_*`) is unset in that same environment.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkRabbitMqManagementApiReachable(OutputInterface $output): void
    {
        $probeQueueName = 'search-index-alias.check-installation.probe';

        try {
            $rabbitMqManagementClient = $this->getFactory()->createRabbitMqManagementClient();
            $rabbitMqManagementClient->declareQueue($probeQueueName);
            $rabbitMqManagementClient->deleteQueue($probeQueueName);
        } catch (Throwable $throwable) {
            $this->failures[] = sprintf(
                'Could not reach the RabbitMQ Management HTTP API: %s. Confirm SPRYKER_BROKER_API_HOST/PORT/USERNAME/PASSWORD are set in this environment (distinct from the plain AMQP connection settings).',
                $throwable->getMessage(),
            );

            return;
        }

        $output->writeln('<info>✓</info> the RabbitMQ Management HTTP API is reachable');
    }

    /**
     * `getSpySearchSourceTables()`/`getSyncExchangeNames()` ship configured for the `page` source
     * identifier only (see their own doc blocks) -- every OTHER managed, already-adopted scope can be
     * rebuilt but will silently write zero documents (an empty bulk load, no matching exchange) unless a
     * project overrides those two methods. Confirmed live: this is exactly what happened the first time
     * this package's own console tooling was exercised against a real multi-scope install.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkManagedScopesHaveRebuildConfig(OutputInterface $output): void
    {
        $searchIndexAliasConfig = $this->getFactory()->getConfig();
        $configuredSourceIdentifiers = array_keys($searchIndexAliasConfig->getSpySearchSourceTables());

        $unconfiguredSourceIdentifiers = [];

        foreach ($this->getFacade()->getManagedScopes() as $searchIndexScopeTransfer) {
            $sourceIdentifier = $searchIndexScopeTransfer->getSourceIdentifier();

            if ($sourceIdentifier === null || in_array($sourceIdentifier, $configuredSourceIdentifiers, true)) {
                continue;
            }

            $unconfiguredSourceIdentifiers[$sourceIdentifier] = true;
        }

        if ($unconfiguredSourceIdentifiers === []) {
            $output->writeln('<info>✓</info> every managed sourceIdentifier has rebuild config (getSpySearchSourceTables())');

            return;
        }

        $this->warnings[] = sprintf(
            'These managed sourceIdentifier(s) have no entry in SearchIndexAliasConfig::getSpySearchSourceTables(): %s. They can still be adopted, but a rebuild will silently bulk-load zero documents. Override getSpySearchSourceTables() (and getSyncExchangeNames() if the default "sync.search.<sourceIdentifier>" guess is wrong) in your project\'s Pyz\Zed\SearchIndexAlias\SearchIndexAliasConfig.',
            implode(', ', array_keys($unconfiguredSourceIdentifiers)),
        );
    }

    /**
     * A default Spryker install needs nothing done: `root_role` carries a total wildcard and every
     * installer user sits in `root_group`, so this package's pages -- Rebuild, Flip, Abort, Roll back,
     * Delete, all of which act on real search infrastructure -- are reachable the moment the package is
     * installed. That is worth flagging MORE prominently here than the read-only-GUI case the same check
     * covers in sibling packages: an adopter who wants these specific actions restricted to a named role
     * needs to know a default install does NOT do that on its own, and has to add the ACL rule
     * themselves (Maintenance > Users & Rights > Roles).
     *
     * Still only a WARNING, never a failure -- keeping these pages to root-style admins is a legitimate,
     * deliberate choice for a small back office, and this command cannot know which roles an adopter
     * meant to restrict.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkBackOfficeAccess(OutputInterface $output): void
    {
        $moduleNames = $this->readOwnNavigationModuleNames();

        if ($moduleNames === []) {
            $this->warnings[] = 'Could not read this package\'s own navigation.xml, so back-office access could not be checked. Confirm by hand which Zed roles can reach the Search Index Alias pages -- Rebuild/Flip/Abort/Roll back/Delete all act on real search infrastructure.';

            return;
        }

        $diagnosisTransfer = $this->getFactory()->createBackOfficeAccessAnalyzer()->analyze($moduleNames);
        $restrictedRoleCount = $diagnosisTransfer->getRestrictedRoleCountOrFail();
        $unrestrictedRoleCount = $diagnosisTransfer->getUnrestrictedRoleCountOrFail();

        if ($unrestrictedRoleCount > 0) {
            $this->warnings[] = sprintf(
                '%d unrestricted (root-style) back-office role(s) can reach this package\'s pages -- Rebuild/Flip/Abort/Roll back/Delete all act on real search infrastructure. This is the default and is fine for a small back office, but if you want these actions restricted to a named role, add an ACL rule for %s scoped to that role and either remove or narrow the wildcard role(s) that currently reach it (Maintenance > Users & Rights > Roles).',
                $unrestrictedRoleCount,
                implode('/', $moduleNames),
            );

            return;
        }

        $restrictedRoleWithAccessCount = $diagnosisTransfer->getRestrictedRoleWithAccessCountOrFail();

        if ($restrictedRoleWithAccessCount > 0) {
            $output->writeln(sprintf(
                '<info>✓</info> no unrestricted back-office role can reach this package\'s pages -- %d of %d restricted role(s) have an explicit ACL rule for %s',
                $restrictedRoleWithAccessCount,
                $restrictedRoleCount,
                implode('/', $moduleNames),
            ));

            return;
        }

        $this->warnings[] = sprintf(
            'No back-office role -- unrestricted or restricted -- has an ACL rule reaching %s. Nobody can reach the Search Index Alias pages at all; if that is intended (e.g. managed exclusively via the console commands), nothing to do. Otherwise add a rule for the role that should manage it.',
            implode('/', $moduleNames),
        );
    }

    /**
     * Every string in the Overview/History pages falls back to its own raw English text when
     * untranslated, so a project that never wires the `spryker-community/*` translation glob (README
     * step 6) sees nothing WRONG, just nothing translated -- this resolves a known key through the real
     * TranslatorFacade to catch that silently-degraded state explicitly.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkZedTranslationRegistered(OutputInterface $output): void
    {
        if ($this->getFactory()->getTranslatorFacade()->has(static::KNOWN_ZED_TRANSLATION_KEY, static::KNOWN_ZED_TRANSLATION_LOCALE)) {
            $output->writeln('<info>✓</info> the Zed GUI translation catalog is loaded');

            return;
        }

        $this->failures[] = sprintf(
            'The Zed translation catalog does not resolve "%s". Add the spryker-community/* glob to Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns() (README step 6), then run translator:clean-cache and translator:generate-cache.',
            static::KNOWN_ZED_TRANSLATION_KEY,
        );
    }

    /**
     * Scans this package's OWN Twig/PHP sources for every `| trans`/`->trans()` string and diffs it
     * against `data/translation/Zed/en_US.csv` -- a defect in the package itself (a string shipped
     * without a matching catalog entry, e.g. the table headers this exact gap caught before this check
     * existed), not something a project misconfigured. Failing here, unlike the check above, is
     * therefore never something an adopter can fix on their own.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkZedTranslationCatalogComplete(OutputInterface $output): void
    {
        $usedKeys = $this->collectUsedZedTranslationKeys();
        $catalogKeys = $this->readZedTranslationCatalogKeys(static::TRANSLATION_REFERENCE_LOCALE);

        if ($usedKeys === [] || $catalogKeys === null) {
            $this->warnings[] = 'Could not compare this package\'s Zed translation catalog against the strings its GUI uses (sources or catalog unreadable). Nothing to act on unless you are working on the package itself.';

            return;
        }

        $missingKeys = array_values(array_diff($usedKeys, $catalogKeys));

        if ($missingKeys === []) {
            $output->writeln(sprintf('<info>✓</info> all %d Zed GUI strings are present in the translation catalog', count($usedKeys)));

            return;
        }

        $this->failures[] = sprintf(
            '%d Zed GUI string(s) are missing from data/translation/Zed/%s.csv and will render untranslated in any non-English Zed: "%s". This is a defect in the package itself, not in your project setup.',
            count($missingKeys),
            static::TRANSLATION_REFERENCE_LOCALE,
            implode('", "', array_slice($missingKeys, 0, 8)) . (count($missingKeys) > 8 ? '", ...' : ''),
        );
    }

    /**
     * A scope flagged flip-pending sits there until either `search-index-alias:deploy-flip` runs (from
     * the deploy pipeline) or an operator flips/aborts it by hand -- there is no automatic expiry. A flag
     * that has been sitting for a long time almost always means one of two things: a deploy pipeline that
     * never actually calls `deploy-flip` (the hook is misconfigured, see README "Deploying"), or a
     * forgotten flag left over from a rollout an operator meant to review further. Either way it is worth
     * surfacing, not silently carrying forward into whatever the next deploy happens to be.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkStalePendingFlips(OutputInterface $output): void
    {
        $staleAfterHours = static::STALE_PENDING_FLIP_HOURS;
        $staleThreshold = (new DateTime())->modify(sprintf('-%d hours', $staleAfterHours));

        $staleAliasNames = [];

        foreach ($this->getFacade()->findPendingFlipCandidates() as $searchIndexRolloutTransfer) {
            $startedAt = $searchIndexRolloutTransfer->getStartedAt();

            if ($startedAt !== null && new DateTime($startedAt) > $staleThreshold) {
                continue;
            }

            $staleAliasNames[] = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getAliasName();
        }

        if ($staleAliasNames === []) {
            $output->writeln('<info>✓</info> no scope has had a pending deploy-time flip or rollback flagged for longer than ' . $staleAfterHours . 'h');

            return;
        }

        $this->warnings[] = sprintf(
            'These scopes have had a pending deploy-time flip or rollback flagged for more than %dh without it running: %s. Either your deploy pipeline never runs `search-index-alias:deploy-flip` (check SPRYKER_HOOK_AFTER_DEPLOY), or this is a forgotten flag -- review on the Overview page.',
            $staleAfterHours,
            implode(', ', $staleAliasNames),
        );
    }

    /**
     * @return array<string>
     */
    protected function collectUsedZedTranslationKeys(): array
    {
        $zedSourcePath = __DIR__ . static::PACKAGE_ROOT_RELATIVE_PATH . '/src/SprykerCommunity/Zed';

        if (!is_dir($zedSourcePath)) {
            return [];
        }

        $keys = [];
        $directoryIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($zedSourcePath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directoryIterator as $fileInfo) {
            if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), ['twig', 'php'], true)) {
                continue;
            }

            $keys = array_merge($keys, $this->extractTranslationKeys((string)file_get_contents($fileInfo->getPathname())));
        }

        return array_values(array_unique($keys));
    }

    /**
     * Skips anything interpolated (`~`, `{{ }}`) or a path-looking value (a `trans()` call on an
     * unrelated object, e.g. a URL builder, would otherwise false-positive) -- those are built at
     * runtime and cannot be matched against a static catalog.
     *
     * @param string $source
     *
     * @return array<string>
     */
    protected function extractTranslationKeys(string $source): array
    {
        $keys = [];

        foreach ([static::PATTERN_TWIG_TRANS, static::PATTERN_PHP_TRANS] as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = str_replace(['\\\'', '\\"'], ['\'', '"'], $match[2]);

                if (str_contains($key, '{') || str_contains($key, '~') || str_starts_with($key, '/')) {
                    continue;
                }

                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param string $locale
     *
     * @return array<string>|null
     */
    protected function readZedTranslationCatalogKeys(string $locale): ?array
    {
        $catalogPath = sprintf('%s%s/data/translation/Zed/%s.csv', __DIR__, static::PACKAGE_ROOT_RELATIVE_PATH, $locale);

        if (!is_readable($catalogPath)) {
            return null;
        }

        $handle = fopen($catalogPath, 'r');

        if ($handle === false) {
            return null;
        }

        $keys = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (!isset($row[0]) || trim((string)$row[0]) === '') {
                continue;
            }

            $keys[] = (string)$row[0];
        }

        fclose($handle);

        return $keys;
    }

    /**
     * @return array<string>
     */
    protected function readOwnNavigationModuleNames(): array
    {
        $ownNavigationXml = $this->loadXml(__DIR__ . static::OWN_NAVIGATION_XML_RELATIVE_PATH);

        if ($ownNavigationXml === null) {
            return [];
        }

        $moduleNames = [];

        foreach ($ownNavigationXml->xpath('//bundle') ?: [] as $bundleElement) {
            $moduleNames[(string)$bundleElement] = true;
        }

        return array_keys($moduleNames);
    }

    /**
     * @return array<string, array<int, string>> Wrapper page key => its own leaf page keys.
     */
    protected function readOwnNavigationWrapperPageKeys(): array
    {
        $ownNavigationXml = $this->loadXml(__DIR__ . static::OWN_NAVIGATION_XML_RELATIVE_PATH);

        if ($ownNavigationXml === null) {
            return [];
        }

        $wrapperPageKeys = [];

        foreach ($ownNavigationXml->children() as $rootEntry) {
            $leafKeys = [];

            foreach ($rootEntry->pages->children() as $page) {
                $leafKeys[] = $page->getName();
            }

            $wrapperPageKeys[$rootEntry->getName()] = $leafKeys;
        }

        return $wrapperPageKeys;
    }

    /**
     * Finds this package's own wrapper key(s) anywhere in the project's navigation tree, at any nesting
     * depth (a project may nest it under a shared parent category rather than at the root) -- and reports
     * each one's own direct `<pages>` children, so the caller can tell a full literal copy from a
     * deliberately childless one relying on merge-time auto-adoption.
     *
     * @return array<string, array<int, string>>|null Wrapper page key => its direct children in the
     *  project's own file (empty array for a childless copy); null if the project file couldn't be read.
     */
    protected function readProjectNavigationWrapperChildKeys(): ?array
    {
        $projectNavigationXml = $this->loadXml(APPLICATION_ROOT_DIR . '/config/Zed/navigation.xml');

        if ($projectNavigationXml === null) {
            return null;
        }

        $wrapperChildKeys = [];

        foreach (array_keys($this->readOwnNavigationWrapperPageKeys()) as $wrapperKey) {
            $matches = $projectNavigationXml->xpath("//{$wrapperKey}") ?: [];

            if ($matches === []) {
                continue;
            }

            $childKeys = [];

            foreach ($matches[0]->xpath('pages/*') ?: [] as $child) {
                $childKeys[] = $child->getName();
            }

            $wrapperChildKeys[$wrapperKey] = $childKeys;
        }

        return $wrapperChildKeys;
    }

    /**
     * @param string $filePath
     */
    protected function loadXml(string $filePath): ?SimpleXMLElement
    {
        if (!is_readable($filePath)) {
            return null;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)file_get_contents($filePath));
        libxml_use_internal_errors($previousUseInternalErrors);

        return $xml === false ? null : $xml;
    }
}
