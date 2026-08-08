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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\HreflangCodeFormatter;

class HreflangCodeFormatterTest extends TestCase
{
    /**
     * Requirement 57: underscores are normalised, regions are preserved.
     *
     * @dataProvider validCodes
     */
    public function testValidCodesAreNormalised(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HreflangCodeFormatter())->format($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'plain language' => ['en', 'en'];
        yield 'uppercase language' => ['DE', 'de'];
        yield 'hyphen region' => ['en-GB', 'en-GB'];
        yield 'underscore region' => ['de_CH', 'de-CH'];
        yield 'lowercase region' => ['de_ch', 'de-CH'];
        yield 'padded' => ['  fr  ', 'fr'];
        yield 'three letter language' => ['fil', 'fil'];
        yield 'numeric region' => ['es-419', 'es-419'];
    }

    /**
     * Requirement 56: malformed values are omitted instead of being guessed at.
     *
     * @dataProvider invalidCodes
     */
    public function testInvalidCodesAreRejected(?string $input): void
    {
        $formatter = new HreflangCodeFormatter();

        $this->assertNull($formatter->format($input));
        $this->assertFalse($formatter->isValid($input));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'sentence' => ['not a language'];
        yield 'too long' => ['deutsch'];
        yield 'single letter' => ['d'];
        yield 'markup' => ['<script>'];
        yield 'path' => ['de/ch'];
        yield 'double region' => ['de-CH-CH'];
    }

    public function testUnderscoresNeverReachTheOutput(): void
    {
        $this->assertStringNotContainsString('_', (string) (new HreflangCodeFormatter())->format('pt_br'));
    }
}
