<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Business\Rebuild;

use Codeception\Test\Unit;
use ReflectionMethod;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestrator;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildOrchestratorInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisher;
use SprykerCommunity\Zed\SearchIndexAlias\Business\Rebuild\RebuildRequestPublisherInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacade;
use SprykerCommunity\Zed\SearchIndexAlias\Business\SearchIndexAliasFacadeInterface;

/**
 * A caller that omits `$fromSchema` entirely (an existing project's rebuild console/GUI/cron call, not
 * yet updated) must get the NEW default -- this is the whole reason flipping this default is a major,
 * not minor, version: identical calling code now behaves differently. Reflection, not a live-cluster
 * integration test, because proving the default's runtime effect would need a scope whose alias resolves
 * in this demoshop's real schema.json (RebuildOrchestratorTest's throwaway scopes deliberately do NOT,
 * to avoid colliding with this repo's real "page" rollout history) -- the default's VALUE is what this
 * class guards, not the resolver's own behavior (see SchemaIndexDefinitionResolverTest for that).
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Business
 * @group Rebuild
 * @group RebuildFromSchemaDefaultTest
 * Add your own group annotations below this line
 */
class RebuildFromSchemaDefaultTest extends Unit
{
    public function testEveryFromSchemaParameterDefaultsToTrue(): void
    {
        $methodsByClassAndName = [
            [RebuildOrchestrator::class, 'start'],
            [RebuildOrchestrator::class, 'requestRebuildAsync'],
            [RebuildOrchestrator::class, 'executeQueuedRebuild'],
            [RebuildOrchestratorInterface::class, 'start'],
            [RebuildOrchestratorInterface::class, 'requestRebuildAsync'],
            [RebuildOrchestratorInterface::class, 'executeQueuedRebuild'],
            [RebuildRequestPublisher::class, 'publish'],
            [RebuildRequestPublisherInterface::class, 'publish'],
            [SearchIndexAliasFacade::class, 'startRebuild'],
            [SearchIndexAliasFacade::class, 'requestRebuildAsync'],
            [SearchIndexAliasFacadeInterface::class, 'startRebuild'],
            [SearchIndexAliasFacadeInterface::class, 'requestRebuildAsync'],
        ];

        foreach ($methodsByClassAndName as [$class, $methodName]) {
            $reflectionMethod = new ReflectionMethod($class, $methodName);
            $fromSchemaParameter = null;

            foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
                if ($reflectionParameter->getName() !== 'fromSchema') {
                    continue;
                }

                $fromSchemaParameter = $reflectionParameter;
            }

            $this->assertNotNull($fromSchemaParameter, sprintf('%s::%s has no $fromSchema parameter.', $class, $methodName));
            $this->assertTrue($fromSchemaParameter->isDefaultValueAvailable(), sprintf('%s::%s\'s $fromSchema has no default.', $class, $methodName));
            $this->assertTrue($fromSchemaParameter->getDefaultValue(), sprintf('%s::%s\'s $fromSchema does not default to true.', $class, $methodName));
        }
    }
}
