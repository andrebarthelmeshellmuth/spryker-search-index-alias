<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Persistence;

use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeletionQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexDeployRollbackTargetQuery;
use Orm\Zed\SearchIndexAlias\Persistence\SpySearchIndexRolloutQuery;
use Spryker\Zed\Kernel\Persistence\AbstractPersistenceFactory;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexDeletionMapper;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexDeletionMapperInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexDeployRollbackTargetMapper;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexDeployRollbackTargetMapperInterface;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexRolloutMapper;
use SprykerCommunity\Zed\SearchIndexAlias\Persistence\Propel\Mapper\SearchIndexRolloutMapperInterface;

class SearchIndexAliasPersistenceFactory extends AbstractPersistenceFactory
{
    public function createSpySearchIndexRolloutQuery(): SpySearchIndexRolloutQuery
    {
        return SpySearchIndexRolloutQuery::create();
    }

    public function createSearchIndexRolloutMapper(): SearchIndexRolloutMapperInterface
    {
        return new SearchIndexRolloutMapper();
    }

    public function createSpySearchIndexDeployRollbackTargetQuery(): SpySearchIndexDeployRollbackTargetQuery
    {
        return SpySearchIndexDeployRollbackTargetQuery::create();
    }

    public function createSearchIndexDeployRollbackTargetMapper(): SearchIndexDeployRollbackTargetMapperInterface
    {
        return new SearchIndexDeployRollbackTargetMapper();
    }

    public function createSpySearchIndexDeletionQuery(): SpySearchIndexDeletionQuery
    {
        return SpySearchIndexDeletionQuery::create();
    }

    public function createSearchIndexDeletionMapper(): SearchIndexDeletionMapperInterface
    {
        return new SearchIndexDeletionMapper();
    }
}
