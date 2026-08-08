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
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryLanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\IncomingLanguageResolver;

/**
 * An installation whose new URL fields are all empty must behave exactly as it
 * did before the fields existed.
 */
class LanguageUrlCompatibilityTest extends TestCase
{
    private const ROOT = 1;

    private function legacyResolver(): InMemoryLanguageUrlResolver
    {
        return new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'de']],
            [self::ROOT => [
                ['id' => 2, 'language' => 'en', 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => '', 'published' => true],
                ['id' => 3, 'language' => 'fr', 'urlProtocol' => '', 'urlDomain' => '', 'urlEntryPoint' => '', 'published' => true],
            ]],
        );
    }

    public function testEmptyFieldsKeepTheRootDomainAndProtocol(): void
    {
        $resolver = $this->legacyResolver();

        foreach (['de', 'en', 'fr'] as $language) {
            $mapping = $resolver->forLanguage(self::ROOT, $language);

            $this->assertTrue($mapping->hasInheritedDomain());
            $this->assertTrue($mapping->hasInheritedProtocol());
            $this->assertTrue($mapping->hasInheritedEntryPoint());
            $this->assertSame('www.xyz.com', $mapping->effectiveHostname);
            $this->assertSame('https', $mapping->effectiveProtocol);
        }
    }

    /** The default language stays unprefixed, every other language keeps its code. */
    public function testEmptyFieldsKeepThePreviousPrefixStrategy(): void
    {
        $resolver = $this->legacyResolver();

        $this->assertSame('/', $resolver->entryPoint(self::ROOT, 'de'));
        $this->assertSame('/en', $resolver->entryPoint(self::ROOT, 'en'));
        $this->assertSame('/fr', $resolver->entryPoint(self::ROOT, 'fr'));
        $this->assertFalse($resolver->mappings(self::ROOT)->hasCustomMapping());
    }

    /** The generated paths are byte-for-byte the ones the previous code produced. */
    public function testGeneratedPathsAreUnchanged(): void
    {
        $resolver = $this->legacyResolver();
        $policy = new CanonicalUrlPolicy();

        $withMapping = $policy->buildPagePath('de', 'en', 'ueber-uns', 'about-us', '.html', false, $resolver->entryPoint(self::ROOT, 'en'));
        $withoutMapping = $policy->buildPagePath('de', 'en', 'ueber-uns', 'about-us', '.html', false);

        $this->assertSame('/en/about-us.html', $withMapping);
        $this->assertSame($withoutMapping, $withMapping);

        $this->assertSame(
            $policy->buildPagePath('de', 'de', 'ueber-uns', null, '.html', false),
            $policy->buildPagePath('de', 'de', 'ueber-uns', null, '.html', false, $resolver->entryPoint(self::ROOT, 'de')),
        );
    }

    /** Links stay relative because no language leaves the root's origin. */
    public function testGeneratedUrlsStayRelative(): void
    {
        $resolver = $this->legacyResolver();
        $request = Request::create('https://www.xyz.com/en/about-us.html');

        $this->assertSame('/en/about-us.html', $resolver->url($resolver->forLanguage(self::ROOT, 'en'), '/en/about-us.html', $request));
        $this->assertSame('/ueber-uns.html', $resolver->url($resolver->forLanguage(self::ROOT, 'de'), '/ueber-uns.html', $request));
    }

    /** Language resolution still works off the language-code prefix. */
    public function testIncomingResolutionStillUsesTheLanguageCodePrefix(): void
    {
        $resolver = $this->legacyResolver();
        $incoming = new IncomingLanguageResolver($resolver);

        $this->assertSame('en', $incoming->resolve(Request::create('https://www.xyz.com/en/about-us.html'), self::ROOT, 'de', ['de', 'en', 'fr']));
        $this->assertSame('fr', $incoming->resolve(Request::create('https://www.xyz.com/fr/'), self::ROOT, 'de', ['de', 'en', 'fr']));
        $this->assertSame('de', $incoming->resolve(Request::create('https://www.xyz.com/ueber-uns.html'), self::ROOT, 'de', ['de', 'en', 'fr']));
        $this->assertSame('de', $incoming->resolve(Request::create('https://www.xyz.com/'), self::ROOT, 'de', ['de', 'en', 'fr']));
    }

    /** A language that is not configured never wins, even with a matching prefix. */
    public function testAnUnconfiguredLanguagePrefixFallsBackToTheDefault(): void
    {
        $resolver = $this->legacyResolver();
        $incoming = new IncomingLanguageResolver($resolver);

        $this->assertSame('de', $incoming->resolve(Request::create('https://www.xyz.com/it/x'), self::ROOT, 'de', ['de', 'en', 'fr']));
    }

    /** The root's own language always has a mapping, even without a record. */
    public function testTheRootLanguageAlwaysHasAMapping(): void
    {
        $resolver = new InMemoryLanguageUrlResolver(
            [self::ROOT => ['host' => 'www.xyz.com', 'secure' => true, 'language' => 'de']],
            [self::ROOT => []],
        );

        $default = $resolver->mappings(self::ROOT)->defaultLanguage();

        $this->assertNotNull($default);
        $this->assertSame('de', $default->languageCode);
        $this->assertSame(0, $default->languageId);
        $this->assertSame('/', $default->effectiveEntryPoint);
        $this->assertTrue($default->isPublished);
    }
}
