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

final class BackendWorkflowTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testPageAndArticleDcasDoNotUnconditionallyCreateLanguageLegends(): void
    {
        foreach (['tl_page.php', 'tl_article.php', 'tl_content.php', 'tl_news.php', 'tl_calendar_events.php', 'tl_faq.php'] as $file) {
            $source = (string) file_get_contents(self::ROOT.'/contao/dca/'.$file);
            self::assertStringNotContainsString("'{language_legend},language_tabs;'", $source);
        }
        $tabs = (string) file_get_contents(self::ROOT.'/src/Backend/LanguageTabs.php');
        self::assertStringContainsString('PaletteHelper::removeFromTable', $tabs);
        self::assertStringContainsString("['additional_languages']", $tabs);
    }

    public function testSiteRootLanguageManagerAndRootScopedValidationAreRegistered(): void
    {
        $page = (string) file_get_contents(self::ROOT.'/contao/dca/tl_page.php');
        $language = (string) file_get_contents(self::ROOT.'/contao/dca/tl_inline_language.php');
        $manager = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');

        self::assertStringContainsString("'additional_languages'", $page);
        self::assertStringContainsString('renderRootManager', $page);
        self::assertStringContainsString('guardEditScope', $language);
        self::assertStringContainsString('validateLanguage', $language);
        self::assertStringContainsString('pid=? AND language=? AND id!=?', $manager);
        self::assertStringContainsString("'root' !==", $manager);
        self::assertStringContainsString("'ptable'           => 'tl_page'", $language);
        self::assertStringContainsString("'mode'        => 4", $language);
        self::assertStringContainsString('SELECT type, language FROM tl_page WHERE id=?', $manager);
        self::assertStringContainsString('SELECT pid FROM tl_inline_language WHERE id=?', $manager);
    }

    public function testParentModeOwnsTheSingleScopedCreateOperation(): void
    {
        $dca = (string) file_get_contents(self::ROOT.'/contao/dca/tl_inline_language.php');
        $manager = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');

        // DC_Table mode 4 creates one operation for its current parent. A
        // second static or callback-generated operation was the live defect.
        self::assertSame(0, preg_match_all("/'new'\\s*=>/", $dca));
        self::assertStringNotContainsString('button_callback', substr(
            $dca,
            (int) strpos($dca, "'global_operations'"),
            (int) strpos($dca, "'operations'") - (int) strpos($dca, "'global_operations'"),
        ));
        self::assertStringNotContainsString('findByType', $manager);
        self::assertStringNotContainsString('findMultipleByIds', $manager);
        self::assertStringContainsString('$contextId', $manager);
        self::assertStringContainsString('(int) $requestedId !== $contextId', $manager);
    }

    public function testCreationGuardUsesOnlyTheSelectedRootLicenceContext(): void
    {
        $manager = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');

        self::assertStringContainsString('$this->rootDomains->domain($rootId)', $manager);
        self::assertStringContainsString('$this->licenceContext->select($rootId, $domain)', $manager);
        self::assertStringContainsString('$this->licenceContext->clear()', $manager);
        self::assertStringContainsString("['create', 'edit', 'copy']", $manager);
        self::assertStringContainsString('Capability::TranslationEditing', $manager);
        self::assertStringContainsString('throw new AccessDeniedHttpException', $manager);
    }

    /**
     * The Language section never grows a licence control of its own: the
     * controls exist exactly once, in the licence section of the same form.
     */
    public function testTheLanguagePanelCarriesNoLicenceControlOfItsOwn(): void
    {
        $manager = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');
        $panel = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');
        $script = (string) file_get_contents(self::ROOT.'/public/js/root-licence-navigation.js');

        self::assertStringContainsString('id="cmp-root-licence-panel"', $panel);
        self::assertStringContainsString('getElementById(targetId)', $script);
        self::assertStringContainsString("addEventListener('click'", $script);
        self::assertStringContainsString('return false', $script);

        // No navigation-only control anywhere, in markup or in script.
        self::assertStringNotContainsString('cmp-root-licence-navigation', $manager);
        self::assertStringNotContainsString('cmp-root-licence-navigation', $script);
        self::assertStringNotContainsString('data-cmp-root-id', $manager);
        self::assertStringNotContainsString('licenceSettings', $manager);

        // The section never posts to a licence route or leaks a key or a host.
        self::assertStringNotContainsString('/licence/activate', $manager);
        self::assertStringNotContainsString('licence_key=', $script);
        self::assertStringNotContainsString('www.v-t.one', $script);
    }

    /**
     * The panel script belongs to the licence section only, so the Language
     * section never pulls in an asset it has no control for.
     */
    public function testThePanelAssetIsLoadedOnlyByTheLicenceSection(): void
    {
        $manager = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');
        $panel = (string) file_get_contents(self::ROOT.'/src/Backend/RootLicenseDca.php');

        self::assertStringNotContainsString('root-licence-navigation.js', $manager);
        self::assertStringContainsString('root-licence-navigation.js', $panel);
    }

    public function testLanguageListingHasRequiredOperationsAndModes(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/contao/dca/tl_inline_language.php');
        foreach (['edit', 'delete', 'toggle', 'pageAvailabilityMode', 'contentTranslationMode', 'published'] as $required) {
            self::assertStringContainsString("'{$required}'", $source);
        }
        self::assertStringNotContainsString('{language_legend},language,label,flag,fallback', $source);
    }

    public function testLicenceIsRootIntegratedAndStandaloneModuleIsRemoved(): void
    {
        $config = (string) file_get_contents(self::ROOT.'/contao/config/config.php');
        $routes = (string) file_get_contents(self::ROOT.'/src/Resources/config/routes.yaml');
        $controller = (string) file_get_contents(self::ROOT.'/src/Controller/Backend/LicenseController.php');

        $page = (string) file_get_contents(self::ROOT.'/contao/dca/tl_page.php');
        self::assertStringNotContainsString("['BE_MOD']['system']['contao_multilingual_pagetree']", $config);
        self::assertStringContainsString('PageRootPalette::LICENCE_FIELD', $page);
        self::assertStringContainsString("'sql' => null", $page);
        self::assertStringContainsString("PageRootPalette::class, 'register'", $page);
        self::assertStringNotContainsString('PageRootPalette::register()', $page);
        self::assertStringContainsString('PageRootPalette::class', $page);
        self::assertStringNotContainsString('contaoMultilingualPagetreeLicencePanel', (string) file_get_contents(self::ROOT.'/src/Backend/LanguageTabs.php'));
        self::assertStringContainsString('contao_multilingual_pagetree_root_licence_activate:', $routes);
        self::assertMatchesRegularExpression('/licence\/activate[\s\S]+methods: \[POST\]/', $routes);
        self::assertStringContainsString('RootPagePermission', $controller);
        self::assertStringContainsString("request->request->get('licence_key')", $controller);
        self::assertStringContainsString('root_domain', $controller);
        self::assertStringNotContainsString("request->request->get('domain')", $controller);
        self::assertStringNotContainsString('Capability::', $controller);
    }

    public function testDedicatedLicenceFieldAndPanelAreSeparateFromLanguageSettings(): void
    {
        $page = (string) file_get_contents(self::ROOT.'/contao/dca/tl_page.php');
        $palette = (string) file_get_contents(self::ROOT.'/src/Backend/PageRootPalette.php');
        $languagePanel = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');
        $template = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');

        self::assertStringContainsString("public const LICENCE_FIELD = 'contaoMultilingualPagetreeLicencePanel'", $palette);
        self::assertStringContainsString('PageRootPalette::LICENCE_FIELD', $page);
        self::assertStringContainsString("'sql' => null", $page);
        self::assertStringContainsString('RootLicenseDca::class', $page);
        // Assert the assembled result rather than the anchor list: the position
        // is what matters, and the anchor list has documented fallbacks.
        self::assertStringContainsString(
            '{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{chmod_legend}',
            \Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette::assemble(
                '{title_legend},title;{language_legend},language;{chmod_legend},includeChmod;{publish_legend},published',
                true,
            ),
        );
        self::assertStringNotContainsString("'language_legend', [self::LICENCE_FIELD]", $palette);
        self::assertStringNotContainsString('data-cmp-post-name', $languagePanel);
        self::assertStringNotContainsString('ctrl_cmp_licence_key', $languagePanel);
        self::assertStringContainsString('data-cmp-post-name="licence_key"', $template);
        self::assertStringContainsString('data-cmp-licence-action="activate"', $template);
        self::assertStringContainsString('data-cmp-licence-action="refresh"', $template);
        self::assertStringContainsString('data-cmp-licence-action="replace"', $template);
        self::assertStringContainsString('data-cmp-licence-action="remove"', $template);
    }

    public function testLicenceTemplateDoesNotExposeSensitivePackageInternals(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');
        foreach (['signature', 'checksum', 'storage path', 'license.json'] as $secret) {
            self::assertStringNotContainsString($secret, strtolower($source));
        }
        self::assertStringContainsString('https://www.v-t.one', $source);
        self::assertStringNotContainsString('<h1', strtolower($source));
        self::assertStringNotContainsString('<form', strtolower($source));
        self::assertStringNotContainsString('formmethod=', $source);
        self::assertStringNotContainsString('formaction=', $source);
        self::assertStringContainsString('data-cmp-licence-action="activate"', $source);
        self::assertStringContainsString('data-cmp-post-name="REQUEST_TOKEN"', $source);
    }
}
