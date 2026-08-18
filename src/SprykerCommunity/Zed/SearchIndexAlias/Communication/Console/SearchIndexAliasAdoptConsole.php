<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\AdoptionNotApplicableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasAdoptConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:adopt';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Migrates an existing installation\'s concrete index into an alias, atomically and with zero downtime. Run once per scope, before its first rebuild.';

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

        if (!$this->getFacade()->needsAdoption($searchIndexScopeTransfer)) {
            $output->writeln(sprintf('<info>"%s" is already an alias -- nothing to adopt.</info>', $aliasName));

            return static::CODE_SUCCESS;
        }

        try {
            $newIndexName = $this->getFacade()->adopt($searchIndexScopeTransfer);
        } catch (AdoptionNotApplicableException $adoptionNotApplicableException) {
            $output->writeln(sprintf('<error>%s</error>', $adoptionNotApplicableException->getMessage()));

            return static::CODE_ERROR;
        }

        $output->writeln(sprintf('<info>"%s" is now an alias pointing at "%s".</info>', $aliasName, $newIndexName));

        return static::CODE_SUCCESS;
    }
}
