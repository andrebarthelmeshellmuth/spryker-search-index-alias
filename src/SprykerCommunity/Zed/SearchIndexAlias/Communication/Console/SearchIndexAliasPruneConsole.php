<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasPruneConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:prune';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Deletes old, unaliased physical indices for a scope, keeping the configured number of most recent ones as a rollback buffer. Never touches the live (aliased) index.';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::REQUIRED, 'The scope\'s alias name, as shown by search-index-alias:status.');

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

        $deletedIndexNames = $this->getFacade()->pruneScope($searchIndexScopeTransfer);

        if ($deletedIndexNames === []) {
            $output->writeln(sprintf('<info>Nothing to prune for "%s".</info>', $aliasName));

            return static::CODE_SUCCESS;
        }

        $output->writeln(sprintf('<info>Deleted %d old index(es) for "%s": %s</info>', count($deletedIndexNames), $aliasName, implode(', ', $deletedIndexNames)));

        return static::CODE_SUCCESS;
    }
}
