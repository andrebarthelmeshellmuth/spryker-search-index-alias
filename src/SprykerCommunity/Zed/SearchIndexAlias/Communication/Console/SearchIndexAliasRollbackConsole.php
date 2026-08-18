<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Exception\ConcurrentRolloutException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasRollbackConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:rollback';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Atomically flips a scope\'s alias directly to an already-existing physical index -- typically an older, superseded one a previous flip left behind. See search-index-alias:status for which physical index is currently aliased.';

    /**
     * @var string
     */
    protected const ARGUMENT_TARGET_INDEX = 'target-index';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::REQUIRED, 'The scope\'s alias name, as shown by search-index-alias:status.');
        $this->addArgument(static::ARGUMENT_TARGET_INDEX, InputArgument::REQUIRED, 'The physical index name to roll back to -- must already exist.');

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
        /** @var string $targetIndexName */
        $targetIndexName = $input->getArgument(static::ARGUMENT_TARGET_INDEX);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $output->writeln(sprintf('<error>No managed scope found for alias "%s".</error>', $aliasName));

            return static::CODE_ERROR;
        }

        try {
            $searchIndexRolloutTransfer = $this->getFacade()->rollbackToIndex($searchIndexScopeTransfer, $targetIndexName, get_current_user() ?: 'console');
        } catch (ConcurrentRolloutException $concurrentRolloutException) {
            $output->writeln(sprintf('<error>%s</error>', $concurrentRolloutException->getMessage()));

            return static::CODE_ERROR;
        }

        if ($searchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_FAILED) {
            $output->writeln(sprintf('<error>Rollback failed: %s</error>', $searchIndexRolloutTransfer->getFailureReason() ?? 'unknown reason'));

            return static::CODE_ERROR;
        }

        $output->writeln(sprintf('<info>"%s" rolled back to "%s".</info>', $aliasName, $targetIndexName));

        return static::CODE_SUCCESS;
    }
}
