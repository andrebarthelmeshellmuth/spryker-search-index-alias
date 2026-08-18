<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface getFacade()
 */
class SearchIndexAliasStatusConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:status';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Shows every managed (store, sourceIdentifier) scope, whether it is adopted yet, and its most recent rollout.';

    /**
     * @var string
     */
    public const ARGUMENT_ALIAS = 'alias';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::OPTIONAL, 'Limit to a single alias name (default: show every managed scope).');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $aliasFilter */
        $aliasFilter = $input->getArgument(static::ARGUMENT_ALIAS);

        $latestRolloutByScopeKey = [];
        foreach ($this->getFacade()->getLatestRolloutPerScope() as $searchIndexRolloutTransfer) {
            $searchIndexScopeTransfer = $searchIndexRolloutTransfer->getSearchIndexScopeOrFail();
            $key = sprintf('%s:%s', $searchIndexScopeTransfer->getSourceIdentifier(), $searchIndexScopeTransfer->getStoreName());
            $latestRolloutByScopeKey[$key] = $searchIndexRolloutTransfer;
        }

        $table = new Table($output);
        $table->setHeaders(['Alias', 'Source', 'Store', 'Adopted?', 'Last Rollout Status', 'Target Index', 'Docs', 'Started At']);

        $rowCount = 0;

        foreach ($this->getFacade()->getManagedScopes() as $searchIndexScopeTransfer) {
            if ($aliasFilter !== null && $searchIndexScopeTransfer->getAliasName() !== $aliasFilter) {
                continue;
            }

            $key = sprintf('%s:%s', $searchIndexScopeTransfer->getSourceIdentifier(), $searchIndexScopeTransfer->getStoreName());
            $searchIndexRolloutTransfer = $latestRolloutByScopeKey[$key] ?? null;
            $adopted = $this->getFacade()->needsAdoption($searchIndexScopeTransfer) ? '<comment>NO -- needs adoption</comment>' : 'yes';

            $table->addRow([
                $searchIndexScopeTransfer->getAliasName(),
                $searchIndexScopeTransfer->getSourceIdentifier(),
                $searchIndexScopeTransfer->getStoreName(),
                $adopted,
                $searchIndexRolloutTransfer?->getStatus() ?? '-',
                $searchIndexRolloutTransfer?->getTargetIndexName() ?? '-',
                $searchIndexRolloutTransfer?->getActualDocumentCount() ?? '-',
                $searchIndexRolloutTransfer?->getStartedAt() ?? '-',
            ]);
            $rowCount++;
        }

        $table->render();

        if ($aliasFilter !== null && $rowCount === 0) {
            $this->error(sprintf('No managed scope found for alias "%s".', $aliasFilter));

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
