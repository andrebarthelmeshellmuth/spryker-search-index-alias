<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\RollbackTargetNotApplicableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasMarkRollbackPendingConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:mark-rollback-pending';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Flags (or, with --off, unflags) an already-existing physical index as "flip to this the next time search-index-alias:deploy-flip runs" -- the rollback counterpart to mark-flip-pending. Mutually exclusive with it: flagging one clears the other. Does not flip anything itself.';

    /**
     * @var string
     */
    protected const ARGUMENT_TARGET_INDEX = 'target-index';

    /**
     * @var string
     */
    protected const OPTION_OFF = 'off';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::REQUIRED, 'The scope\'s alias name, as shown by search-index-alias:status.');
        $this->addArgument(static::ARGUMENT_TARGET_INDEX, InputArgument::OPTIONAL, 'The physical index name to roll back to -- must already exist. Not needed with --off.');
        $this->addOption(static::OPTION_OFF, null, InputOption::VALUE_NONE, 'Unflag instead of flagging.');

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
        /** @var string|null $targetIndexName */
        $targetIndexName = $input->getArgument(static::ARGUMENT_TARGET_INDEX);
        $off = (bool)$input->getOption(static::OPTION_OFF);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $output->writeln(sprintf('<error>No managed scope found for alias "%s".</error>', $aliasName));

            return static::CODE_ERROR;
        }

        if ($off) {
            $this->getFacade()->unmarkPendingRollback($searchIndexScopeTransfer);
            $output->writeln(sprintf('<info>"%s" is no longer flagged for a deploy-time rollback.</info>', $aliasName));

            return static::CODE_SUCCESS;
        }

        if ($targetIndexName === null) {
            $output->writeln('<error>A target index is required unless --off is given.</error>');

            return static::CODE_ERROR;
        }

        try {
            $this->getFacade()->markPendingRollback($searchIndexScopeTransfer, $targetIndexName, get_current_user() ?: 'console');
        } catch (RollbackTargetNotApplicableException $rollbackTargetNotApplicableException) {
            $output->writeln(sprintf('<error>%s</error>', $rollbackTargetNotApplicableException->getMessage()));

            return static::CODE_ERROR;
        }

        $output->writeln(sprintf('<info>"%s" will roll back to "%s" on the next deploy.</info>', $aliasName, $targetIndexName));

        return static::CODE_SUCCESS;
    }
}
