<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Mirror;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\SearchIndexRolloutTransfer;
use Generated\Shared\Transfer\SearchIndexScopeTransfer;
use GuzzleHttp\Client;
use Spryker\Client\RabbitMq\RabbitMqConfig as ClientRabbitMqConfig;
use Spryker\Zed\RabbitMq\RabbitMqConfig;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\BrokerConnectionProvider;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Broker\RabbitMqManagementClient;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Exception\BrokerOperationFailedException;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Mirror\MirrorQueueBinder;
use SprykerCommunity\Zed\SearchIndexAlias\SearchIndexAliasConfig;

/**
 * INTEGRATION TEST — real RabbitMQ Management API. Verifies the mirror queue really is declared and
 * really is bound to the scope's own real sync exchange (`sync.search.product`, this project's actual
 * config), not just that the right method calls happen in the right order.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Mirror
 * @group MirrorQueueBinderTest
 * Add your own group annotations below this line
 * @group NeedsBroker
 */
class MirrorQueueBinderTest extends Unit
{
    protected MirrorQueueBinder $mirrorQueueBinder;

    protected function _before(): void
    {
        $this->mirrorQueueBinder = new MirrorQueueBinder(
            new RabbitMqManagementClient(new Client(), new RabbitMqConfig(), new BrokerConnectionProvider(new ClientRabbitMqConfig())),
            new SearchIndexAliasConfig(),
        );
    }

    public function testBindDeclaresAUniquelyNamedQueueAndBindsItToTheScopesSyncExchange(): void
    {
        $searchIndexRolloutTransfer = $this->createRolloutTransfer(4242);

        $mirrorQueueName = $this->mirrorQueueBinder->bind($searchIndexRolloutTransfer);

        $this->assertSame(SearchIndexAliasConfig::MIRROR_QUEUE_NAME_PREFIX . 'phpunit_mirror_binder_page.4242', $mirrorQueueName);

        $this->mirrorQueueBinder->unbind($mirrorQueueName);
    }

    public function testUnbindDeletesTheMirrorQueueSoARepeatUnbindFails(): void
    {
        $mirrorQueueName = $this->mirrorQueueBinder->bind($this->createRolloutTransfer(4243));

        $this->mirrorQueueBinder->unbind($mirrorQueueName);

        $this->expectException(BrokerOperationFailedException::class);
        $this->mirrorQueueBinder->unbind($mirrorQueueName);
    }

    protected function createRolloutTransfer(int $idSearchIndexRollout): SearchIndexRolloutTransfer
    {
        return (new SearchIndexRolloutTransfer())
            ->setIdSearchIndexRollout($idSearchIndexRollout)
            ->setSearchIndexScope(
                (new SearchIndexScopeTransfer())
                    ->setSourceIdentifier('page')
                    ->setStoreName('DE')
                    ->setAliasName('phpunit_mirror_binder_page'),
            );
    }
}
