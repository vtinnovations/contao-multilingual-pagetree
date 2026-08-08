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

use Contao\CoreBundle\Intl\Countries;
use Contao\CoreBundle\Intl\Locales;
use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider;

final class LanguageAndFlagChoiceProviderTest extends TestCase
{
    public function testPlatformLanguagesBecomeNaturallySortedCodeValuedOptions(): void
    {
        $provider = $this->provider([
            'ru' => 'Russian',
            'de' => 'German',
            'en' => 'English',
            'en_GB' => 'British English',
        ]);

        self::assertSame(
            ['en-gb' => 'British English (en-gb)', 'en' => 'English (en)', 'de' => 'German (de)', 'ru' => 'Russian (ru)'],
            $provider->languageOptions(),
        );
        self::assertTrue($provider->isKnownLanguage('en-GB'));
        self::assertTrue($provider->isKnownLanguage('zz', 'zz'));
        self::assertFalse($provider->isKnownLanguage('<script>'));
    }

    /** @dataProvider defaultFlags */
    public function testCanonicalFlagDefaults(string $language, string $expected): void
    {
        self::assertSame($expected, $this->provider()->defaultFlag($language));
    }

    public static function defaultFlags(): iterable
    {
        yield ['de', 'de'];
        yield ['ru', 'ru'];
        yield ['en', 'gb'];
        yield ['en-US', 'us'];
        yield ['en-GB', 'gb'];
        yield ['pt-BR', 'br'];
        yield ['pt-PT', 'pt'];
        yield ['zh', ''];
        yield ['ar', ''];
        yield ['EN_us', 'us'];
    }

    public function testFlagOptionsAreReadableAndUseStableLowercaseValues(): void
    {
        $options = $this->provider([], ['DE' => 'Germany', 'GB' => 'United Kingdom', 'RU' => 'Russia'])->flagOptions();
        self::assertStringContainsString('Germany (de)', $options['de']);
        self::assertStringContainsString('United Kingdom (gb)', $options['gb']);
        self::assertStringContainsString('Russia (ru)', $options['ru']);
        self::assertStringNotContainsString('http', implode(' ', $options));
    }

    public function testServerDefaultsValidationAndCompatibility(): void
    {
        $provider = $this->provider(['de' => 'German', 'en' => 'English']);
        self::assertSame('gb', $provider->normalizeFlag('', 'en'));
        self::assertSame('de', $provider->normalizeFlag(' DE ', 'en'));
        self::assertSame('xy', $provider->normalizeFlag('xy', 'en', 'XY'));

        foreach ([['bad/path'], ['<script>'], [['de']]] as [$invalid]) {
            try {
                $provider->normalizeFlag($invalid, 'de');
                self::fail('Malformed flag input was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEmptyLabelIsSuggestedButCustomLabelIsPreserved(): void
    {
        $provider = $this->provider(['de' => 'German']);
        self::assertSame('German', $provider->fillLanguageLabelFor('', 'de'));
        self::assertSame('Custom label', $provider->fillLanguageLabelFor(' Custom label ', 'de'));
    }

    /** @param array<string, string> $languages @param array<string, string> $countries */
    private function provider(array $languages = ['en' => 'English'], array $countries = []): LanguageAndFlagChoiceProvider
    {
        $locales = $this->createMock(Locales::class);
        $locales->method('getLocales')->willReturn($languages);
        $countryProvider = $this->createMock(Countries::class);
        $countryProvider->method('getCountries')->willReturn($countries);

        return new LanguageAndFlagChoiceProvider($locales, $countryProvider);
    }
}
