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

/**
 * Regression guard for the defect that kept the licence section out of the live
 * backend.
 *
 * Contao runs `config.onload_callback` inside the data container's constructor
 * and only loads the record later, in `edit()`. Anything that decides from
 * `DataContainer::$activeRecord` at that point always sees an empty record: the
 * palette pass concluded "not a root page, not authorised" on every edit form
 * and silently left the palette untouched, which is exactly what the live
 * backend showed. `activeRecord` is deprecated in Contao 5.3 as well.
 *
 * These assertions therefore pin the lifecycle contract, not a rendered string.
 */
final class RootLicencePaletteLifecycleTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @dataProvider lifecycleSensitiveFiles
     */
    public function testTheLicencePathNeverReadsTheActiveRecord(string $file): void
    {
        // Comments are stripped first: these files explain *why* they avoid
        // activeRecord, and that explanation must not fail the rule it states.
        $code = implode('', array_map(
            // token_get_all() yields plain strings for single-character tokens,
            // so the parameter cannot be typed as an array.
            static fn (array|string $token): string => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all((string) file_get_contents(self::ROOT.'/'.$file)),
        ));

        self::assertStringNotContainsString(
            '->activeRecord',
            $code,
            $file.' must resolve the record through RootPageContext: activeRecord is empty during onload and deprecated in Contao 5.3.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lifecycleSensitiveFiles(): iterable
    {
        yield 'palette pass' => ['src/Backend/PageRootPalette.php'];
        yield 'licence field' => ['src/Backend/RootLicenseDca.php'];
    }

    /** The language summary resolves its record the same way. */
    public function testTheLanguageSummaryResolvesItsRecordThroughTheSharedContext(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');
        $start = strpos($source, 'public function renderRootManager');
        $body = false === $start ? '' : substr($source, $start, 900);

        self::assertStringContainsString('$this->pages->currentId($dc)', $body);
        self::assertStringContainsString('$this->pages->isRootPage(', $body);
        self::assertStringNotContainsString('activeRecord', $body);
    }

    /** The virtual field is registered unconditionally, with no database column. */
    public function testTheLicenceFieldIsRegisteredUnconditionally(): void
    {
        $dca = (string) file_get_contents(self::ROOT.'/contao/dca/tl_page.php');

        self::assertMatchesRegularExpression(
            '/\$GLOBALS\[.TL_DCA.\]\[.tl_page.\]\[.fields.\]\[\\\\?Vtinnovations[^\]]*PageRootPalette::LICENCE_FIELD\]\s*=/',
            $dca,
            'The field must be registered at DCA load time, not inside a conditional.',
        );
        self::assertMatchesRegularExpression("/'input_field_callback' => \[[^\]]*RootLicenseDca::class, 'render'\]/", $dca);
        self::assertStringContainsString("'sql' => null", $dca);

        // `exclude` would hide the field from every non-administrator unless the
        // permission is granted per user group - a silent removal path.
        self::assertDoesNotMatchRegularExpression(
            '/LICENCE_FIELD\]\s*=\s*\[(?:[^\]]|\](?!;))*\'exclude\'/s',
            $dca,
        );
    }

    /** The palette pass is registered as a real onload callback. */
    public function testThePalettePassIsRegisteredAsAnOnloadCallback(): void
    {
        $dca = (string) file_get_contents(self::ROOT.'/contao/dca/tl_page.php');

        self::assertMatchesRegularExpression(
            "/config.\]\[.onload_callback.\][\s\S]*PageRootPalette::class, 'register'/",
            $dca,
        );
        self::assertStringContainsString('$GLOBALS[\'TL_DCA\'][\'tl_page\'][\'config\'][\'onload_callback\']', $dca);
    }

    /** The callback method the DCA names must exist and be public. */
    public function testTheRegisteredCallbackExistsAndIsPublic(): void
    {
        $method = new \ReflectionMethod(\Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette::class, 'register');

        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic(), 'Contao resolves the class through the container, so the method is an instance method.');
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame(0, $method->getNumberOfRequiredParameters(), 'Contao may call the callback without a data container.');
    }

    /** The render callback the DCA names must exist and be public. */
    public function testTheRenderCallbackExistsAndIsPublic(): void
    {
        $method = new \ReflectionMethod(\Vtinnovations\ContaoMultilingualPagetree\Backend\RootLicenseDca::class, 'render');

        self::assertTrue($method->isPublic());
    }

    /**
     * Contao resolves `[class, method]` callbacks through the container, so both
     * callback classes must be registered as public services.
     */
    public function testTheCallbackClassesAreDiscoverablePublicServices(): void
    {
        $services = (string) file_get_contents(self::ROOT.'/src/Resources/config/services.yaml');

        foreach (['PageRootPalette', 'RootLicenseDca', 'RootPageContext', 'SiteLanguageDca'] as $class) {
            self::assertMatchesRegularExpression(
                '/Backend\\\\'.$class.":\n(?:\s+\S.*\n)*?\s+public: true/",
                $services,
                $class.' must be a public service or Contao cannot instantiate the callback.',
            );
        }
    }

    /** Nothing on the licence path may do I/O while the service is built. */
    public function testTheCallbackConstructorsStayCheap(): void
    {
        foreach (['src/Backend/PageRootPalette.php', 'src/Backend/RootPageContext.php', 'src/Backend/RootLicenseDca.php'] as $file) {
            $source = (string) file_get_contents(self::ROOT.'/'.$file);
            $start = strpos($source, 'public function __construct');

            if (false === $start) {
                continue;
            }

            $body = substr($source, $start, (int) (strpos($source, "\n    }", $start) ?: strlen($source)) - $start);

            foreach (['Database::', 'file_get_contents', 'Input::', 'curl_', '->initialize()', 'findByPk'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $body, $file.' does work in its constructor: '.$forbidden);
            }
        }
    }

    /**
     * The native language section must stay a summary. Status, domain, term,
     * activation state, key input and every control belong to the dedicated
     * section only.
     */
    public function testTheLanguageSectionCarriesNoLicenceDetailOrControl(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Backend/SiteLanguageDca.php');

        foreach ([
            'licence_key',
            'ctrl_cmp_licence_key',
            'data-cmp-post-name',
            'data-cmp-licence-action',
            'activateUrl',
            'replaceUrl',
            'refreshUrl',
            'verifyUrl',
            'removeUrl',
            'boundHost',
            'statusLabel',
            'lifetime',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'The language section must not contain '.$forbidden.'.');
        }
    }

    /** The dedicated section renders the complete form. */
    public function testTheDedicatedSectionCarriesTheCompleteForm(): void
    {
        $template = (string) file_get_contents(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');

        foreach ([
            'data-cmp-post-name="licence_key"',
            'data-cmp-licence-action="activate"',
            'data-cmp-licence-action="replace"',
            'data-cmp-licence-action="refresh"',
            'data-cmp-licence-action="verify"',
            'data-cmp-licence-action="remove"',
            'data-cmp-post-name="REQUEST_TOKEN"',
            'id="cmp-root-licence-panel"',
        ] as $required) {
            self::assertStringContainsString($required, $template);
        }
    }

    /** Both backend languages label the dedicated section. */
    public function testTheSectionIsLabelledInEveryShippedLanguage(): void
    {
        foreach (['en', 'de'] as $language) {
            $labels = (string) file_get_contents(self::ROOT.'/contao/languages/'.$language.'/tl_page.php');

            self::assertStringContainsString("['contao_multilingual_pagetree_licence_legend']", $labels);
            self::assertStringContainsString("['contaoMultilingualPagetreeLicencePanel']", $labels);
        }
    }
}
