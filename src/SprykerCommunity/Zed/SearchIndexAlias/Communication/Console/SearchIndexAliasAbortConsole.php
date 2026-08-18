<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasAbortConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:abort';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Aborts a scope\'s active rollout: drops its target index and mirror queue. The live index is never touched, so this is a clean no-op as far as live traffic is concerned.';

    /**
     * @var string
     */
    public const OPTION_REASON = 'reason';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::REQUIRED, 'The scope\'s alias name, as shown by search-index-alias:status.');
        $this->addOption(static::OPTION_REASON, null, InputOption::VALUE_REQUIRED, 'Recorded on the rollout row.', 'aborted via console');

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
        /** @var string $reason */
        $reason = $input->getOption(static::OPTION_REASON);

        $searchIndexScopeTransfer = $this->findScopeByAlias($aliasName);

        if ($searchIndexScopeTransfer === null) {
            $output->writeln(sprintf('<error>No managed scope found for alias "%s".</error>', $aliasName));

            return static::CODE_ERROR;
        }

        $searchIndexRolloutTransfer = $this->getFacade()->getActiveRollout($searchIndexScopeTransfer);

        if ($searchIndexRolloutTransfer === null) {
            $output->writeln(sprintf('<error>No active rollout for "%s" -- nothing to abort.</error>', $aliasName));

            return static::CODE_ERROR;
        }

        $abortedSearchIndexRolloutTransfer = $this->getFacade()->abortRollout($searchIndexRolloutTransfer, $reason);

        if ($abortedSearchIndexRolloutTransfer->getStatus() !== SearchIndexAliasConfig::STATUS_ABORTED) {
            $output->writeln(sprintf('<error>Abort did not complete cleanly: status=%s</error>', $abortedSearchIndexRolloutTransfer->getStatus()));

            return static::CODE_ERROR;
        }

        $output->writeln(sprintf('<info>Rollout %d for "%s" aborted. Live traffic was never affected.</info>', $abortedSearchIndexRolloutTransfer->getIdSearchIndexRollout() ?? 0, $aliasName));

        return static::CODE_SUCCESS;
    }
}
