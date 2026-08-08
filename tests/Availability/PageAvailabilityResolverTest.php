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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Availability;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityReason;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityResolver;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PublicationChecker;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

class PageAvailabilityResolverTest extends TestCase
{
    use PageModelMockTrait;

    /** Requirement 9 */
    public function testAvailablePublishedTranslationIsTranslated(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us');
        $resolver = $this->resolver(['de' => 'strict'], [
            'tl_page_translation|10|de' => $this->translation(10, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]),
        ]);

        $result = $resolver->resolve($page, 'de');

        $this->assertTrue($result->isTranslated());
        $this->assertSame(PageAvailabilityReason::Available, $result->reason);
        $this->assertSame('ueber-uns', $result->effectiveAlias);
        $this->assertSame('about-us', $result->sourceAlias);
        $this->assertFalse($result->usesFallbackContent());
        $this->assertSame('/de/ueber-uns', $resolver->canonicalPath($result));
    }

    /** Requirement 10 */
    public function testMissingTranslationInStrictModeIsUnavailable(): void
    {
        $resolver = $this->resolver(['de' => 'strict'], []);

        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isUnavailable());
        $this->assertSame(PageAvailabilityReason::NoTranslation, $result->reason);
        $this->assertSame(PageAvailabilityMode::Strict, $result->mode);
        $this->assertNull($resolver->canonicalPath($result));
    }

    /** Requirement 11 */
    public function testMissingTranslationInFallbackModeUsesTheSourcePage(): void
    {
        $resolver = $this->resolver(['de' => 'fallback'], []);

        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isFallback());
        $this->assertTrue($result->usesFallbackContent());
        $this->assertSame('about-us', $result->effectiveAlias);
        $this->assertSame('/de/about-us', $resolver->canonicalPath($result));
    }

    /**
     * Requirements 12, 13, 14 and 15: an unavailable translation is unavailable
     * in strict mode and falls back in fallback mode.
     *
     * @dataProvider unavailableTranslations
     */
    public function testUnavailableTranslationsPerMode(array $translationRow, PageAvailabilityReason $expected): void
    {
        $translation = $this->translation(10, array_merge(['alias' => 'ueber-uns'], $translationRow), ['alias' => FieldStateMap::CUSTOM]);

        $strict = $this->resolver(['de' => 'strict'], ['tl_page_translation|10|de' => $translation]);
        $strictResult = $strict->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($strictResult->isUnavailable());
        $this->assertSame($expected, $strictResult->reason);

        $fallback = $this->resolver(['de' => 'fallback'], ['tl_page_translation|10|de' => $translation]);
        $fallbackResult = $fallback->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($fallbackResult->isFallback());
        $this->assertSame($expected, $fallbackResult->reason);
        $this->assertSame('/de/about-us', $fallback->canonicalPath($fallbackResult));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, PageAvailabilityReason}>
     */
    public static function unavailableTranslations(): iterable
    {
        yield 'unpublished' => [['published' => ''], PageAvailabilityReason::Unpublished];
        yield 'future start' => [['start' => (string) (time() + 86400)], PageAvailabilityReason::NotStarted];
        yield 'expired stop' => [['stop' => (string) (time() - 86400)], PageAvailabilityReason::Expired];
    }

    /** Requirement 16 */
    public function testOrphanedTranslationIsUnavailable(): void
    {
        // The record no longer belongs to the source page it is returned for.
        $orphan = $this->translation(999, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]);

        $resolver = $this->resolver(['de' => 'strict'], ['tl_page_translation|10|de' => $orphan]);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isUnavailable());
        $this->assertSame(PageAvailabilityReason::OrphanedRelation, $result->reason);
    }

    public function testTranslationOfAnotherLanguageIsUnavailable(): void
    {
        $wrongLanguage = $this->translation(10, ['language' => 'fr', 'alias' => 'a-propos'], ['alias' => FieldStateMap::CUSTOM]);

        $resolver = $this->resolver(['de' => 'strict'], ['tl_page_translation|10|de' => $wrongLanguage]);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isUnavailable());
        $this->assertSame(PageAvailabilityReason::WrongLanguage, $result->reason);
    }

    /** Requirement 17: a translation of another root site can never be used. */
    public function testTranslationFromAnotherRootSiteIsIgnored(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'strict')
            ->add(2, 'de', 'default')->add(2, 'en', 'fallback');

        // The translation belongs to page 20 of the second site.
        $resolver = $this->resolverWithRegistry($registry, [
            'tl_page_translation|20|de' => $this->translation(20, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]),
        ]);

        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isUnavailable());
        $this->assertSame(PageAvailabilityReason::NoTranslation, $result->reason);
    }

    /** Requirement 18 */
    public function testMissingFieldStateEntriesDoNotAffectAvailability(): void
    {
        $translation = new FakeModel('tl_page_translation', [
            'id' => 900,
            'pid' => 10,
            'language' => 'de',
            'published' => '1',
            'fieldStates' => '',
            'alias' => 'stale-copy',
        ]);

        $resolver = $this->resolver(['de' => 'strict'], ['tl_page_translation|10|de' => $translation]);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isTranslated());
        // No field state means "inherit", so the current source alias is used.
        $this->assertSame('about-us', $result->effectiveAlias);
        $this->assertSame('/de/about-us', $resolver->canonicalPath($result));
    }

    /** Requirement 19 */
    public function testDeliberatelyEmptyAliasPreventsTranslatedRouteAvailability(): void
    {
        $translation = $this->translation(10, ['alias' => ''], ['alias' => FieldStateMap::EMPTY]);

        $strict = $this->resolver(['de' => 'strict'], ['tl_page_translation|10|de' => $translation]);
        $strictResult = $strict->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($strictResult->isUnavailable());
        $this->assertSame(PageAvailabilityReason::InvalidAlias, $strictResult->reason);

        $fallback = $this->resolver(['de' => 'fallback'], ['tl_page_translation|10|de' => $translation]);
        $fallbackResult = $fallback->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($fallbackResult->isFallback());
        $this->assertSame('/de/about-us', $fallback->canonicalPath($fallbackResult));
    }

    /** Requirement 20 */
    public function testUnavailableSourcePagePreventsStrictAndFallbackOutput(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['published' => '']);

        foreach (['strict', 'fallback'] as $mode) {
            $resolver = $this->resolver(['de' => $mode], [
                'tl_page_translation|10|de' => $this->translation(10, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]),
            ]);

            $result = $resolver->resolve($page, 'de');

            $this->assertTrue($result->isUnavailable(), $mode);
            $this->assertSame(PageAvailabilityReason::SourcePageUnavailable, $result->reason, $mode);
        }
    }

    public function testExpiredSourcePageIsUnavailable(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['stop' => (string) (time() - 60)]);
        $resolver = $this->resolver(['de' => 'fallback'], []);

        $this->assertTrue($resolver->resolve($page, 'de')->isUnavailable());
    }

    /** Requirement 25: the default language stays unprefixed. */
    public function testDefaultLanguageUsesTheUnprefixedSourceAlias(): void
    {
        $resolver = $this->resolver(['de' => 'strict'], []);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'en');

        $this->assertTrue($result->isTranslated());
        $this->assertTrue($result->isDefaultLanguage);
        $this->assertSame('/about-us', $resolver->canonicalPath($result));
    }

    /** Requirement 31: root pages follow the same policy. */
    public function testRootPagesFollowTheSamePolicy(): void
    {
        $root = $this->mockRootPage(1, 'en');

        $fallback = $this->resolver(['de' => 'fallback'], []);
        $fallbackResult = $fallback->resolve($root, 'de');

        $this->assertTrue($fallbackResult->isFallback());
        $this->assertTrue($fallbackResult->isRootPage);
        $this->assertSame('/de/', $fallback->canonicalPath($fallbackResult));
        $this->assertSame('/', $fallback->canonicalPath($fallback->resolve($root, 'en')));

        $strict = $this->resolver(['de' => 'strict'], []);
        $this->assertTrue($strict->resolve($root, 'de')->isUnavailable());
    }

    public function testAvailableRootTranslationKeepsThePrefixedRootPath(): void
    {
        $root = $this->mockRootPage(1, 'en');
        $resolver = $this->resolver(['de' => 'strict'], [
            'tl_page_translation|1|de' => $this->translation(1, ['alias' => ''], ['alias' => FieldStateMap::EMPTY]),
        ]);

        $result = $resolver->resolve($root, 'de');

        $this->assertTrue($result->isTranslated(), 'A root page does not need an alias.');
        $this->assertSame('/de/', $resolver->canonicalPath($result));
    }

    public function testUnconfiguredLanguageIsUnavailable(): void
    {
        $resolver = $this->resolver(['de' => 'fallback'], []);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'fr');

        $this->assertTrue($result->isUnavailable());
        $this->assertSame(PageAvailabilityReason::LanguageNotConfigured, $result->reason);
    }

    public function testUrlSuffixIsAppliedToNonRootPages(): void
    {
        $page = $this->mockRegularPage(10, 1, 'about-us', ['urlSuffix' => '.html']);
        $resolver = $this->resolver(['de' => 'fallback'], []);
        $result = $resolver->resolve($page, 'de');

        $this->assertSame('/de/about-us.html', $resolver->canonicalPath($result));
        $this->assertSame('/de/about-us.php', $resolver->canonicalPath($result, '.php'));
    }

    /**
     * The obsolete fallback path of a translated page, used to redirect old
     * fallback URLs to the canonical translated URL. (Requirements 28 and 29)
     */
    public function testFallbackPathExposesTheObsoleteSourceAliasUrl(): void
    {
        $resolver = $this->resolver(['de' => 'fallback'], [
            'tl_page_translation|10|de' => $this->translation(10, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]),
        ]);
        $result = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertSame('/de/ueber-uns', $resolver->canonicalPath($result));
        $this->assertSame('/de/about-us', $resolver->fallbackPath($result));
        $this->assertNull($resolver->fallbackPath($resolver->resolve($this->mockRootPage(1, 'en'), 'de')));
    }

    /** Requirement 43: two sites may use different default languages. */
    public function testSitesMayUseDifferentDefaultLanguages(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback')
            ->add(2, 'de', 'default')->add(2, 'en', 'fallback');

        $resolver = $this->resolverWithRegistry($registry, []);

        $this->assertSame('/about-us', $resolver->canonicalPath($resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'en')));
        $this->assertSame('/de/about-us', $resolver->canonicalPath($resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de')));
        $this->assertSame('/ueber-uns', $resolver->canonicalPath($resolver->resolve($this->mockRegularPage(20, 2, 'ueber-uns'), 'de')));
        $this->assertSame('/en/ueber-uns', $resolver->canonicalPath($resolver->resolve($this->mockRegularPage(20, 2, 'ueber-uns'), 'en')));
    }

    /** Requirement 44: two sites may use different availability modes. */
    public function testSitesMayUseDifferentAvailabilityModes(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'strict')
            ->add(2, 'en', 'default')->add(2, 'de', 'fallback');

        $resolver = $this->resolverWithRegistry($registry, []);

        $this->assertTrue($resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de')->isUnavailable());
        $this->assertTrue($resolver->resolve($this->mockRegularPage(20, 2, 'about-us'), 'de')->isFallback());
    }

    /** Requirement 46: identical aliases in different sites stay isolated. */
    public function testIdenticalAliasesInDifferentSitesStayIsolated(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback')
            ->add(2, 'en', 'default')->add(2, 'de', 'fallback');

        $resolver = $this->resolverWithRegistry($registry, [
            'tl_page_translation|10|de' => $this->translation(10, ['alias' => 'ueber-uns'], ['alias' => FieldStateMap::CUSTOM]),
        ]);

        $first = $resolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');
        $second = $resolver->resolve($this->mockRegularPage(20, 2, 'about-us'), 'de');

        $this->assertSame('/de/ueber-uns', $resolver->canonicalPath($first));
        $this->assertSame('/de/about-us', $resolver->canonicalPath($second), 'The second site has no translation of its own.');
    }

    public function testAFailingTranslationLookupNeverBreaksTheFrontend(): void
    {
        $locator = new class() implements \Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface {
            public function find(string $translationTable, int $sourceId, string $language, ?int $parentId = null): ?object
            {
                throw new \RuntimeException('Database is gone');
            }
        };

        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de', 'fallback');
        $fallbackResolver = new PageAvailabilityResolver(
            $registry,
            $locator,
            new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap()),
            new CanonicalUrlPolicy(),
            new PublicationChecker(),
        );

        $result = $fallbackResolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de');

        $this->assertTrue($result->isFallback());
        $this->assertSame(PageAvailabilityReason::ResolutionFailed, $result->reason);

        $strictRegistry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default')->add(1, 'de', 'strict');
        $strictResolver = new PageAvailabilityResolver(
            $strictRegistry,
            $locator,
            new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap()),
            new CanonicalUrlPolicy(),
            new PublicationChecker(),
        );

        $this->assertTrue($strictResolver->resolve($this->mockRegularPage(10, 1, 'about-us'), 'de')->isUnavailable());
    }

    public function testPreservedSourceAliasWinsOverAnOverlaidAlias(): void
    {
        // The rendered page model already carries the translated alias.
        $page = $this->mockRegularPage(10, 1, 'ueber-uns', [
            PageAvailabilityResolver::SOURCE_ALIAS_PROPERTY => 'about-us',
        ]);

        $resolver = $this->resolver(['de' => 'fallback'], []);

        $this->assertSame('/about-us', $resolver->canonicalPath($resolver->resolve($page, 'en')));
        $this->assertSame('/de/about-us', $resolver->canonicalPath($resolver->resolve($page, 'de')));
    }

    /**
     * @param array<string, string> $languages
     * @param array<string, object> $translations
     */
    private function resolver(array $languages, array $translations): PageAvailabilityResolver
    {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default');

        foreach ($languages as $language => $mode) {
            $registry->add(1, (string) $language, $mode);
        }

        return $this->resolverWithRegistry($registry, $translations);
    }

    /**
     * @param array<string, object> $translations
     */
    private function resolverWithRegistry(FakeSiteLanguageRegistry $registry, array $translations): PageAvailabilityResolver
    {
        return new PageAvailabilityResolver(
            $registry,
            new FakeTranslationRecordLocator($translations),
            new TranslationOverlayResolver(new TranslationFieldRegistry(), new FieldStateMap()),
            new CanonicalUrlPolicy(),
            new PublicationChecker(),
        );
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $states
     */
    private function translation(int $pageId, array $values, array $states): FakeModel
    {
        return new FakeModel('tl_page_translation', array_merge([
            'id' => 900 + $pageId,
            'pid' => $pageId,
            'language' => 'de',
            'published' => '1',
            'start' => '',
            'stop' => '',
            'fieldStates' => json_encode($states, JSON_THROW_ON_ERROR),
        ], $values));
    }
}
