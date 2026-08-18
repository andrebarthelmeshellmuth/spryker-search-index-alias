<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchIndexAliasHealthConsole extends AbstractScopeConsole
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-index-alias:health';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Detects alias drift: a scope whose alias does not exist yet, or resolves to more than one physical index simultaneously (never produced by this package\'s own operations).';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);
        $this->addArgument(static::ARGUMENT_ALIAS, InputArgument::OPTIONAL, 'Limit to a single alias name (default: check every managed scope).');

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

        if ($aliasFilter !== null) {
            $searchIndexScopeTransfer = $this->findScopeByAlias($aliasFilter);

            if ($searchIndexScopeTransfer === null) {
                $output->writeln(sprintf('<error>No managed scope found for alias "%s".</error>', $aliasFilter));

                return static::CODE_ERROR;
            }

            $searchIndexHealthTransfers = [$this->getFacade()->checkScopeHealth($searchIndexScopeTransfer)];
        } else {
            $searchIndexHealthTransfers = $this->getFacade()->checkAllManagedScopesHealth()->getSearchIndexHealths()->getArrayCopy();
        }

        $table = new Table($output);
        $table->setHeaders(['Alias', 'Healthy?', 'Aliased indices', 'Docs', 'Issues']);

        $unhealthyCount = 0;

        foreach ($searchIndexHealthTransfers as $searchIndexHealthTransfer) {
            if (!$searchIndexHealthTransfer->getIsHealthy()) {
                $unhealthyCount++;
            }

            $table->addRow([
                $searchIndexHealthTransfer->getSearchIndexScopeOrFail()->getAliasName(),
                $searchIndexHealthTransfer->getIsHealthy() ? 'yes' : 'NO',
                implode(', ', $searchIndexHealthTransfer->getAliasedIndexNames()) ?: '-',
                $searchIndexHealthTransfer->getDocumentCount(),
                implode(' ', $searchIndexHealthTransfer->getIssues()) ?: '-',
            ]);
        }

        $table->render();

        if ($unhealthyCount > 0) {
            $output->writeln(sprintf('<error>%d scope(s) have an issue.</error>', $unhealthyCount));

            return static::CODE_ERROR;
        }

        $output->writeln('<info>All checked scopes are healthy.</info>');

        return static::CODE_SUCCESS;
    }
}
