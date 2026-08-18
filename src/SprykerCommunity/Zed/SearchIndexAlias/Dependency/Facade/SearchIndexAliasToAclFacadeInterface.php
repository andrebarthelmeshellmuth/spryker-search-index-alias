<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Dependency\Facade;

use Generated\Shared\Transfer\GroupsTransfer;
use Generated\Shared\Transfer\RolesTransfer;
use Generated\Shared\Transfer\RulesTransfer;

interface SearchIndexAliasToAclFacadeInterface
{
    /**
     * Read-only, and used ONLY by `search-index-alias:check-installation` to work out whether this
     * package's own Zed pages are reachable by anybody other than a root-style admin. Nothing on the
     * request path consults it — Zed access control is Spryker's own Acl module's job, exactly as it is
     * for every other Zed module.
     */
    public function getAllGroups(): GroupsTransfer;

    /**
     * @param int $idGroup
     */
    public function getGroupRoles(int $idGroup): RolesTransfer;

    /**
     * @param int $idRole
     */
    public function getRoleRules(int $idRole): RulesTransfer;
}
