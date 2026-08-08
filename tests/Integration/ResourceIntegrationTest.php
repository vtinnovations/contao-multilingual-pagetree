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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceIntegrationTest extends TestCase
{
    #[DataProvider('dcaProvider')]
    public function testDcaResourcesLoadAndCallbacksAreCallableClasses(string $table): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2).'/contao/dca/'.$table.'.php');

        self::assertStringContainsString("\$GLOBALS['TL_DCA']['$table']", $contents);
        self::assertStringContainsString("'fields'", $contents);

        preg_match_all("/\['(Vtinnovations\\\\[^']+)',\s*'([^']+)'\]/", $contents, $callbacks, PREG_SET_ORDER);
        foreach ($callbacks as $callback) {
            self::assertTrue(class_exists($callback[1]), sprintf('DCA callback class %s must exist.', $callback[1]));
            self::assertTrue(method_exists($callback[1], $callback[2]), sprintf('DCA callback %s::%s must exist.', $callback[1], $callback[2]));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function dcaProvider(): iterable
    {
        foreach (glob(dirname(__DIR__, 2).'/contao/dca/tl_*_translation.php') ?: [] as $file) {
            yield basename($file, '.php') => [basename($file, '.php')];
        }
    }

    public function testEnglishAndGermanResourcesRemainInParity(): void
    {
        $root = dirname(__DIR__, 2).'/contao/languages';
        $english = array_map('basename', glob($root.'/en/*.php') ?: []);
        $german = array_map('basename', glob($root.'/de/*.php') ?: []);
        sort($english);
        sort($german);

        self::assertSame($english, $german);
    }

    public function testTemplateAndModuleRegistrationRemainDiscoverable(): void
    {
        self::assertFileExists(dirname(__DIR__, 2).'/contao/templates/mod_language_switcher.html.twig');
        self::assertFileExists(dirname(__DIR__, 2).'/contao/templates/mod_language_switcher.html5');
        self::assertFileExists(dirname(__DIR__, 2).'/src/Controller/FrontendModule/LanguageSwitcherController.php');
        self::assertStringContainsString("type: 'language_switcher'", (string) file_get_contents(dirname(__DIR__, 2).'/src/Controller/FrontendModule/LanguageSwitcherController.php'));
    }

    public function testPublicAssetPathsMatchTheCaseSensitiveBundleDirectory(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root.'/public/globe.png');
        self::assertFileExists($root.'/public/css/language-switcher.css');
        self::assertFileExists($root.'/public/js/translation-field-states.js');

        $template = (string) file_get_contents($root.'/contao/templates/mod_language_switcher.html.twig');
        self::assertStringContainsString('bundles/vtinnovationscontaomultilingualpagetree/flags/', $template);
    }
}
