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
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;

/**
 * Finding the website root of a language's own hostname.
 *
 * This is the step that was missing. Contao resolves a root from `tl_page.dns`,
 * and for the path `/` it asks nothing else - so a hostname that only exists on
 * a language record produced no routes at all and a 404, no matter what the
 * effective entry point said. The active route-provider decorator consults the
 * exact lookup below before bootstrapping the owning root route.
 */
class LanguageHostRootAssociationTest extends TestCase
{
    private const ROOT = 1;
    private const OTHER_ROOT = 2;
    private const GERMAN_HOST = 'bauland.taheri.cool';
    private const RUSSIAN_HOST = 'bauland-ru.taheri.cool';

    private function resolver(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [
                self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de'],
                self::OTHER_ROOT => ['host' => 'other.example', 'secure' => true, 'language' => 'fr'],
            ],
            [
                self::ROOT => [
                    ['id' => 2, 'language' => 'en', 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => '/en', 'published' => true],
                    ['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => self::RUSSIAN_HOST, 'urlEntryPoint' => '', 'published' => true],
                ],
                self::OTHER_ROOT => [
                    ['id' => 9, 'language' => 'it', 'urlProtocol' => '', 'urlDomain' => 'other-it.example', 'urlEntryPoint' => '', 'published' => true],
                ],
            ],
        );
    }

    /** The defect, at the layer that actually caused it. */
    public function testALanguageHostnameFindsItsOwningRoot(): void
    {
        $this->assertSame(self::ROOT, $this->resolver()->rootForLanguageHost(self::RUSSIAN_HOST));
    }

    /** Each root only ever answers for its own language domains. */
    public function testEachRootAnswersOnlyForItsOwnLanguageDomains(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(self::ROOT, $resolver->rootForLanguageHost(self::RUSSIAN_HOST));
        $this->assertSame(self::OTHER_ROOT, $resolver->rootForLanguageHost('other-it.example'));
    }

    /**
     * A root's own primary domain is Contao's business.
     *
     * Answering here would take over resolution Contao already performs
     * correctly - including for roots this bundle knows nothing about.
     */
    public function testARootsOwnDomainIsLeftToContao(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->rootForLanguageHost(self::GERMAN_HOST));
        $this->assertNull($resolver->rootForLanguageHost('other.example'));
    }

    /**
     * @dataProvider foreignHosts
     */
    public function testAHostNoRecordNamesIsRefused(string $host): void
    {
        $this->assertNull($this->resolver()->rootForLanguageHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignHosts(): iterable
    {
        yield 'unknown host' => ['unknown.example'];
        yield 'parent domain' => ['taheri.cool'];
        yield 'sibling subdomain' => ['bauland-de.taheri.cool'];
        yield 'www variant' => ['www.bauland-ru.taheri.cool'];
        yield 'suffix attack' => ['bauland-ru.taheri.cool.evil.test'];
        yield 'prefix attack' => ['evil-bauland-ru.taheri.cool'];
        yield 'wildcard' => ['*.taheri.cool'];
        yield 'empty' => [''];
    }

    /** Case and a trailing dot are normalised; nothing else is. */
    public function testTheComparisonIsNormalisedButExact(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(self::ROOT, $resolver->rootForLanguageHost(strtoupper(self::RUSSIAN_HOST)));
        $this->assertSame(self::ROOT, $resolver->rootForLanguageHost(self::RUSSIAN_HOST.'.'));
    }

    /** An unpublished language claims no hostname. */
    public function testAnUnpublishedLanguageClaimsNoHostname(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                ['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => self::RUSSIAN_HOST, 'urlEntryPoint' => '', 'published' => false],
            ]],
        );

        $this->assertNull($resolver->rootForLanguageHost(self::RUSSIAN_HOST));
    }

    /** A language without its own domain claims no hostname either. */
    public function testAnInheritedDomainClaimsNoHostname(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                ['id' => 2, 'language' => 'en', 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => '/en', 'published' => true],
            ]],
        );

        $this->assertNull($resolver->rootForLanguageHost(self::GERMAN_HOST));
        $this->assertNull($resolver->rootForLanguageHost('anything.example'));
    }

    /** A host two roots somehow claim is refused rather than guessed. */
    public function testAnAmbiguousHostIsRefused(): void
    {
        $shared = 'shared.example';

        $resolver = new InMemoryLanguageUrlResolver(
            [
                self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de'],
                self::OTHER_ROOT => ['host' => 'other.example', 'secure' => true, 'language' => 'fr'],
            ],
            [
                self::ROOT => [['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => $shared, 'urlEntryPoint' => '', 'published' => true]],
                self::OTHER_ROOT => [['id' => 9, 'language' => 'it', 'urlProtocol' => '', 'urlDomain' => $shared, 'urlEntryPoint' => '', 'published' => true]],
            ],
        );

        $this->assertNull($resolver->rootForLanguageHost($shared));
    }

    /**
     * The association does not depend on a path.
     *
     * This is the whole point: `/` had to work, and it only ever failed because
     * the host alone was not enough to find the root.
     */
    public function testTheAssociationNeedsNoPath(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(self::ROOT, $resolver->rootForLanguageHost(self::RUSSIAN_HOST));
        $this->assertSame('ru', $resolver->mappings(self::ROOT)->match(self::RUSSIAN_HOST, '/')?->languageCode);
        $this->assertSame('/', $resolver->forLanguage(self::ROOT, 'ru')->effectiveEntryPoint);
    }
}
