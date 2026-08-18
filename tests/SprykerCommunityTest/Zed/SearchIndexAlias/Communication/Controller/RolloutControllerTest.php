<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Controller;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Controller\RolloutController;

/**
 * PORTABLE — pure open-redirect guard logic, no host shop needed. The rest of `RolloutController` (and
 * `IndexController`) is deliberately left untested here: every other method needs a real Silex
 * `Application`/session (for `addSuccessMessage()`'s flash bag) plus the real Locator-resolved
 * facade/factory chain, which none of this package's sibling packages' own Zed backend controllers
 * (as opposed to the simpler JSON gateway pattern) attempt to unit-test either -- a real browser click
 * through the GUI is the only honest way to verify a full form-post/redirect round trip, same boundary
 * this project's own testing conventions already draw elsewhere. See README "Testing and CI".
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Controller
 * @group RolloutControllerTest
 * Add your own group annotations below this line
 * @group Portable
 */
class RolloutControllerTest extends Unit
{
    public function testResolveRedirectUrlPassesThroughAValidSameSiteRelativePath(): void
    {
        $this->assertSame('/search-index-alias/index?source=page&store=DE', $this->resolveRedirectUrl('/search-index-alias/index?source=page&store=DE'));
    }

    public function testResolveRedirectUrlFallsBackToIndexForAnEmptyValue(): void
    {
        $this->assertSame('/search-index-alias/index', $this->resolveRedirectUrl(''));
    }

    public function testResolveRedirectUrlRejectsAProtocolRelativeUrlAsAnOpenRedirect(): void
    {
        $this->assertSame('/search-index-alias/index', $this->resolveRedirectUrl('//evil.example'));
    }

    public function testResolveRedirectUrlRejectsAnAbsoluteUrlAsAnOpenRedirect(): void
    {
        $this->assertSame('/search-index-alias/index', $this->resolveRedirectUrl('https://evil.example/phish'));
    }

    public function testResolveRedirectUrlRejectsAValueNotStartingWithASlash(): void
    {
        $this->assertSame('/search-index-alias/index', $this->resolveRedirectUrl('evil.example'));
    }

    protected function resolveRedirectUrl(string $redirectTo): string
    {
        $rolloutController = new class extends RolloutController {
            public function exposeResolveRedirectUrl(string $redirectTo): string
            {
                return $this->resolveRedirectUrl($redirectTo);
            }
        };

        return $rolloutController->exposeResolveRedirectUrl($redirectTo);
    }
}
