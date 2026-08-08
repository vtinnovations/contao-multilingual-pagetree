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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Language;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Language\LanguageFlagMapper;

final class LanguageFlagMapperTest extends TestCase
{
    /** @dataProvider mappings */
    public function testBackendAndFrontendCanonicalDefaults(string $language, string $flag): void
    {
        self::assertSame($flag, (new LanguageFlagMapper())->defaultFlag($language));
    }

    public static function mappings(): iterable
    {
        yield ['de', 'de'];
        yield ['en', 'gb'];
        yield ['en-US', 'us'];
        yield ['en-GB', 'gb'];
        yield ['pt-BR', 'br'];
        yield ['ru', 'ru'];
        yield ['ja', 'jp'];
        yield ['zh', ''];
        yield ['ar', ''];
    }
}
