<?php

declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class TemplateRegistrationTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testSwitcherHasRuntimeTwigAndEditableLegacyTemplate(): void
    {
        self::assertFileExists(self::ROOT.'/contao/templates/mod_language_switcher.html.twig');
        self::assertFileExists(self::ROOT.'/contao/templates/mod_language_switcher.html5');

        $legacy = (string) file_get_contents(self::ROOT.'/contao/templates/mod_language_switcher.html5');
        self::assertStringContainsString('$this->languages', $legacy);
        self::assertStringContainsString("\$lang['href']", $legacy);
        self::assertStringNotContainsString("\$lang['language'].'/'", $legacy);
    }

    public function testBackendLicenceViewIsNotAnEditableLegacyTemplate(): void
    {
        self::assertFileDoesNotExist(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html5');
        self::assertFileExists(self::ROOT.'/contao/templates/be_contao_multilingual_pagetree_root_license.html.twig');
        self::assertStringContainsString(
            "new BackendTemplate('be_contao_multilingual_pagetree_root_license')",
            (string) file_get_contents(self::ROOT.'/src/Backend/RootLicenseDca.php'),
        );
    }

    public function testHistoricalTypoIsNotExposedAsPrimaryTemplate(): void
    {
        self::assertFileDoesNotExist(self::ROOT.'/contao/templates/mod_language_swithcher.html5');
        self::assertFileDoesNotExist(self::ROOT.'/contao/templates/mod_language_swithcher.html.twig');
    }
}
