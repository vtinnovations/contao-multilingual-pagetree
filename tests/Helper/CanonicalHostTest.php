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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Helper;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;

/**
 * The host binding is exact. These tests pin both halves of that promise:
 * representation is normalised, scope never is.
 */
final class CanonicalHostTest extends TestCase
{
    private CanonicalHost $hosts;

    protected function setUp(): void
    {
        $this->hosts = new CanonicalHost();
    }

    /**
     * @dataProvider representationCases
     */
    public function testRepresentationIsNormalisedWithoutChangingScope(string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->hosts->normalize($input));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function representationCases(): iterable
    {
        yield 'plain host' => ['example.com', 'example.com'];
        yield 'upper case' => ['EXAMPLE.COM', 'example.com'];
        yield 'mixed case subdomain' => ['Shop.Example.COM', 'shop.example.com'];
        yield 'one trailing dot' => ['example.com.', 'example.com'];
        yield 'two trailing dots' => ['example.com..', null];
        yield 'approved port' => ['example.com:8443', 'example.com'];
        yield 'invalid port' => ['example.com:99999', null];
        yield 'surrounding space' => ['  example.com  ', 'example.com'];
        yield 'url' => ['https://example.com/path', null];
        yield 'userinfo' => ['user@example.com', null];
        yield 'wildcard' => ['*.example.com', null];
        yield 'ipv4' => ['192.0.2.10', null];
        yield 'ipv6' => ['[2001:db8::1]', null];
        yield 'empty' => ['', null];
        yield 'underscore label' => ['bad_host.example.com', null];
        yield 'leading dash label' => ['-bad.example.com', null];
    }

    /** Punycode and its unicode spelling are the same host, nothing broader. */
    public function testInternationalisedHostsCanonicaliseToPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('The intl extension is not available.');
        }

        self::assertSame('xn--bcher-kva.example', $this->hosts->normalize('bücher.example'));
        self::assertTrue($this->hosts->matches('bücher.example', 'xn--bcher-kva.example'));
        self::assertFalse($this->hosts->matches('bücher.example', 'buecher.example'));
    }

    /**
     * @dataProvider bindingCases
     */
    public function testBindingIsExactHostOnly(string $bound, string $actual, bool $expected): void
    {
        self::assertSame($expected, $this->hosts->matches($bound, $actual));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function bindingCases(): iterable
    {
        yield 'identical' => ['example.com', 'example.com', true];
        yield 'case only difference' => ['example.com', 'Example.COM', true];
        yield 'apex does not match www' => ['example.com', 'www.example.com', false];
        yield 'www does not match apex' => ['www.example.com', 'example.com', false];
        yield 'apex does not match child' => ['example.com', 'shop.example.com', false];
        yield 'child does not match apex' => ['shop.example.com', 'example.com', false];
        yield 'siblings do not match' => ['shop.example.com', 'staging.example.com', false];
        yield 'nested child does not match' => ['shop.example.com', 'admin.shop.example.com', false];
        yield 'parent of nested does not match' => ['admin.shop.example.com', 'shop.example.com', false];
        yield 'suffix trap' => ['example.com', 'malicious-example.com', false];
        yield 'prefix trap' => ['example.com', 'example.com.attacker.test', false];
        yield 'wildcard is never interpreted' => ['*.example.com', 'shop.example.com', false];
        yield 'unparsable side' => ['example.com', 'https://example.com', false];
    }

    public function testAllMatchRequiresEveryValueToBeTheSameHost(): void
    {
        self::assertTrue($this->hosts->allMatch('example.com', 'EXAMPLE.com.', 'example.com:443'));
        self::assertFalse($this->hosts->allMatch('example.com', 'example.com', 'www.example.com'));
        self::assertFalse($this->hosts->allMatch('example.com'));
        self::assertFalse($this->hosts->allMatch('example.com', 'example.com', null));
    }
}
