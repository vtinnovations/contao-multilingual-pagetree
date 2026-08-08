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

final class LanguageSelectorArchitectureTest extends TestCase
{
    public function testNativeDcaSelectorsUseOneCanonicalProvider(): void
    {
        $dca = file_get_contents(__DIR__.'/../../contao/dca/tl_inline_language.php');
        self::assertIsString($dca);
        self::assertStringContainsString("'inputType' => 'select'", $dca);
        self::assertStringContainsString('LanguageAndFlagChoiceProvider::class, \'languageOptions\'', $dca);
        self::assertStringContainsString('LanguageAndFlagChoiceProvider::class, \'flagOptions\'', $dca);
        self::assertStringContainsString('LanguageAndFlagChoiceProvider::class, \'validateFlag\'', $dca);
        self::assertStringContainsString('language-flag-selector.js', $dca);
    }

    public function testNoRemoteFlagAssetsOrSecondPersistenceFieldsWereIntroduced(): void
    {
        $provider = file_get_contents(__DIR__.'/../../src/Backend/LanguageAndFlagChoiceProvider.php');
        $dca = file_get_contents(__DIR__.'/../../contao/dca/tl_inline_language.php');
        self::assertIsString($provider);
        self::assertIsString($dca);
        self::assertDoesNotMatchRegularExpression('#(?:src|href)=["\']https?://#i', $provider);
        self::assertStringNotContainsString('HttpClientInterface', $provider);
        self::assertStringContainsString("'sql'       => \"varchar(7) NOT NULL default ''\"", $dca);
        self::assertStringNotContainsString('languageCode', $dca);
    }
}
