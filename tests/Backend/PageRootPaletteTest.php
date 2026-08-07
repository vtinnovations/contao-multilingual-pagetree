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
use Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette;
use Vtinnovations\ContaoMultilingualPagetree\Backend\RootPageContext;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;

/**
 * What the assembled tl_page palettes actually contain after the onload pass.
 *
 * These tests operate on `$GLOBALS['TL_DCA']` exactly like Contao does, so a
 * palette that would not show the section in the live backend fails here.
 */
final class PageRootPaletteTest extends TestCase
{
    /** A faithful copy of Contao's own website-root palette. */
    private const ROOT_PALETTE = '{title_legend},title,alias,type;'
        .'{meta_legend},pageTitle,robots,description,serpPreview;'
        .'{routing_legend},routePath,routePriority,routeConflicts;'
        .'{language_legend},language,fallback,disableLanguageRedirect;'
        .'{dns_legend},dns,useSSL,urlPrefix,urlSuffix,mailerTransport,useFolderUrl,validAliasCharacters;'
        .'{global_legend:hide},favicon,robotsTxt,adminEmail,dateFormat,timeFormat,datimFormat,enableCanonical,staticFiles,staticPlugins;'
        .'{sitemap_legend:hide},createSitemap;'
        .'{layout_legend},includeLayout;'
        .'{cache_legend:hide},includeCache;'
        .'{chmod_legend},includeChmod;'
        .'{protected_legend:hide},protected;'
        .'{expert_legend:hide},cssClass,noSearch,requireItem,tabindex,accesskey;'
        .'{publish_legend},published,start,stop';

    private const REGULAR_PALETTE = '{title_legend},title,alias,type;{routing_legend},routePath;{chmod_legend},includeChmod;{publish_legend},published';

    /** @var array<string, mixed>|null */
    private ?array $backup = null;

    protected function setUp(): void
    {
        $this->backup = $GLOBALS['TL_DCA']['tl_page'] ?? null;

        $GLOBALS['TL_DCA']['tl_page']['palettes'] = [
            '__selector__' => ['type', 'includeLayout'],
            'root' => self::ROOT_PALETTE,
            'rootfallback' => self::ROOT_PALETTE,
            'regular' => self::REGULAR_PALETTE,
        ];
    }

    protected function tearDown(): void
    {
        if (null === $this->backup) {
            unset($GLOBALS['TL_DCA']['tl_page']);

            return;
        }

        $GLOBALS['TL_DCA']['tl_page'] = $this->backup;
    }

    private function palette(): PageRootPalette
    {
        // Both dependencies are constructor-only; nothing is queried or fetched
        // while the service is built.
        return new PageRootPalette(new RootPagePermission(), new RootPageContext());
    }

    public function testAnAuthorisedRequestGetsItsOwnSectionInEveryRootPalette(): void
    {
        $this->palette()->apply(true);

        foreach (['root', 'rootfallback'] as $name) {
            $palette = $GLOBALS['TL_DCA']['tl_page']['palettes'][$name];

            self::assertStringContainsString(
                '{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel',
                $palette,
                $name.' must contain the dedicated licence section.',
            );
        }
    }

    public function testTheSectionSitsImmediatelyBeforeContaosAccessRightsSection(): void
    {
        $this->palette()->apply(true);

        self::assertStringContainsString(
            '{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{chmod_legend},includeChmod',
            $GLOBALS['TL_DCA']['tl_page']['palettes']['root'],
        );
    }

    public function testTheSectionIsNeitherFirstNorPartOfTheLanguageSection(): void
    {
        $this->palette()->apply(true);
        $segments = explode(';', $GLOBALS['TL_DCA']['tl_page']['palettes']['root']);

        self::assertStringStartsWith('{title_legend}', $segments[0], 'The licence section must never open the form.');

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{language_legend}')) {
                self::assertStringNotContainsString('contaoMultilingualPagetreeLicencePanel', $segment);
                self::assertStringContainsString('additional_languages', $segment, 'The language summary stays in the language section.');
            }
        }
    }

    public function testTheLanguageSummaryDoesNotReplaceContaosOwnLanguageFields(): void
    {
        $this->palette()->apply(true);
        $palette = $GLOBALS['TL_DCA']['tl_page']['palettes']['root'];

        self::assertStringContainsString('{language_legend},language,fallback,disableLanguageRedirect,additional_languages', $palette);
    }

    public function testNonRootPalettesAreNeverTouched(): void
    {
        $this->palette()->apply(true);

        self::assertSame(self::REGULAR_PALETTE, $GLOBALS['TL_DCA']['tl_page']['palettes']['regular']);
    }

    /** Repeated DCA loading in one request must not duplicate anything. */
    public function testRepeatedPassesAreIdempotent(): void
    {
        $palette = $this->palette();
        $palette->apply(true);
        $once = $GLOBALS['TL_DCA']['tl_page']['palettes']['root'];
        $palette->apply(true);
        $palette->apply(true);
        $twice = $GLOBALS['TL_DCA']['tl_page']['palettes']['root'];

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, 'contaoMultilingualPagetreeLicencePanel'));
        self::assertSame(1, substr_count($twice, 'contao_multilingual_pagetree_licence_legend'));
        self::assertSame(1, substr_count($twice, 'additional_languages'));
    }

    public function testAnUnauthorisedRequestHasNoLicenceSectionAtAll(): void
    {
        $palette = $this->palette();
        $palette->apply(true);
        $palette->apply(false);

        $assembled = $GLOBALS['TL_DCA']['tl_page']['palettes']['root'];

        self::assertStringNotContainsString('contaoMultilingualPagetreeLicencePanel', $assembled);
        self::assertStringNotContainsString('contao_multilingual_pagetree_licence_legend', $assembled);
        self::assertStringContainsString('{chmod_legend},includeChmod', $assembled, 'Contao’s own sections must survive.');
    }

    /** Third-party additions must survive the pass unchanged. */
    public function testForeignLegendsAndFieldsSurvive(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title;{vendor_legend},vendorField;{chmod_legend},includeChmod;{publish_legend},published';
        $this->palette()->apply(true);

        $assembled = $GLOBALS['TL_DCA']['tl_page']['palettes']['root'];

        self::assertStringContainsString('{vendor_legend},vendorField', $assembled);
        self::assertStringContainsString('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{chmod_legend}', $assembled);
    }

    /** A custom root page type keeps the section. */
    public function testCustomRootPalettesAreIncluded(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['palettes']['rootcustom'] = self::ROOT_PALETTE;
        $this->palette()->apply(true);

        self::assertStringContainsString(
            '{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel',
            $GLOBALS['TL_DCA']['tl_page']['palettes']['rootcustom'],
        );
        self::assertContains('rootcustom', PageRootPalette::rootPaletteNames());
    }

    public function testAMissingRootPaletteIsSimplySkipped(): void
    {
        $GLOBALS['TL_DCA']['tl_page']['palettes'] = ['__selector__' => ['type']];

        $this->palette()->apply(true);

        self::assertSame(['__selector__' => ['type']], $GLOBALS['TL_DCA']['tl_page']['palettes']);
    }

    /**
     * The section must never disappear just because a palette was reorganised.
     *
     * @dataProvider unusualPalettes
     */
    public function testTheSectionIsAlwaysVisibleAndNeverFirst(string $palette, string $expectedBefore): void
    {
        $assembled = PageRootPalette::assemble($palette, true);

        self::assertStringContainsString('contaoMultilingualPagetreeLicencePanel', $assembled);
        self::assertFalse(str_starts_with($assembled, '{contao_multilingual_pagetree_licence_legend}'));

        if ('' !== $expectedBefore) {
            self::assertStringContainsString(
                '{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;'.$expectedBefore,
                $assembled,
            );

            return;
        }

        self::assertStringEndsWith('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel', $assembled);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusualPalettes(): iterable
    {
        yield 'access rights present' => [
            '{title_legend},title;{chmod_legend},includeChmod;{publish_legend},published',
            '{chmod_legend}',
        ];

        yield 'only protection' => [
            '{title_legend},title;{protected_legend:hide},protected;{publish_legend},published',
            '{protected_legend:hide}',
        ];

        yield 'alternative access legend' => [
            '{title_legend},title;{access_legend},protected;{publish_legend},published',
            '{access_legend}',
        ];

        yield 'no access section at all' => [
            '{title_legend},title;{publish_legend},published,start,stop',
            '{publish_legend}',
        ];

        yield 'no known anchor' => [
            '{title_legend},title;{vendor_legend},vendorField',
            '',
        ];
    }

    /**
     * An anchor in the very first position is skipped, because the section must
     * not open the form. This is asserted on the helper, since the assembled
     * root palette always starts with Contao's own sections.
     */
    public function testAnAnchorInFirstPositionIsSkipped(): void
    {
        $assembled = \Vtinnovations\ContaoMultilingualPagetree\Backend\PaletteHelper::insertLegend(
            '{chmod_legend},includeChmod;{publish_legend},published',
            PageRootPalette::LICENCE_LEGEND,
            [PageRootPalette::LICENCE_FIELD],
            ['chmod_legend', 'publish_legend'],
        );

        self::assertSame(
            '{chmod_legend},includeChmod;{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{publish_legend},published',
            $assembled,
        );
    }
}
