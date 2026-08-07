<?php

declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class AvailabilityAndSwitcherUiTest extends TestCase
{
    public function testSwitcherDcaOffersExactlyTheSixCanonicalStyles(): void
    {
        $source = file_get_contents(__DIR__.'/../../contao/dca/tl_module.php');
        self::assertIsString($source);

        foreach (['horizontal_flags', 'horizontal_labels', 'horizontal_flags_labels', 'vertical_flags', 'vertical_labels', 'vertical_flags_labels'] as $style) {
            self::assertStringContainsString($style, file_get_contents(__DIR__.'/../../src/Switcher/SwitcherStyle.php'));
        }
        self::assertStringContainsString("'options'       => SwitcherStyle::values()", $source);
        self::assertStringContainsString('unavailableLanguageDisplay', $source);
        self::assertStringContainsString('inlineLangHideActive', $source);
        self::assertStringContainsString('customTpl', $source);
    }

    public function testContentReviewUiIsRemovedWithoutRemovingPageReview(): void
    {
        self::assertStringNotContainsString('TranslationReviewDca', file_get_contents(__DIR__.'/../../contao/dca/tl_content.php'));
        self::assertStringNotContainsString('TranslationReviewDca', file_get_contents(__DIR__.'/../../contao/dca/tl_content_translation.php'));
        self::assertStringContainsString('TranslationReviewDca', file_get_contents(__DIR__.'/../../contao/dca/tl_page.php'));
        self::assertStringContainsString('TranslationReviewDca', file_get_contents(__DIR__.'/../../contao/dca/tl_page_translation.php'));
    }

    public function testAvailabilityFieldsHaveExactlyTwoPolicies(): void
    {
        $dca = file_get_contents(__DIR__.'/../../contao/dca/tl_inline_language.php');
        self::assertStringContainsString('[PageAvailabilityMode::Strict->value, PageAvailabilityMode::Fallback->value]', $dca);
        self::assertStringContainsString('[ContentFallbackMode::Strict->value, ContentFallbackMode::Fallback->value]', $dca);
    }

    public function testTemplateConsumesResolvedUrlsAndProvidesAccessibleFlagOnlyLabels(): void
    {
        $template = file_get_contents(__DIR__.'/../../contao/templates/mod_language_switcher.html.twig');
        self::assertStringContainsString('href="{{ lang.href }}"', $template);
        self::assertStringContainsString('aria-current="page"', $template);
        self::assertStringContainsString('language-switcher__label--visually-hidden', $template);
        self::assertStringNotContainsString("~ '/'", $template);
    }
}
