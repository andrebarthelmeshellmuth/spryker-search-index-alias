<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

/*
 * Minimal config for standalone transfer generation — just the handful of keys the transfer generator's
 * own class-resolver reads before anything ever tries to reach a network or a database. Not a real
 * project's config_default.php.
 */

declare(strict_types = 1);

use Spryker\Shared\Kernel\KernelConstants;

$config[KernelConstants::PROJECT_NAMESPACES] = [];
$config[KernelConstants::CORE_NAMESPACES] = ['SprykerCommunity', 'Spryker'];
