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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Url;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Url\InvalidLanguageUrlException;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;

class LanguageDomainNormalizerTest extends TestCase
{
    private LanguageDomainNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LanguageDomainNormalizer(new CanonicalHost());
    }

    /**
     * @dataProvider acceptedValues
     */
    public function testAcceptedValuesAreNormalised(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'empty inherits the root domain' => ['', null];
        yield 'null inherits the root domain' => [null, null];
        yield 'whitespace only inherits the root domain' => ['   ', null];
        yield 'plain hostname' => ['www.xyz.de', 'www.xyz.de'];
        yield 'surrounding whitespace is trimmed' => ['  www.xyz.de  ', 'www.xyz.de'];
        yield 'uppercase is lowercased' => ['WWW.XYZ.DE', 'www.xyz.de'];
        yield 'mixed case is lowercased' => ['Www.Xyz.De', 'www.xyz.de'];
        yield 'one accidental final dot is removed' => ['www.xyz.de.', 'www.xyz.de'];
        yield 'subdomain' => ['de.example.org', 'de.example.org'];
        yield 'deep subdomain' => ['a.b.c.example.org', 'a.b.c.example.org'];
    }

    /**
     * A parent domain, a subdomain and a www variant are three different
     * identities. Nothing is added, removed or widened.
     */
    public function testExactHostnameSemanticsArePreserved(): void
    {
        $this->assertSame('example.com', $this->normalizer->normalize('example.com'));
        $this->assertSame('www.example.com', $this->normalizer->normalize('www.example.com'));

        $this->assertFalse($this->normalizer->matches('example.com', 'www.example.com'));
        $this->assertFalse($this->normalizer->matches('example.com', 'shop.example.com'));
        $this->assertFalse($this->normalizer->matches('example.com', 'malicious-example.com'));
        $this->assertTrue($this->normalizer->matches('Example.COM', 'example.com.'));
    }

    /**
     * @dataProvider rejectedValues
     */
    public function testRejectedValues(mixed $input, string $reasonKey): void
    {
        try {
            $this->normalizer->normalize($input);
            $this->fail(sprintf('"%s" must be rejected.', is_string($input) ? $input : gettype($input)));
        } catch (InvalidLanguageUrlException $exception) {
            $this->assertSame($reasonKey, $exception->reasonKey);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'https scheme' => ['https://www.xyz.de', 'domainScheme'];
        yield 'http scheme' => ['http://www.xyz.de', 'domainScheme'];
        yield 'protocol relative' => ['//www.xyz.de', 'domainScheme'];
        yield 'path' => ['www.xyz.de/de', 'domainPath'];
        yield 'trailing slash' => ['www.xyz.de/', 'domainPath'];
        yield 'query string' => ['www.xyz.de?x=1', 'domainQuery'];
        yield 'fragment' => ['www.xyz.de#top', 'domainFragment'];
        yield 'credentials' => ['user@www.xyz.de', 'domainInvalid'];
        yield 'port' => ['www.xyz.de:8080', 'domainPort'];
        yield 'wildcard' => ['*.xyz.de', 'domainInvalid'];
        yield 'double final dot' => ['www.xyz.de..', 'domainInvalid'];
        yield 'empty label' => ['www..xyz.de', 'domainInvalid'];
        yield 'underscore label' => ['www_xyz.de', 'domainInvalid'];
        yield 'ip address' => ['192.168.0.1', 'domainInvalid'];
        yield 'space inside' => ['www xyz.de', 'domainInvalid'];
        yield 'non string' => [42, 'domainInvalid'];
    }

    /** A rejected persisted value simply inherits the root domain again. */
    public function testAnUnreadablePersistedValueInherits(): void
    {
        $this->assertNull($this->normalizer->normalizeOrNull('https://www.xyz.de'));
        $this->assertSame('www.xyz.de', $this->normalizer->normalizeOrNull('WWW.XYZ.DE'));
    }
}
