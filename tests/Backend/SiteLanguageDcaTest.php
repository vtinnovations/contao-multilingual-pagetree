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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Backend;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca;

final class SiteLanguageDcaTest extends TestCase
{
    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'manage' => 'Manage additional languages',
            'licenceRequired' => 'A valid license is required before additional languages can be managed.',
        ];
    }

    /**
     * The Language section states the requirement and offers nothing else. The
     * controls live in the licence section of the same form, and a second entry
     * point here would duplicate them.
     */
    public function testUnlicensedAuthorisedUserGetsTheNoticeAndNoControl(): void
    {
        $html = SiteLanguageDca::renderContextActions($this->labels(), 17, false, true, '/languages?id=17');

        self::assertStringContainsString('A valid license is required', $html);
        self::assertStringNotContainsString('Manage additional languages', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('cmp-root-licence-navigation', $html);
        self::assertStringNotContainsString('root_licence_activate', $html);
    }

    public function testUnlicensedUnauthorisedUserGetsTheSameGenericNotice(): void
    {
        $html = SiteLanguageDca::renderContextActions($this->labels(), 17, false, true, '/languages?id=17');

        self::assertStringContainsString('A valid license is required', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('Manage additional languages', $html);
    }

    /** No promotional wording survives anywhere in the section. */
    public function testNoPromotionalWordingIsRendered(): void
    {
        $html = SiteLanguageDca::renderContextActions($this->labels(), 17, false, true, '/languages?id=17');

        foreach (['free licence', 'free license', 'lifetime free', 'Go to licence settings'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
    }

    public function testLicensedAuthorisedRootGetsExactlyOneLanguageActionWithoutWarning(): void
    {
        $html = SiteLanguageDca::renderContextActions($this->labels(), 17, true, true, '/languages?id=17');

        self::assertSame(1, substr_count($html, 'Manage additional languages'));
        self::assertStringContainsString('href="/languages?id=17"', $html);
        self::assertStringNotContainsString('license is required', $html);
    }

    public function testLicensedRootWithoutLanguagePermissionGetsNoAction(): void
    {
        self::assertSame('', SiteLanguageDca::renderContextActions($this->labels(), 17, true, false, '/languages?id=17'));
    }

    public function testUnavailableCurrentRootClosesCreationIdempotently(): void
    {
        $config = ['ptable' => 'tl_page'];

        SiteLanguageDca::applyCreateAvailability($config, false);
        SiteLanguageDca::applyCreateAvailability($config, false);

        self::assertTrue($config['closed']);
        self::assertSame('tl_page', $config['ptable']);
    }

    public function testAValidCurrentRootRestoresOnlyItsOwnClosedChange(): void
    {
        $config = ['ptable' => 'tl_page'];
        SiteLanguageDca::applyCreateAvailability($config, false);
        SiteLanguageDca::applyCreateAvailability($config, true);

        self::assertArrayNotHasKey('closed', $config);

        $thirdPartyClosed = ['closed' => true];
        SiteLanguageDca::applyCreateAvailability($thirdPartyClosed, false);
        SiteLanguageDca::applyCreateAvailability($thirdPartyClosed, true);
        self::assertTrue($thirdPartyClosed['closed']);
    }
}
