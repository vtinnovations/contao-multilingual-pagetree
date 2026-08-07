<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RootLicenceArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testControllerDerivesDomainAndRejectsCrossRootFormState(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Controller/Backend/LicenseController.php');
        self::assertStringContainsString('$this->domains->domain($rootId)', $source);
        self::assertStringContainsString('(int) $postedRoot !== $rootId', $source);
        self::assertStringContainsString('hash_equals($domain, $displayedDomain)', $source);
        self::assertStringNotContainsString("get('domain')", $source);
        self::assertStringNotContainsString('storage_path', $source);
        self::assertStringContainsString('$this->guard->isWriteMethod($request)', $source);
        self::assertStringContainsString('$this->guard->isTokenValid($request)', $source);
    }

    public function testDisplayedAndVerifiedDomainsShareTheSelectedRootContext(): void
    {
        $panel = (string) file_get_contents(self::ROOT.'/src/Backend/RootLicenseDca.php');
        $controller = (string) file_get_contents(self::ROOT.'/src/Controller/Backend/LicenseController.php');
        $identity = (string) file_get_contents(self::ROOT.'/src/Metadata/InstallationIdentity.php');

        self::assertStringContainsString('$template->rootDomain = $domain', $panel);
        self::assertStringContainsString('$this->context->select($rootId, $domain)', $panel);
        self::assertStringContainsString('$this->context->select($rootId, $domain)', $controller);
        self::assertStringContainsString('$this->rootContext?->domain()', $identity);
    }

    public function testUpdaterRequiresOneExactRootMatch(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Distribution/ChannelUpdateProcessor.php');
        self::assertStringContainsString('rootsForExactDomain($request->host)', $source);
        self::assertStringContainsString('1 !== count($matches)', $source);
        self::assertStringContainsString('$this->rootContext->select($matches[0], $request->host)', $source);
    }

    public function testEveryLicenceWriteRouteIsPostOnlyAndRootScoped(): void
    {
        $routes = (string) file_get_contents(self::ROOT.'/src/Resources/config/routes.yaml');
        foreach (['activate', 'replace', 'refresh', 'verify', 'remove'] as $action) {
            self::assertMatchesRegularExpression('/root_licence_'.$action.':[\s\S]*?path: [^\n]*\{rootId\}[^\n]*[\s\S]*?methods: \[POST\]/', $routes);
        }
    }

    public function testPermissionUsesOnlyNativeAdminModuleAndPageMountAccess(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Security/RootPagePermission.php');
        self::assertStringContainsString('$user->isAdmin', $source);
        self::assertStringContainsString('$this->authoriseCurrentUser($rootId)', $source);
        self::assertStringContainsString("'page', 'modules'", $source);
        self::assertStringContainsString("'pagemounts'", $source);
        self::assertStringNotContainsString('assignedGroups', $source);
        self::assertStringContainsString("(string) \$rootId, 'pagemounts'", $source);
    }

    public function testCapabilityCacheIncludesRootDomainAndStateFingerprint(): void
    {
        $policy = (string) file_get_contents(self::ROOT.'/src/Security/CapabilityPolicy.php');
        $store = (string) file_get_contents(self::ROOT.'/src/Storage/RootScopedPackageStore.php');
        self::assertStringContainsString('$this->rootContext?->key()', $policy);
        self::assertStringContainsString('stateFingerprint()', $policy);
        self::assertStringContainsString("hash('sha256'", $store);
    }

    public function testInvocationUsesSelectedRootDomainAndRemainsOncePerInvocation(): void
    {
        $listener = (string) file_get_contents(self::ROOT.'/src/EventListener/UsageSignalListener.php');
        $signal = (string) file_get_contents(self::ROOT.'/src/Distribution/UsageSignal.php');
        self::assertStringContainsString('$this->rootContext->domain()', $listener);
        self::assertStringContainsString('$this->sent', $signal);
    }

    public function testPanelIsRootOnlyEscapedAndLoadsItsOwnAsset(): void
    {
        $dca = (string) file_get_contents(self::ROOT.'/src/Backend/RootLicenseDca.php');
        $template = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');
        // The panel refuses a non-root record through the page context rather
        // than by comparing a raw type string itself.
        self::assertStringContainsString('isRootPage($rootId)', $dca);
        self::assertStringNotContainsString('|raw', $template);
        self::assertStringNotContainsString('<h1', strtolower($template));
        self::assertStringContainsString('backend-license.css', $dca);
        self::assertStringContainsString('root-licence-navigation.js', $dca);
        self::assertStringNotContainsString('<form', strtolower($template));
        self::assertStringNotContainsString('type="submit"', $template);
        self::assertStringContainsString('data-cmp-licence-action', $template);
        self::assertStringContainsString('disabled aria-disabled="true"', $template);
    }

    public function testLegacyAdoptionRequiresOneMatchingRootAndNeverClearsLegacyState(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Storage/LegacyStateAdoption.php');
        self::assertStringContainsString('[$this->context->rootId()] !== $this->roots->rootsForExactDomain', $source);
        self::assertStringNotContainsString('legacy->clear', $source);
    }
}
