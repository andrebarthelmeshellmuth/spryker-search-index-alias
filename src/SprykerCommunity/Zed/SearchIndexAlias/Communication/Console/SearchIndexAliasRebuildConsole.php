<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasRebuildConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:rebuild';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Starts a blue-green rebuild for an already-adopted scope: builds a fresh target index, bulk-loads it, and converges a mirror queue against it. The live index is never touched.';

    /**
     * @var string
     */
    public const OPTION_MAPPING_FILE = 'mapping-file';

    /**
     * @var string
     */
    public const OPTION_USER = 'user';

    /**
     * @var string
     */
    public const OPTION_OPTIMIZE = 'optimize';

    /**
     * @var string
     */
    public const OPTION_FROM_LIVE = 'from-live';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::REQUIRED, 'The scope\'s alias name, as shown by search-index-alias:status.');
        $this->addOption(
            static::OPTION_MAPPING_FILE,
            null,
            InputOption::VALUE_REQUIRED,
            'Path to a JSON file of the form {"properties": {...}} to layer on top of the new target\'s ' .
            'mapping -- omit to rebuild with the mapping unchanged (e.g. to recover from suspected drift). ' .
            'Still layers on top of the schema-built base (the default -- see --from-live).',
        );
        $this->addOption(static::OPTION_USER, null, InputOption::VALUE_REQUIRED, 'Recorded on the rollout row as who triggered it.');
        $this->addOption(
            static::OPTION_OPTIMIZE,
            null,
            InputOption::VALUE_NONE,
            'Disable refresh/replicas on the target index for the duration of the bulk load, restoring both ' .
            'afterward -- speeds up large bulk loads at the cost of near-real-time search on the target ' .
            'until it converges.',
        );
        $this->addOption(
            static::OPTION_FROM_LIVE,
            null,
            InputOption::VALUE_NONE,
            'Build the target\'s base mapping+settings by cloning the live index instead of the project\'s ' .
            'own Shared/Search/Schema/*.json definition(s) (the default). Use this to recover from suspected ' .
            'schema.json drift, or when live has a mapping/settings change (e.g. a manual patch) not yet ' .
            'reflected in schema.json. See the README for the full tradeoff.',
        );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $aliasName */
        $aliasName = $input->getArgument(static::ARGUMENT_ALIAS);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $output->writeln(sprintf('<error>No managed scope found for alias "%s".</error>', $aliasName));

            return static::CODE_ERROR;
        }

        $targetMappingProperties = $this->readMappingFile($input, $output);

        if ($targetMappingProperties === false) {
            return static::CODE_ERROR;
        }

        /** @var string|null $optionUser */
        $optionUser = $input->getOption(static::OPTION_USER);
        $triggeredByUser = $optionUser ?: (get_current_user() ?: 'console');
        $optimizeForBulkLoad = (bool)$input->getOption(static::OPTION_OPTIMIZE);
        $fromSchema = !$input->getOption(static::OPTION_FROM_LIVE);

        try {
            $searchIndexRolloutTransfer = $this->getFacade()->startRebuild($searchIndexScopeTransfer, $triggeredByUser, $targetMappingProperties, $optimizeForBulkLoad, $fromSchema);
        } catch (ConcurrentRolloutException $concurrentRolloutException) {
            $output->writeln(sprintf('<error>%s</error>', $concurrentRolloutException->getMessage()));

            return static::CODE_ERROR;
        }

        if ($searchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_FAILED) {
            $output->writeln(sprintf('<error>Rollout failed: %s</error>', $searchIndexRolloutTransfer->getFailureReason() ?? 'unknown reason'));

            return static::CODE_ERROR;
        }

        $this->reportResult($output, $aliasName, $searchIndexRolloutTransfer);

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $aliasName
     * @param \Generated\Shared\Transfer\SearchIndexRolloutTransfer $searchIndexRolloutTransfer
     */
    protected function reportResult(OutputInterface $output, string $aliasName, SearchIndexRolloutTransfer $searchIndexRolloutTransfer): void
    {
        $searchIndexMappingDiffTransfer = $searchIndexRolloutTransfer->getSearchIndexMappingDiff();

        if ($searchIndexMappingDiffTransfer !== null && $searchIndexMappingDiffTransfer->getClassification() === SearchIndexAliasConfig::MAPPING_DIFF_BREAKING) {
            $output->writeln(sprintf(
                '<comment>Mapping diff includes breaking field(s): %s -- this is fine for the rebuild itself (the ' .
                'target is a fresh index), but do not run search:setup against the LIVE index with this ' .
                'same mapping change.</comment>',
                implode(', ', $searchIndexMappingDiffTransfer->getBreakingFields()),
            ));
        }

        $output->writeln(sprintf(
            '<info>Rollout %d for "%s" is now %s (target=%s, docs=%s).</info>',
            $searchIndexRolloutTransfer->getIdSearchIndexRollout() ?? 0,
            $aliasName,
            $searchIndexRolloutTransfer->getStatus(),
            $searchIndexRolloutTransfer->getTargetIndexName() ?? '-',
            $searchIndexRolloutTransfer->getActualDocumentCount() ?? '-',
        ));

        if ($searchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_READY) {
            return;
        }

        $output->writeln(sprintf('<info>Run `search-index-alias:flip %s` once you\'re ready to switch live traffic to it.</info>', $aliasName));
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return array<string, mixed>|false|null Null when no mapping file was given, false on a read/parse
     *  error (the caller returns CODE_ERROR in that case).
     */
    protected function readMappingFile(InputInterface $input, OutputInterface $output): array|null|false
    {
        /** @var string|null $path */
        $path = $input->getOption(static::OPTION_MAPPING_FILE);

        if ($path === null) {
            return null;
        }

        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $output->writeln(sprintf('<error>Could not read mapping file "%s".</error>', $path));

            return false;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || !isset($decoded['properties']) || !is_array($decoded['properties'])) {
            $output->writeln(sprintf('<error>Mapping file "%s" must be a JSON object of the form {"properties": {...}}.</error>', $path));

            return false;
        }

        return $decoded;
    }
}
