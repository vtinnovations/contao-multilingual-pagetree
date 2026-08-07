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
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;

/**
 * Incoming request resolution: exact hostname, entry-point boundaries, longest
 * match, published state and root isolation.
 */
class LanguageUrlResolutionTest extends TestCase
{
    private const ROOT = 1;

    /** Same domain, English at "/", German at "/de", Russian at "/ru". */
    public function testSameDomainWithPathEntryPoints(): void
    {
        $resolver = $this->sameDomainSetup();

        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/'));
        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/about'));
        $this->assertSame('de', $this->resolve($resolver, 'www.xyz.com', '/de'));
        $this->assertSame('de', $this->resolve($resolver, 'www.xyz.com', '/de/ueber-uns'));
        $this->assertSame('ru', $this->resolve($resolver, 'www.xyz.com', '/ru/o-nas'));
    }

    /** The longest valid entry point wins, and /de never matches /demo. */
    public function testTheLongestEntryPointWinsOnSegmentBoundaries(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de', entryPoint: '/de'),
                $this->record(3, 'de_at', entryPoint: '/de/at'),
            ]],
        );

        $this->assertSame('de', $this->resolve($resolver, 'www.xyz.com', '/de/impressum'));
        $this->assertSame('de_at', $this->resolve($resolver, 'www.xyz.com', '/de/at/impressum'));

        // A prefix that is not a complete segment belongs to the default language.
        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/demo'));
        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/development'));
        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/demo/about'));
    }

    /** Distinct domains that all use the domain root. */
    public function testDistinctDomainsAllUsingTheDomainRoot(): void
    {
        $resolver = $this->separateDomainSetup();

        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/about'));
        $this->assertSame('de', $this->resolve($resolver, 'www.xyz.de', '/ueber-uns'));
        $this->assertSame('ru', $this->resolve($resolver, 'www.xyz.ru', '/o-nas'));
    }

    /** Mixed: English at www.xyz.com/, German at www.xyz.de/de, Russian at www.xyz.com/ru. */
    public function testMixedDomainAndPathMappings(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de', domain: 'www.xyz.de'),
                $this->record(3, 'ru', entryPoint: '/ru'),
            ]],
        );

        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/about'));
        $this->assertSame('ru', $this->resolve($resolver, 'www.xyz.com', '/ru/o-nas'));
        $this->assertSame('de', $this->resolve($resolver, 'www.xyz.de', '/de/ueber-uns'));

        // The German prefix on the English domain is not German territory.
        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/de/ueber-uns'));
    }

    public function testUnpublishedLanguagesAreIgnored(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [$this->record(2, 'de', entryPoint: '/de', published: false)]],
        );

        $this->assertSame('en', $this->resolve($resolver, 'www.xyz.com', '/de/ueber-uns'));
        $this->assertSame('en', $resolver->mappings(self::ROOT)->match('www.xyz.com', '/de/ueber-uns')?->languageCode);
        $this->assertSame([], $resolver->mappings(self::ROOT)->published() === [] ? [] : array_values(array_filter(
            $resolver->mappings(self::ROOT)->published(),
            static fn ($mapping): bool => 'de' === $mapping->languageCode,
        )));
    }

    /** An unknown hostname is never associated with a configured root. */
    public function testAnUnknownHostnameMatchesNothingByHost(): void
    {
        $resolver = $this->separateDomainSetup();

        $this->assertFalse($resolver->mappings(self::ROOT)->claimsHost('www.attacker.example'));
        $this->assertFalse($resolver->mappings(self::ROOT)->claimsHost('xyz.com'));
        $this->assertFalse($resolver->mappings(self::ROOT)->claimsHost('www.xyz.com.evil.test'));
        $this->assertSame([], $resolver->rootsClaimingHost('www.attacker.example'));
    }

    /** Two published languages on the same host and entry point resolve to nothing. */
    public function testAnAmbiguousMappingIsNeverGuessed(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de', entryPoint: '/x'),
                $this->record(3, 'ru', entryPoint: '/x'),
            ]],
        );

        $this->assertNull($resolver->mappings(self::ROOT)->match('www.xyz.com', '/x/page'));
    }

    /** Two languages that differ only by protocol stay ambiguous for a request. */
    public function testProtocolAloneNeverDistinguishesTwoLanguages(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(2, 'de', entryPoint: '/x', protocol: 'https'),
                $this->record(3, 'ru', entryPoint: '/x', protocol: 'http'),
            ]],
        );

        $this->assertSame(
            $resolver->forLanguage(self::ROOT, 'de')->targetKey(),
            $resolver->forLanguage(self::ROOT, 'ru')->targetKey(),
        );
        $this->assertNull($resolver->mappings(self::ROOT)->match('www.xyz.com', '/x/page'));
    }

    /** A mapping of another root is never consulted. */
    public function testMappingsAreIsolatedPerRoot(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [
                1 => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en'],
                2 => ['host' => 'www.other.com', 'secure' => true, 'language' => 'fr'],
            ],
            [
                1 => [$this->record(2, 'de', entryPoint: '/de')],
                2 => [$this->record(9, 'it', entryPoint: '/it')],
            ],
        );

        $this->assertNull($resolver->forLanguage(1, 'it'));
        $this->assertNull($resolver->forLanguage(2, 'de'));
        $this->assertNull($resolver->mappings(1)->match('www.other.com', '/it/x'));
        $this->assertNotNull($resolver->mappings(2)->match('www.other.com', '/it/x'));
        $this->assertStringNotContainsString(
            $resolver->mappings(2)->cacheKey(),
            $resolver->mappings(1)->cacheKey(),
        );
    }

    /** A language hostname associates the request with its owning root only. */
    public function testARootIsClaimedThroughItsExactLanguageHostname(): void
    {
        $resolver = $this->separateDomainSetup();

        $this->assertSame([self::ROOT], $resolver->rootsClaimingHost('www.xyz.de'));
        $this->assertSame([self::ROOT], $resolver->rootsClaimingHost('www.xyz.com'));
        $this->assertSame([], $resolver->rootsClaimingHost('www.xyz.example'));
    }

    /** Without any configured mapping the previous URL strategy is preserved. */
    public function testAnUnconfiguredRootKeepsThePreviousStrategy(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [$this->record(2, 'de'), $this->record(3, 'ru')]],
        );

        $this->assertFalse($resolver->mappings(self::ROOT)->hasCustomMapping());
        $this->assertSame('/de', $resolver->entryPoint(self::ROOT, 'de'));
        $this->assertSame('/', $resolver->entryPoint(self::ROOT, 'en'));

        // The hostname was never part of the decision before, so it is not now.
        $this->assertSame('de', $this->resolve($resolver, 'staging.internal.test', '/de/x'));
        $this->assertSame('en', $this->resolve($resolver, 'staging.internal.test', '/x'));
    }

    /** The matched route always wins over any path inspection. */
    public function testTheMatchedRouteIsAuthoritative(): void
    {
        $resolver = $this->sameDomainSetup();
        $incoming = new IncomingLanguageResolver($resolver);
        $request = Request::create('https://www.xyz.com/de/ueber-uns');
        $request->attributes->set(IncomingLanguageResolver::ROUTE_ATTRIBUTE, 'ru');

        $this->assertSame('ru', $incoming->resolve($request, self::ROOT, 'en', ['en', 'de', 'ru']));
    }

    private function resolve(LanguageUrlResolver $resolver, string $host, string $path): string
    {
        $incoming = new IncomingLanguageResolver($resolver);
        $request = Request::create('https://'.$host.$path);

        return $incoming->resolve($request, self::ROOT, 'en', ['en', 'de', 'de_at', 'ru']);
    }

    private function sameDomainSetup(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(1, 'en', entryPoint: '/'),
                $this->record(2, 'de', entryPoint: '/de'),
                $this->record(3, 'ru', entryPoint: '/ru'),
            ]],
        );
    }

    private function separateDomainSetup(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'en']],
            [self::ROOT => [
                $this->record(1, 'en', entryPoint: '/'),
                $this->record(2, 'de', domain: 'www.xyz.de', entryPoint: '/'),
                $this->record(3, 'ru', domain: 'www.xyz.ru', entryPoint: '/'),
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
