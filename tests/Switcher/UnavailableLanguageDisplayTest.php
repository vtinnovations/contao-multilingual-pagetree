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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Switcher;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\UnavailableLanguageDisplay;

class UnavailableLanguageDisplayTest extends TestCase
{
    public function testHideIsAccepted(): void
    {
        $this->assertSame(UnavailableLanguageDisplay::Hide, UnavailableLanguageDisplay::fromValue('hide'));
        $this->assertFalse(UnavailableLanguageDisplay::fromValue('hide')->showsUnavailable());
    }

    public function testDisabledIsAccepted(): void
    {
        $this->assertSame(UnavailableLanguageDisplay::Disabled, UnavailableLanguageDisplay::fromValue('disabled'));
        $this->assertTrue(UnavailableLanguageDisplay::fromValue('disabled')->showsUnavailable());
    }

    /**
     * Requirement 39: an invalid configuration defaults to hide.
     *
     * @dataProvider invalidValues
     */
    public function testInvalidValuesNormaliseToHide(mixed $value): void
    {
        $this->assertSame(UnavailableLanguageDisplay::Hide, UnavailableLanguageDisplay::fromValue($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown' => ['visible'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'array' => [['disabled']];
        yield 'redirect attempt' => ['redirect'];
    }

    public function testValuesAreCaseAndWhitespaceInsensitive(): void
    {
        $this->assertSame(UnavailableLanguageDisplay::Disabled, UnavailableLanguageDisplay::fromValue(' DISABLED '));
    }

    public function testOnlyTwoPoliciesExist(): void
    {
        $this->assertSame(['hide', 'disabled'], array_map(
            static fn (UnavailableLanguageDisplay $case): string => $case->value,
            UnavailableLanguageDisplay::cases(),
        ));
    }
}
