<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
class SearchIndexAliasRebuildWorkerConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:rebuild-worker';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Consumes queued rebuild requests submitted from the Zed GUI (see requestRebuildAsync()) -- ' .
        'runs the same heavy build/bulk-load/converge work as search-index-alias:rebuild, off the HTTP request. ' .
        'Must be running for GUI-triggered rebuilds to ever complete; long-running by default like queue:worker:start.';

    /**
     * @var string
     */
    public const OPTION_STOP_WHEN_EMPTY = 'stop-when-empty';

    /**
     * @var string
     */
    public const OPTION_STOP_WHEN_EMPTY_SHORT = 's';

    /**
     * @var int
     */
    protected const POLL_INTERVAL_SECONDS = 2;

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addOption(
            static::OPTION_STOP_WHEN_EMPTY,
            static::OPTION_STOP_WHEN_EMPTY_SHORT,
            InputOption::VALUE_NONE,
            'Process everything currently queued, then exit, instead of polling forever.',
        );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopWhenEmpty = (bool)$input->getOption(static::OPTION_STOP_WHEN_EMPTY);

        $output->writeln('<info>search-index-alias rebuild worker started.</info>');

        do {
            $processed = $this->getFacade()->consumeOneRebuildRequest();

            if ($processed) {
                $output->writeln('<info>Processed a queued rebuild request.</info>');

                continue;
            }

            if ($stopWhenEmpty) {
                break;
            }

            sleep(static::POLL_INTERVAL_SECONDS);
        } while (true);

        $output->writeln('<info>search-index-alias rebuild worker stopped.</info>');

        return static::CODE_SUCCESS;
    }
}
