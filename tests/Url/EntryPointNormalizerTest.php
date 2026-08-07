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
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\InvalidLanguageUrlException;

class EntryPointNormalizerTest extends TestCase
{
    private EntryPointNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new EntryPointNormalizer();
    }

    /**
     * @dataProvider acceptedValues
     */
    public function testAcceptedValuesAreNormalised(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'empty preserves the legacy strategy' => ['', EntryPointNormalizer::LEGACY];
        yield 'null preserves the legacy strategy' => [null, EntryPointNormalizer::LEGACY];
        yield 'whitespace only preserves the legacy strategy' => ['   ', EntryPointNormalizer::LEGACY];
        yield 'explicit domain root' => ['/', '/'];
        yield 'bare English language code' => ['en', '/en'];
        yield 'English language code with leading slash' => ['/en', '/en'];
        yield 'English language code with trailing slash' => ['/en/', '/en'];
        yield 'bare language code' => ['de', '/de'];
        yield 'leading slash' => ['/de', '/de'];
        yield 'trailing slash' => ['/de/', '/de'];
        yield 'surrounding whitespace' => ['  /de/  ', '/de'];
        yield 'nested entry point' => ['/languages/de', '/languages/de'];
        yield 'nested without leading slash' => ['languages/de', '/languages/de'];
        yield 'nested with trailing slash' => ['/languages/de/', '/languages/de'];
        yield 'hyphenated locale' => ['/de-at', '/de-at'];
        yield 'underscored locale' => ['/pt_br', '/pt_br'];
    }

    /**
     * An empty entry point and an explicit "/" are two different states and
     * must never collapse into one.
     */
    public function testEmptyAndExplicitRootStayDistinct(): void
    {
        $this->assertNotSame($this->normalizer->normalize(''), $this->normalizer->normalize('/'));
        $this->assertTrue($this->normalizer->isLegacy($this->normalizer->normalize('')));
        $this->assertFalse($this->normalizer->isLegacy($this->normalizer->normalize('/')));
        $this->assertTrue($this->normalizer->isRoot($this->normalizer->normalize('/')));
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
        yield 'full URL' => ['https://www.example.com/de', 'entryPointUrl'];
        yield 'protocol relative host' => ['//www.example.com/de', 'entryPointHost'];
        yield 'repeated leading slash' => ['//', 'entryPointHost'];
        yield 'hostname' => ['example.com/en', 'entryPointHost'];
        yield 'query string' => ['/de?x=1', 'entryPointQuery'];
        yield 'fragment' => ['/de#top', 'entryPointFragment'];
        yield 'dot segment' => ['/de/./x', 'entryPointTraversal'];
        yield 'dot only' => ['/.', 'entryPointTraversal'];
        yield 'traversal only' => ['/..', 'entryPointTraversal'];
        yield 'traversal segment' => ['/de/../admin', 'entryPointTraversal'];
        yield 'leading traversal' => ['../de', 'entryPointTraversal'];
        yield 'encoded traversal' => ['/de/%2e%2e/admin', 'entryPointTraversal'];
        yield 'encoded slash' => ['/de%2Fadmin', 'entryPointTraversal'];
        yield 'encoded backslash' => ['/de%5Cadmin', 'entryPointTraversal'];
        yield 'repeated slashes' => ['/de//about', 'entryPointSlashes'];
        yield 'repeated trailing slashes' => ['/de//', 'entryPointSlashes'];
        yield 'control character' => ["/de\n", 'entryPointControl'];
        yield 'null byte' => ["/de\0", 'entryPointControl'];
        yield 'backslash' => ['/de\\admin', 'entryPointInvalid'];
        yield 'credentials' => ['/de@example.com', 'entryPointInvalid'];
        yield 'space inside a segment' => ['/de about', 'entryPointInvalid'];
        yield 'non string' => [42, 'entryPointInvalid'];
    }

    /** A rejected persisted value falls back to the legacy strategy. */
    public function testAnUnreadablePersistedValueFallsBackToLegacy(): void
    {
        $this->assertSame(EntryPointNormalizer::LEGACY, $this->normalizer->normalizeOrLegacy('/de/../admin'));
        $this->assertSame('/de', $this->normalizer->normalizeOrLegacy('/de/'));
    }

    /**
     * @dataProvider boundaries
     */
    public function testMatchingIsOnCompleteSegmentBoundaries(string $entryPoint, string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->normalizer->contains($entryPoint, $path));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function boundaries(): iterable
    {
        yield 'exact' => ['/de', '/de', true];
        yield 'trailing slash' => ['/de', '/de/', true];
        yield 'child path' => ['/de', '/de/about', true];
        yield 'deep child path' => ['/de', '/de/a/b/c.html', true];
        yield 'demo is not de' => ['/de', '/demo', false];
        yield 'development is not de' => ['/de', '/development', false];
        yield 'demo child is not de' => ['/de', '/demo/about', false];
        yield 'unrelated' => ['/de', '/about', false];
        yield 'root contains everything' => ['/', '/anything/at/all', true];
        yield 'root contains the root' => ['/', '/', true];
        yield 'nested entry point' => ['/languages/de', '/languages/de/about', true];
        yield 'nested prefix is not enough' => ['/languages/de', '/languages/den', false];
    }

    /**
     * @dataProvider strippedPaths
     */
    public function testStrippingLeavesTheRoutedPath(string $entryPoint, string $path, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->strip($entryPoint, $path));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function strippedPaths(): iterable
    {
        yield 'child path' => ['/de', '/de/about', '/about'];
        yield 'exact entry point' => ['/de', '/de', '/'];
        yield 'entry point with slash' => ['/de', '/de/', '/'];
        yield 'nested' => ['/languages/de', '/languages/de/about', '/about'];
        yield 'root strips nothing' => ['/', '/about', '/about'];
        yield 'outside stays untouched' => ['/de', '/demo', '/demo'];
    }

    /**
     * @dataProvider prependedPaths
     */
    public function testPrependingHappensExactlyOnce(string $entryPoint, string $path, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->prepend($entryPoint, $path));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function prependedPaths(): iterable
    {
        yield 'plain page' => ['/de', '/about', '/de/about'];
        yield 'already prefixed' => ['/de', '/de/about', '/de/about'];
        yield 'root page' => ['/de', '/', '/de/'];
        yield 'domain root adds nothing' => ['/', '/about', '/about'];
        yield 'legacy adds nothing' => ['', '/about', '/about'];
        yield 'nested' => ['/languages/de', '/about', '/languages/de/about'];
    }

    public function testDepthOrdersLongestFirst(): void
    {
        $this->assertSame(0, $this->normalizer->depth('/'));
        $this->assertSame(0, $this->normalizer->depth(''));
        $this->assertSame(1, $this->normalizer->depth('/de'));
        $this->assertSame(2, $this->normalizer->depth('/languages/de'));
    }
}
