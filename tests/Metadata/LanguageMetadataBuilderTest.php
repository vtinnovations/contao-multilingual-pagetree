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
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityReason;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailContext;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResult;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\LanguageMetadata;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\AvailabilityStack;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeDetailTargetResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

class LanguageMetadataBuilderTest extends TestCase
{
    use PageModelMockTrait;

    /** Requirements 41, 42 and 48 */
    public function testDefaultLanguageCanonicalIsUnprefixedAndAlternatesAreEmitted(): void
    {
        $metadata = $this->pageMetadata('en', ['de' => 'fallback'], []);

        $this->assertSame('http://localhost/about-us', $metadata->canonicalUrl);
        $this->assertSame(
            ['en' => 'http://localhost/about-us', 'de' => 'http://localhost/de/about-us'],
            $metadata->alternates,
        );
    }

    /** Requirements 43, 46 and 54 */
    public function testNonDefaultCanonicalIsPrefixedAndSelfReferencing(): void
    {
        $metadata = $this->pageMetadata(
            'de',
            ['de' => 'fallback'],
            ['tl_page_translation|10|de' => $this->pageTranslation(10, 'ueber-uns')],
            '/de/ueber-uns',
        );

        $this->assertSame('http://localhost/de/ueber-uns', $metadata->canonicalUrl);
        $this->assertSame('http://localhost/de/ueber-uns', $metadata->alternates['de'] ?? null);
    }

    /** Requirements 44 and 50 */
    public function testFallbackPageCanonicalUsesItsOwnLanguageUrl(): void
    {
        $metadata = $this->pageMetadata('de', ['de' => 'fallback'], [], '/de/about-us');

        $this->assertSame('http://localhost/de/about-us', $metadata->canonicalUrl, 'A fallback page is not canonicalised to the default language.');
        $this->assertSame('http://localhost/de/about-us', $metadata->alternates['de'] ?? null);
        $this->assertSame('http://localhost/about-us', $metadata->xDefaultUrl);
    }

    /** Requirement 45: the redirect-only fallback alias is never canonical. */
    public function testRedirectOnlyAliasIsNotCanonical(): void
    {
        $metadata = $this->pageMetadata(
            'de',
            ['de' => 'fallback'],
            ['tl_page_translation|10|de' => $this->pageTranslation(10, 'ueber-uns')],
            '/de/ueber-uns',
        );

        $this->assertNotContains('http://localhost/de/about-us', array_values($metadata->alternates));
        $this->assertSame('http://localhost/de/ueber-uns', $metadata->canonicalUrl);
    }

    /** Requirements 49 and 55 */
    public function testStrictUnavailableLanguagesAndPrefixedDefaultDuplicatesAreExcluded(): void
    {
        $metadata = $this->pageMetadata('en', ['de' => 'strict'], []);

        $this->assertSame(['en' => 'http://localhost/about-us'], $metadata->alternates);
        $this->assertArrayNotHasKey('de', $metadata->alternates);

        foreach ($metadata->alternates as $url) {
            $this->assertStringNotContainsString('/en/', $url, 'The default language is never advertised with a prefix.');
        }
    }

    /** Requirements 51, 52 and 53 */
    public function testMissingDetailTranslationsEmitNoDetailAlternate(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            ['en' => DetailTargetResult::available('/about-us/source-news', '/about-us/source-news', 'source-news')],
            ResourceAvailabilityReason::MissingDetailTranslation,
        );

        $metadata = $this->detailMetadata('en', ['de' => 'fallback'], $detail);

        $this->assertSame(['en' => 'http://localhost/about-us/source-news'], $metadata->alternates);
        $this->assertArrayNotHasKey('de', $metadata->alternates);
        $this->assertSame('http://localhost/about-us/source-news', $metadata->canonicalUrl);
    }

    /** Requirement 46: a detail canonical uses the translated detail alias. */
    public function testDetailCanonicalUsesTheTranslatedDetailAlias(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            [
                'de' => DetailTargetResult::available('/de/ueber-uns/uebersetzte-news', '/de/ueber-uns/uebersetzte-news', 'uebersetzte-news'),
                'en' => DetailTargetResult::available('/about-us/source-news', '/about-us/source-news', 'source-news'),
            ],
        );

        $metadata = $this->detailMetadata('de', ['de' => 'fallback'], $detail);

        $this->assertSame('http://localhost/de/ueber-uns/uebersetzte-news', $metadata->canonicalUrl);
        $this->assertSame('http://localhost/about-us/source-news', $metadata->xDefaultUrl);
    }

    /** Requirements 60 and 62 */
    public function testXDefaultPointsAtTheDefaultLanguageEquivalentExactlyOnce(): void
    {
        $metadata = $this->pageMetadata('de', ['de' => 'fallback', 'fr' => 'fallback'], [], '/de/about-us');

        $this->assertSame('http://localhost/about-us', $metadata->xDefaultUrl);
        $this->assertSame(1, $this->countHreflang($metadata, 'x-default'));
    }

    /** Requirement 61 */
    public function testXDefaultIsOmittedWithoutADefaultLanguageDetailUrl(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::FAQ, 7, 'source-faq'),
            ['de' => DetailTargetResult::available('/de/faq/uebersetzte-frage', '/de/faq/uebersetzte-frage', 'uebersetzte-frage')],
            ResourceAvailabilityReason::MissingDetailRecord,
        );

        $metadata = $this->detailMetadata('de', ['de' => 'fallback'], $detail);

        $this->assertNull($metadata->xDefaultUrl);
        $this->assertSame(['de' => 'http://localhost/de/faq/uebersetzte-frage'], $metadata->alternates);
    }

    /** Requirements 47 and 58 */
    public function testAlternatesAreDeduplicated(): void
    {
        // Two configured languages resolving to the same canonical URL.
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            [
                'en' => DetailTargetResult::available('/about-us/source-news', '/about-us/source-news', 'source-news'),
                'de' => DetailTargetResult::available('/about-us/source-news/', '/about-us/source-news/', 'source-news'),
            ],
        );

        $metadata = $this->detailMetadata('en', ['de' => 'fallback'], $detail);

        $this->assertCount(1, $metadata->alternates);
        $this->assertSame(['en' => 'http://localhost/about-us/source-news'], $metadata->alternates);
    }

    /** Requirement 59: metadata URLs carry no query parameters. */
    public function testTrackingParametersAreAbsentFromMetadataUrls(): void
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de', 'fallback');
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack(
            $registry,
            [],
            FakeDetailTargetResolver::none(),
            $page,
            'en',
            '/about-us',
            ['utm_source' => 'newsletter', 'ref' => 'mail'],
        );

        $metadata = $stack->metadataBuilder->build($stack->request, $page);

        foreach (array_merge([$metadata->canonicalUrl], array_values($metadata->alternates)) as $url) {
            $this->assertStringNotContainsString('?', (string) $url);
            $this->assertStringNotContainsString('utm_source', (string) $url);
        }
    }

    /** Requirement 56: an invalid language code is skipped instead of emitted. */
    public function testInvalidLanguageCodesAreOmitted(): void
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'not a language', 'fallback');
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack($registry, [], FakeDetailTargetResolver::none(), $page, 'en', '/about-us');

        $metadata = $stack->metadataBuilder->build($stack->request, $page);

        $this->assertSame(['en' => 'http://localhost/about-us'], $metadata->alternates);
    }

    /** Requirement 57: locale underscores are normalised. */
    public function testLocaleUnderscoresAreNormalised(): void
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de_ch', 'fallback');
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack($registry, [], FakeDetailTargetResolver::none(), $page, 'en', '/about-us');

        $metadata = $stack->metadataBuilder->build($stack->request, $page);

        $this->assertArrayHasKey('de-CH', $metadata->alternates);
        $this->assertSame('http://localhost/de_ch/about-us', $metadata->alternates['de-CH']);
    }

    /** Requirement 66: a configured root domain produces absolute URLs of that site. */
    public function testConfiguredRootDomainIsUsedForAbsoluteUrls(): void
    {
        $registry = (new FakeSiteLanguageRegistry())->add(2, 'de', 'default')->add(2, 'en', 'fallback');
        $page = $this->mockRegularPage(20, 2, 'ueber-uns', ['domain' => 'example.de', 'rootUseSSL' => '1']);
        $stack = new AvailabilityStack($registry, [], FakeDetailTargetResolver::none(), $page, 'de', '/ueber-uns');

        $metadata = $stack->metadataBuilder->build($stack->request, $page);

        $this->assertSame('https://example.de/ueber-uns', $metadata->canonicalUrl);
        $this->assertSame('https://example.de/en/ueber-uns', $metadata->alternates['en'] ?? null);
    }

    public function testAnUnavailableResourceStillKeepsOneCanonicalTag(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            [],
            ResourceAvailabilityReason::MissingDetailTranslation,
        );

        $metadata = $this->detailMetadata('en', ['de' => 'fallback'], $detail);

        $this->assertSame([], $metadata->alternates);
        $this->assertNull($metadata->canonicalUrl, 'Without a resolvable URL no canonical is invented.');
    }

    private function countHreflang(LanguageMetadata $metadata, string $code): int
    {
        $count = 0;

        foreach ($metadata->links() as $link) {
            if ($link['hreflang'] === $code) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $languages
     * @param array<string, object> $pageTranslations
     */
    private function pageMetadata(
        string $activeLanguage,
        array $languages,
        array $pageTranslations,
        string $path = '/about-us',
    ): LanguageMetadata {
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack(
            $this->registry($languages),
            $pageTranslations,
            FakeDetailTargetResolver::none(),
            $page,
            $activeLanguage,
            $path,
        );

        return $stack->metadataBuilder->build($stack->request, $page);
    }

    /**
     * @param array<string, string> $languages
     */
    private function detailMetadata(
        string $activeLanguage,
        array $languages,
        FakeDetailTargetResolver $detailResolver,
    ): LanguageMetadata {
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack(
            $this->registry($languages),
            [],
            $detailResolver,
            $page,
            $activeLanguage,
            '/about-us/source-news',
        );

        return $stack->metadataBuilder->build($stack->request, $page);
    }

    /**
     * @param array<string, string> $languages
     */
    private function registry(array $languages): FakeSiteLanguageRegistry
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default');

        foreach ($languages as $language => $mode) {
            $registry->add(1, (string) $language, $mode);
        }

        return $registry;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function pageTranslation(int $pageId, string $alias, array $overrides = []): FakeModel
    {
        return new FakeModel('tl_page_translation', array_merge([
            'id' => 900 + $pageId,
            'pid' => $pageId,
            'language' => 'de',
            'published' => '1',
            'start' => '',
            'stop' => '',
            'fieldStates' => json_encode(['alias' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR),
            'alias' => $alias,
        ], $overrides));
    }
}
