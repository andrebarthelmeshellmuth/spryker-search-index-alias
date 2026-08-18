<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Shared\SearchIndexAlias\SearchIndexAliasConfig;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The deploy pipeline's entrypoint: flips every managed scope's rollout that is READY and was explicitly
 * flagged flip-pending (via the Overview page's toggle or search-index-alias:mark-flip-pending), one
 * scope at a time. Intended to run from `SPRYKER_HOOK_AFTER_DEPLOY` -- see `config/install/post-deploy.yml`
 * and the README's "Deploying" section -- since that hook fires only once the new application code is
 * already live, which is exactly the timing a query/mapping change coupled to that code needs.
 *
 * Deliberately does NOT flip every READY rollout it finds: an unflagged READY rollout may have been
 * built ahead of time and still be under manual review, not yet meant to go live. See
 * DeployFlipRunner's own class doc block.
 *
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
class SearchIndexAliasDeployFlipConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:deploy-flip';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Flips every managed scope\'s READY, flip-pending rollout. Intended for the deploy pipeline (SPRYKER_HOOK_AFTER_DEPLOY) -- exits non-zero if any flip failed, so CI can gate on it.';

    /**
     * @var string
     */
    protected const OPTION_DRY_RUN = 'dry-run';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addOption(static::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'List what would flip without flipping anything.');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool)$input->getOption(static::OPTION_DRY_RUN)) {
            return $this->renderDryRun($output);
        }

        $searchIndexRolloutTransfers = $this->getFacade()->deployFlipPending();

        if ($searchIndexRolloutTransfers === []) {
            $output->writeln('<info>No scope is flagged flip-pending -- nothing to do.</info>');

            return static::CODE_SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Alias', 'Result', 'Target Index']);

        $failedCount = 0;

        foreach ($searchIndexRolloutTransfers as $searchIndexRolloutTransfer) {
            $flipped = $searchIndexRolloutTransfer->getStatus() === SearchIndexAliasConfig::STATUS_FLIPPED;
            $failedCount += $flipped ? 0 : 1;

            $table->addRow([
                $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getAliasName(),
                $flipped ? '<info>flipped</info>' : sprintf('<error>failed: %s</error>', $searchIndexRolloutTransfer->getFailureReason() ?? 'unknown reason'),
                $searchIndexRolloutTransfer->getTargetIndexName() ?? '-',
            ]);
        }

        $table->render();

        if ($failedCount > 0) {
            $output->writeln(sprintf('<error>%d of %d flip(s) failed.</error>', $failedCount, count($searchIndexRolloutTransfers)));

            return static::CODE_ERROR;
        }

        $output->writeln(sprintf('<info>%d scope(s) flipped.</info>', count($searchIndexRolloutTransfers)));

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function renderDryRun(OutputInterface $output): int
    {
        $candidates = $this->getFacade()->findPendingFlipCandidates();

        if ($candidates === []) {
            $output->writeln('<info>No scope is flagged flip-pending -- a deploy right now would flip nothing.</info>');

            return static::CODE_SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Alias', 'Target Index', 'Docs']);

        foreach ($candidates as $searchIndexRolloutTransfer) {
            $table->addRow([
                $searchIndexRolloutTransfer->getSearchIndexScopeOrFail()->getAliasName(),
                $searchIndexRolloutTransfer->getTargetIndexName() ?? '-',
                $searchIndexRolloutTransfer->getActualDocumentCount() ?? '-',
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<comment>%d scope(s) would flip on the next deploy.</comment>', count($candidates)));

        return static::CODE_SUCCESS;
    }
}
