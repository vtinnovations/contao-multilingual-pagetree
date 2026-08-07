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
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointOrigin;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;

/**
 * A language with a domain of its own and no entry point is served from that
 * domain's root.
 *
 * The reported defect: German at `bauland.taheri.cool` and English at
 * `bauland.taheri.cool/en` were correct, but Russian - configured with the
 * domain `bauland-ru.taheri.cool` and an empty entry point - had its language
 * code derived anyway. `bauland-ru.taheri.cool` returned 404 while
 * `bauland-ru.taheri.cool/ru` rendered the site.
 */
class LanguageDomainRootTest extends TestCase
{
    private const ROOT = 1;
    private const GERMAN_HOST = 'bauland.taheri.cool';
    private const RUSSIAN_HOST = 'bauland-ru.taheri.cool';

    /** The live configuration of the reported site. */
    private function liveSetup(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                // English: inherited domain, explicit entry point.
                ['id' => 2, 'language' => 'en', 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => '/en', 'published' => true],
                // Russian: own domain, no entry point.
                ['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => self::RUSSIAN_HOST, 'urlEntryPoint' => '', 'published' => true],
            ]],
        );
    }

    /** The whole defect, in one assertion. */
    public function testAnOwnDomainWithoutAnEntryPointServesTheDomainRoot(): void
    {
        $russian = $this->liveSetup()->forLanguage(self::ROOT, 'ru');

        $this->assertSame('/', $russian->effectiveEntryPoint, 'The language code must not be derived.');
        $this->assertSame(self::RUSSIAN_HOST, $russian->effectiveHostname);
        $this->assertSame('https://'.self::RUSSIAN_HOST, $russian->canonicalOrigin());
        $this->assertSame('https://'.self::RUSSIAN_HOST, $russian->canonicalBaseUrl());
        $this->assertStringNotContainsString('/ru', (string) $russian->canonicalBaseUrl());
    }

    /** The derivation records which rule produced it. */
    public function testTheEntryPointOriginIsRecorded(): void
    {
        $resolver = $this->liveSetup();

        $this->assertSame(EntryPointOrigin::DomainRoot, $resolver->forLanguage(self::ROOT, 'ru')->entryPointOrigin);
        $this->assertSame(EntryPointOrigin::Explicit, $resolver->forLanguage(self::ROOT, 'en')->entryPointOrigin);
        $this->assertTrue($resolver->forLanguage(self::ROOT, 'ru')->hasDomainRootEntryPoint());
        $this->assertFalse($resolver->forLanguage(self::ROOT, 'en')->hasDomainRootEntryPoint());

        // The segment the language used to occupy is known, so a stale URL can
        // be recognised - but it is not part of any generated URL.
        $this->assertSame('/ru', $resolver->forLanguage(self::ROOT, 'ru')->legacyPrefix());
        $this->assertNull($resolver->forLanguage(self::ROOT, 'en')->legacyPrefix());
    }

    /** German and English must be exactly as they were. */
    public function testGermanAndEnglishAreUnchanged(): void
    {
        $resolver = $this->liveSetup();

        $german = $resolver->mappings(self::ROOT)->defaultLanguage();
        $this->assertSame('de', $german->languageCode);
        $this->assertSame('/', $german->effectiveEntryPoint);
        $this->assertSame('https://'.self::GERMAN_HOST, $german->canonicalBaseUrl());

        $english = $resolver->forLanguage(self::ROOT, 'en');
        $this->assertSame('/en', $english->effectiveEntryPoint);
        $this->assertSame(self::GERMAN_HOST, $english->effectiveHostname);
        $this->assertSame('https://'.self::GERMAN_HOST.'/en', $english->canonicalBaseUrl());
    }

    /**
     * @dataProvider entryPointRules
     */
    public function testEveryEntryPointRule(string $domain, string $entryPoint, string $expected, EntryPointOrigin $origin): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                ['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => $domain, 'urlEntryPoint' => $entryPoint, 'published' => true],
            ]],
        );

        $mapping = $resolver->forLanguage(self::ROOT, 'ru');

        $this->assertSame($expected, $mapping->effectiveEntryPoint);
        $this->assertSame($origin, $mapping->entryPointOrigin);
    }

    /**
     * @return iterable<string, array{string, string, string, EntryPointOrigin}>
     */
    public static function entryPointRules(): iterable
    {
        yield 'own domain, no entry point -> domain root' => [self::RUSSIAN_HOST, '', '/', EntryPointOrigin::DomainRoot];
        yield 'own domain, explicit /ru -> /ru' => [self::RUSSIAN_HOST, '/ru', '/ru', EntryPointOrigin::Explicit];
        yield 'own domain, explicit / -> domain root' => [self::RUSSIAN_HOST, '/', '/', EntryPointOrigin::Explicit];
        yield 'inherited domain, explicit /ru -> /ru' => ['', '/ru', '/ru', EntryPointOrigin::Explicit];
        // The one case that must not change: no domain and no entry point keeps
        // the strategy the record had before these fields existed.
        yield 'inherited domain, no entry point -> legacy prefix' => ['', '', '/ru', EntryPointOrigin::Legacy];
    }

    /** The Russian domain root resolves Russian without any path prefix. */
    public function testTheRussianDomainRootResolvesRussian(): void
    {
        $resolver = $this->liveSetup();

        foreach (['/', '/about-page', '/a/b/c.html'] as $path) {
            $this->assertSame(
                'ru',
                $resolver->mappings(self::ROOT)->match(self::RUSSIAN_HOST, $path)?->languageCode,
                $path,
            );
        }
    }

    /** The other two languages still resolve on their own host. */
    public function testTheOtherLanguagesStillResolve(): void
    {
        $set = $this->liveSetup()->mappings(self::ROOT);

        $this->assertSame('de', $set->match(self::GERMAN_HOST, '/')?->languageCode);
        $this->assertSame('de', $set->match(self::GERMAN_HOST, '/impressum')?->languageCode);
        $this->assertSame('en', $set->match(self::GERMAN_HOST, '/en')?->languageCode);
        $this->assertSame('en', $set->match(self::GERMAN_HOST, '/en/about')?->languageCode);
    }

    /** An unknown or neighbouring host is never pulled into the root. */
    public function testForeignHostsAreRejected(): void
    {
        $set = $this->liveSetup()->mappings(self::ROOT);

        $this->assertTrue($set->claimsHost(self::RUSSIAN_HOST));
        $this->assertTrue($set->claimsHost(self::GERMAN_HOST));

        foreach (['taheri.cool', 'www.bauland-ru.taheri.cool', 'bauland-ru.taheri.cool.evil.test', 'other.example'] as $foreign) {
            $this->assertFalse($set->claimsHost($foreign), $foreign);
        }
    }

    /** No language contributes a path prefix it does not use. */
    public function testTheRussianCodeIsNoLongerAPathPrefix(): void
    {
        $set = $this->liveSetup()->mappings(self::ROOT);

        $this->assertSame(['en'], $set->explicitPrefixes());
        $this->assertNotContains('ru', $set->explicitPrefixes());
    }

    /** The whole request path stays intact below the Russian domain root. */
    public function testTheRequestPathIsNotReducedByAPrefix(): void
    {
        $resolver = $this->liveSetup();
        $incoming = new IncomingLanguageResolver($resolver);

        $request = Request::create('https://'.self::RUSSIAN_HOST.'/about-page?page=2');

        $this->assertSame('ru', $incoming->resolve($request, self::ROOT, 'de', ['de', 'en', 'ru']));
        $this->assertSame('/about-page', $request->getPathInfo());
    }

    /** Cache keys change with the derived entry point, so nothing stale survives. */
    public function testCacheKeysReflectTheCorrectedEntryPoint(): void
    {
        $corrected = $this->liveSetup()->forLanguage(self::ROOT, 'ru')->cacheKey();

        $stale = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => self::GERMAN_HOST, 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                ['id' => 3, 'language' => 'ru', 'urlProtocol' => '', 'urlDomain' => self::RUSSIAN_HOST, 'urlEntryPoint' => '/ru', 'published' => true],
            ]],
        );

        $this->assertNotSame($stale->forLanguage(self::ROOT, 'ru')->cacheKey(), $corrected);
        $this->assertStringContainsString(EntryPointOrigin::DomainRoot->value, $corrected);
        $this->assertStringContainsString('r'.self::ROOT, $corrected);
        $this->assertStringContainsString(self::RUSSIAN_HOST, $corrected);
    }
}
