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
use Vtinnovations\ContaoMultilingualPagetree\Metadata\AbsoluteUrlBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\ProtocolMode;

/**
 * URL generation: effective protocol, exact hostname, the entry point exactly
 * once, and no leakage of the source language's origin.
 */
class LanguageUrlGenerationTest extends TestCase
{
    private const ROOT = 1;

    public function testEffectiveValuesFollowTheConfiguration(): void
    {
        $resolver = $this->mixedSetup();

        $english = $resolver->forLanguage(self::ROOT, 'en');
        $this->assertSame('https', $english->effectiveProtocol);
        $this->assertSame('www.xyz.com', $english->effectiveHostname);
        $this->assertSame('/', $english->effectiveEntryPoint);
        $this->assertSame('https://www.xyz.com', $english->canonicalOrigin());
        $this->assertSame('https://www.xyz.com', $english->canonicalBaseUrl());

        $german = $resolver->forLanguage(self::ROOT, 'de');
        $this->assertSame('www.xyz.de', $german->effectiveHostname);
        $this->assertSame('/', $german->effectiveEntryPoint);
        $this->assertSame('https://www.xyz.de', $german->canonicalBaseUrl());

        $russian = $resolver->forLanguage(self::ROOT, 'ru');
        $this->assertSame('www.xyz.com', $russian->effectiveHostname, 'An empty domain inherits the website root hostname.');
        $this->assertSame('/ru', $russian->effectiveEntryPoint);
        $this->assertSame('https://www.xyz.com/ru', $russian->canonicalBaseUrl());
    }

    /** An explicit protocol overrides the website root; an empty one inherits. */
    public function testProtocolInheritanceAndOverride(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de'),
                $this->record(3, 'ru', protocol: 'http'),
                $this->record(4, 'fr', protocol: 'https'),
            ]],
        );

        $this->assertTrue($resolver->forLanguage(self::ROOT, 'de')->hasInheritedProtocol());
        $this->assertSame('https', $resolver->forLanguage(self::ROOT, 'de')->effectiveProtocol);
        $this->assertSame('http', $resolver->forLanguage(self::ROOT, 'ru')->effectiveProtocol);
        $this->assertSame('https', $resolver->forLanguage(self::ROOT, 'fr')->effectiveProtocol);
        $this->assertSame(ProtocolMode::Http, $resolver->forLanguage(self::ROOT, 'ru')->configuredProtocol);

        $insecure = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => false, 'language' => 'en']],
            [self::ROOT => [$this->record(2, 'de')]],
        );

        $this->assertSame('http', $insecure->forLanguage(self::ROOT, 'de')->effectiveProtocol);
    }

    /** The entry point is added exactly once and never duplicated. */
    public function testPagePathsCarryTheEntryPointExactlyOnce(): void
    {
        $policy = new CanonicalUrlPolicy();

        $this->assertSame('/de/ueber-uns.html', $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false, '/de'));
        $this->assertSame('/ueber-uns.html', $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false, '/'));
        $this->assertSame('/languages/de/ueber-uns.html', $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false, '/languages/de'));
        $this->assertSame('/de/', $policy->buildPagePath('en', 'de', 'index', null, '', true, '/de'));
        $this->assertSame('/', $policy->buildPagePath('en', 'de', 'index', null, '', true, '/'));

        // No path ever contains a doubled prefix or a doubled slash.
        foreach (['/de', '/', '/languages/de'] as $entryPoint) {
            $path = $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false, $entryPoint);
            $this->assertStringNotContainsString('//', $path);
            $this->assertSame(1, substr_count($path, 'ueber-uns'));
        }

        $this->assertStringNotContainsString('/de/de/', $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false, '/de'));
    }

    /** Passing no entry point keeps the previous prefix strategy. */
    public function testTheLegacyPrefixStrategyIsPreservedWithoutAMapping(): void
    {
        $policy = new CanonicalUrlPolicy();

        $this->assertSame('/de/ueber-uns.html', $policy->buildPagePath('en', 'de', 'about-us', 'ueber-uns', '.html', false));
        $this->assertSame('/about-us.html', $policy->buildPagePath('en', 'en', 'about-us', null, '.html', false));
        $this->assertSame('/de/', $policy->buildPagePath('en', 'de', 'index', null, '', true));
        $this->assertSame('/', $policy->buildPagePath('en', 'en', 'index', null, '', true));
    }

    /** A target on another origin is absolute; a target on the same origin is not. */
    public function testCrossOriginTargetsBecomeAbsolute(): void
    {
        $resolver = $this->mixedSetup();
        $request = Request::create('https://www.xyz.com/about');

        $this->assertSame('/about', $resolver->url($resolver->forLanguage(self::ROOT, 'en'), '/about', $request));
        $this->assertSame('/ru/o-nas', $resolver->url($resolver->forLanguage(self::ROOT, 'ru'), '/ru/o-nas', $request));
        $this->assertSame(
            'https://www.xyz.de/de/ueber-uns',
            $resolver->url($resolver->forLanguage(self::ROOT, 'de'), '/de/ueber-uns', $request),
        );
    }

    /** A generated URL never carries the hostname of the source language. */
    public function testNoSourceDomainLeaksIntoATargetUrl(): void
    {
        $resolver = $this->mixedSetup();
        $request = Request::create('https://www.xyz.de/de/ueber-uns');

        $english = $resolver->url($resolver->forLanguage(self::ROOT, 'en'), '/about', $request, true);
        $russian = $resolver->url($resolver->forLanguage(self::ROOT, 'ru'), '/ru/o-nas', $request, true);

        $this->assertSame('https://www.xyz.com/about', $english);
        $this->assertSame('https://www.xyz.com/ru/o-nas', $russian);
        $this->assertStringNotContainsString('www.xyz.de', $english);
        $this->assertStringNotContainsString('www.xyz.de', $russian);
    }

    /** Canonical, hreflang and x-default are always absolute. */
    public function testMetadataUrlsUseTheTargetLanguageOrigin(): void
    {
        $resolver = $this->mixedSetup();
        $builder = new AbsoluteUrlBuilder();
        $request = Request::create('https://www.xyz.com/about');

        $this->assertSame(
            'https://www.xyz.de/de/ueber-uns',
            $builder->build(null, '/de/ueber-uns', $request, $resolver->forLanguage(self::ROOT, 'de')),
        );
        $this->assertSame(
            'https://www.xyz.com/about',
            $builder->build(null, '/about', $request, $resolver->forLanguage(self::ROOT, 'en')),
        );

        // x-default points at the default language's canonical URL.
        $default = $resolver->mappings(self::ROOT)->defaultLanguage();
        $this->assertSame('en', $default->languageCode);
        $this->assertSame('https://www.xyz.com', $default->canonicalBaseUrl());
    }

    /** Doubled slashes are collapsed without touching the query string. */
    public function testDuplicateSlashesAreCollapsedAndQueriesPreserved(): void
    {
        $resolver = $this->mixedSetup();
        $request = Request::create('https://www.xyz.com/about');

        $this->assertSame(
            'https://www.xyz.de/de/ueber-uns?page=2',
            $resolver->url($resolver->forLanguage(self::ROOT, 'de'), '//de//ueber-uns?page=2', $request),
        );
        $this->assertSame(
            '/ru/o-nas?page=2&filter=a',
            $resolver->url($resolver->forLanguage(self::ROOT, 'ru'), '/ru//o-nas?page=2&filter=a', $request),
        );
    }

    /** A root without a configured domain produces no origin and stays relative. */
    public function testARootWithoutADomainKeepsRelativeUrls(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => null, 'secure' => false, 'language' => 'en']],
            [self::ROOT => [$this->record(2, 'de')]],
        );

        $mapping = $resolver->forLanguage(self::ROOT, 'de');
        $request = Request::create('http://localhost/de/x');

        $this->assertNull($mapping->effectiveHostname);
        $this->assertNull($mapping->canonicalOrigin());
        $this->assertNull($mapping->canonicalBaseUrl());
        $this->assertSame('/de/x', $resolver->url($mapping, '/de/x', $request));
        $this->assertSame('http://localhost/de/x', $resolver->absoluteUrl($mapping, '/de/x', $request));
    }

    /** Every mapping carries enough context for a collision-free cache key. */
    public function testCacheKeysCoverRootDomainEntryPointProtocolAndPublication(): void
    {
        $resolver = $this->mixedSetup();
        $german = $resolver->forLanguage(self::ROOT, 'de');

        $this->assertStringContainsString('r1', $german->cacheKey());
        $this->assertStringContainsString('www.xyz.de', $german->cacheKey());
        $this->assertStringContainsString('|/|domain_root|', $german->cacheKey());
        $this->assertStringContainsString('https', $german->cacheKey());
        $this->assertNotSame($german->cacheKey(), $german->withPublished(false)->cacheKey());
        $this->assertNotSame($german->cacheKey(), $resolver->forLanguage(self::ROOT, 'ru')->cacheKey());
    }

    /** An empty entry point and an explicit "/" remain distinguishable. */
    public function testInheritedAndExplicitEntryPointsStayDistinguishable(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de'),
                $this->record(3, 'ru', domain: 'www.xyz.ru', entryPoint: '/'),
            ]],
        );

        $german = $resolver->forLanguage(self::ROOT, 'de');
        $russian = $resolver->forLanguage(self::ROOT, 'ru');

        $this->assertTrue($german->hasInheritedEntryPoint());
        $this->assertSame(EntryPointNormalizer::LEGACY, $german->configuredEntryPoint);
        $this->assertSame('/de', $german->effectiveEntryPoint);

        $this->assertTrue($russian->hasExplicitEntryPoint());
        $this->assertSame(EntryPointNormalizer::ROOT, $russian->configuredEntryPoint);
        $this->assertSame('/', $russian->effectiveEntryPoint);
    }

    private function mixedSetup(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(1, 'en', entryPoint: '/'),
                $this->record(2, 'de', domain: 'www.xyz.de'),
                $this->record(3, 'ru', entryPoint: '/ru'),
            ]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        int $id,
        string $language,
        string $protocol = '',
        string $domain = '',
        string $entryPoint = '',
        bool $published = true,
    ): array {
        return [
            'id' => $id,
            'language' => $language,
            'urlProtocol' => $protocol,
            'urlDomain' => $domain,
            'urlEntryPoint' => $entryPoint,
            'published' => $published,
        ];
    }
}
