<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

/*
 * Minimal Spryker project bootstrap for running this package's `@group Portable` tests standalone — no
 * demoshop, no Zed Kernel/Console app, no Locator. See the CI-portability write-up (referenced in
 * search-ranking-optimizer's own tests/_ci-standalone/bootstrap.php) for the full recipe this reproduces.
 */

declare(strict_types = 1);

define('APPLICATION_ENV', 'testing');
define('APPLICATION_CODE_BUCKET', 'EU');
define('APPLICATION_ROOT_DIR', __DIR__);
// The REAL repo's own src/ — this package's own transfer.xml lives there, and the transfer generator's
// project-source glob (getApplicationSourceDirectoryGlobPattern()) is APPLICATION_SOURCE_DIR-relative, so
// it has to point at the real source tree, not this CI-only directory. Generated output lands in
// <repo>/src/Generated/, gitignored exactly like a real Spryker project already gitignores it — never
// committed, regenerated every run.
define('APPLICATION_SOURCE_DIR', dirname(__DIR__, 2) . '/src');
define('APPLICATION_VENDOR_DIR', dirname(__DIR__, 2) . '/vendor');
