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
use Vtinnovations\ContaoMultilingualPagetree\Backend\PaletteHelper;
use Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette;

final class PaletteHelperTest extends TestCase
{
    public function testEmptyFieldListNeverCreatesAnEmptyLegend(): void
    {
        self::assertSame('{title_legend},title', PaletteHelper::addToPalette('{title_legend},title', 'language_legend', []));
    }

    public function testAddingOptionalFieldsPreservesExistingPaletteAndAvoidsDuplicates(): void
    {
        $source = '{title_legend},title,alias;{publish_legend},published';
        $once = PaletteHelper::addToPalette($source, 'language_legend', ['language_tabs']);
        $twice = PaletteHelper::addToPalette($once, 'language_legend', ['language_tabs']);

        self::assertSame($once, $twice);
        self::assertStringContainsString('{title_legend},title,alias', $twice);
        self::assertSame(1, substr_count($twice, '{language_legend}'));
        self::assertSame(1, substr_count($twice, 'language_tabs'));
    }

    public function testRemovingLastOptionalFieldAlsoRemovesTheEmptyLegend(): void
    {
        $palette = '{language_legend},language_tabs;{title_legend},title';

        self::assertSame('{title_legend},title', PaletteHelper::removeFields($palette, ['language_tabs']));
    }

    public function testRemovingOptionalFieldPreservesThirdPartyFieldsInSameLegend(): void
    {
        $palette = '{language_legend},third_party,language_tabs;{title_legend},title';

        self::assertSame('{language_legend},third_party;{title_legend},title', PaletteHelper::removeFields($palette, ['language_tabs']));
    }

    public function testRootControlsArePlacedImmediatelyBeforeAccessRightsAndRemainIdempotent(): void
    {
        $source = '{title_legend},title,alias;{third_party_legend},third_party;{protected_legend:hide},protected,groups;{publish_legend},published';
        $once = PageRootPalette::assemble($source, true);
        $twice = PageRootPalette::assemble($once, true);

        self::assertSame($once, $twice);
        self::assertMatchesRegularExpression('/\{contao_multilingual_pagetree_licence_legend\},contaoMultilingualPagetreeLicencePanel;\{protected_legend:hide\}/', $once);
        self::assertFalse(str_starts_with($once, '{contao_multilingual_pagetree_licence_legend}'));
        self::assertSame(1, substr_count($once, '{contao_multilingual_pagetree_licence_legend}'));
        self::assertSame(1, substr_count($once, 'contaoMultilingualPagetreeLicencePanel'));
        self::assertSame(1, substr_count($once, 'additional_languages'));
        self::assertStringContainsString('{title_legend},title,alias', $once);
        self::assertStringContainsString('{third_party_legend},third_party', $once);
        self::assertStringContainsString('{publish_legend},published', $once);
    }

    public function testAlternativeAccessLegendIsRecognisedAndUnauthorisedPaletteHasNoLicenceSection(): void
    {
        $source = '{title_legend},title;{access_legend},protected;{publish_legend},published';
        $authorised = PageRootPalette::assemble($source, true);
        $unauthorised = PageRootPalette::assemble($authorised, false);

        self::assertStringContainsString('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{access_legend}', $authorised);
        self::assertStringNotContainsString('contaoMultilingualPagetreeLicencePanel', $unauthorised);
        self::assertStringNotContainsString('contao_multilingual_pagetree_licence_legend', $unauthorised);
        self::assertStringContainsString('{access_legend},protected', $unauthorised);
    }

    /**
     * A palette without an access-rights legend must still show the section.
     *
     * Dropping it silently was the old behaviour and is indistinguishable from a
     * broken integration, so the next anchor is used instead.
     */
    public function testAMissingAccessLegendFallsBackInsteadOfDroppingTheSection(): void
    {
        $palette = PageRootPalette::assemble('{title_legend},title;{publish_legend},published', true);

        self::assertStringContainsString('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{publish_legend}', $palette);
        self::assertFalse(str_starts_with($palette, '{contao_multilingual_pagetree_licence_legend}'));
        self::assertSame(1, substr_count($palette, 'contaoMultilingualPagetreeLicencePanel'));
    }

    /** With no known anchor left, the section becomes the last one - never the first. */
    public function testWithoutAnyKnownAnchorTheSectionIsAppendedLast(): void
    {
        $palette = PageRootPalette::assemble('{title_legend},title;{vendor_legend},vendorField', true);

        self::assertStringEndsWith('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel', $palette);
        self::assertStringContainsString('{vendor_legend},vendorField', $palette);
    }

    public function testContaoRootAccessRightsLegendIsRecognised(): void
    {
        $source = '{title_legend},title;{language_legend},language;{third_party_legend},custom;{chmod_legend:hide},chmod;{publish_legend},published';
        $assembled = PageRootPalette::assemble($source, true);

        self::assertStringContainsString('{contao_multilingual_pagetree_licence_legend},contaoMultilingualPagetreeLicencePanel;{chmod_legend:hide}', $assembled);
        self::assertFalse(str_starts_with($assembled, '{contao_multilingual_pagetree_licence_legend}'));
        self::assertSame(1, substr_count($assembled, 'contaoMultilingualPagetreeLicencePanel'));
        self::assertStringContainsString('{third_party_legend},custom', $assembled);
        self::assertSame($assembled, PageRootPalette::assemble($assembled, true));
    }
}
