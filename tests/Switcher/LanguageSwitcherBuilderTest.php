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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Switcher;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityReason;
use Vtinnovations\ContaoMultilingualPagetree\Availability\ResourceAvailabilityStatus;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailContext;
use Vtinnovations\ContaoMultilingualPagetree\Detail\DetailTargetResult;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\SwitcherEntry;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\UnavailableLanguageDisplay;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\AvailabilityStack;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeDetailTargetResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PageModelMockTrait;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

class LanguageSwitcherBuilderTest extends TestCase
{
    use PageModelMockTrait;

    /** Requirements 1, 2 and 6 */
    public function testCurrentLanguageIsActiveAndTargetLanguageIsAvailable(): void
    {
        $entries = $this->pageEntries('de', ['de' => 'fallback'], [], '/de/about-us');

        $this->assertSame(['en', 'de'], array_map(static fn (SwitcherEntry $e): string => $e->language, $entries));
        $this->assertTrue($this->entry($entries, 'de')->isActive());
        $this->assertTrue($this->entry($entries, 'en')->isAvailable());
        $this->assertSame(ResourceAvailabilityStatus::Active, $this->entry($entries, 'de')->status);
    }

    /** Requirements 3 and 9: strict mode without a translation is unavailable. */
    public function testStrictModeMissingTranslationIsUnavailable(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'strict'], [], '/about-us', UnavailableLanguageDisplay::Disabled);

        $this->assertTrue($this->entry($entries, 'de')->isUnavailable());
        $this->assertNull($this->entry($entries, 'de')->toArray()['href']);
    }

    /**
     * Requirements 10, 11 and 12: unpublished, scheduled and expired
     * translations are unavailable in strict mode.
     *
     * @dataProvider unavailableTranslations
     */
    public function testStrictModeRejectsUnavailableTranslations(array $overrides): void
    {
        $translation = $this->pageTranslation(10, 'ueber-uns', $overrides);
        $entries = $this->pageEntries(
            'en',
            ['de' => 'strict'],
            ['tl_page_translation|10|de' => $translation],
            '/about-us',
            UnavailableLanguageDisplay::Disabled,
        );

        $this->assertTrue($this->entry($entries, 'de')->isUnavailable());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unavailableTranslations(): iterable
    {
        yield 'unpublished' => [['published' => '']];
        yield 'future start' => [['start' => (string) (time() + 86400)]];
        yield 'expired stop' => [['stop' => (string) (time() - 86400)]];
    }

    /** Requirement 13 */
    public function testFallbackModeMissingTranslationIsAvailableThroughTheFallbackUrl(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'fallback'], [], '/about-us');
        $german = $this->entry($entries, 'de');

        $this->assertTrue($german->isAvailable());
        $this->assertSame('/de/about-us', $german->href);
        $this->assertTrue($german->usesFallback, 'The entry exposes internally that it uses fallback content.');
    }

    /** Requirements 14, 15, 16 and 17 */
    public function testCanonicalAliasesAndPrefixes(): void
    {
        $entries = $this->pageEntries(
            'en',
            ['de' => 'fallback'],
            ['tl_page_translation|10|de' => $this->pageTranslation(10, 'ueber-uns')],
            '/about-us',
        );

        $this->assertSame('/about-us', $this->entry($entries, 'en')->href, 'The default language stays unprefixed.');
        $this->assertSame('/de/ueber-uns', $this->entry($entries, 'de')->href, 'A translated alias is canonical.');
        $this->assertNotSame('/de/about-us', $this->entry($entries, 'de')->href, 'The redirect-only fallback alias is never linked.');
        $this->assertFalse($this->entry($entries, 'de')->usesFallback);
    }

    /** Requirements 7 and 8: the state is URL driven only. */
    public function testBrowserAndSessionPreferencesDoNotChangeTheState(): void
    {
        $stack = $this->stack('de', ['de' => 'fallback'], [], FakeDetailTargetResolver::none(), '/de/about-us');
        $stack->request->headers->set('Accept-Language', 'en-GB,en;q=0.9');
        $stack->request->cookies->set('language', 'en');

        $entries = $stack->switcherBuilder->build(
            $stack->request,
            $stack->request->attributes->get('pageModel'),
            UnavailableLanguageDisplay::Hide,
        );

        $this->assertTrue($this->entry($entries, 'de')->isActive());
        $this->assertTrue($this->entry($entries, 'en')->isAvailable());
    }

    /** Requirements 18, 22 and 23: an available translated detail record. */
    public function testAvailableTranslatedDetailRecordAppears(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            ['de' => DetailTargetResult::available('/de/ueber-uns/uebersetzte-news', '/de/ueber-uns/uebersetzte-news', 'uebersetzte-news')],
        );

        $entries = $this->detailEntries('en', ['de' => 'fallback'], $detail);

        $this->assertTrue($this->entry($entries, 'de')->isAvailable());
        $this->assertSame('/de/ueber-uns/uebersetzte-news', $this->entry($entries, 'de')->href);
    }

    /**
     * Requirements 19, 20, 21, 26, 27, 31 and 32: a fallback reader page never
     * makes a missing or unpublished detail translation available.
     *
     * @dataProvider unavailableDetailReasons
     */
    public function testUnavailableDetailTranslationIsNotLinked(ResourceAvailabilityReason $reason, string $type): void
    {
        $detail = new FakeDetailTargetResolver(new DetailContext($type, 7, 'source-record'), [], $reason);
        $entries = $this->detailEntries('en', ['de' => 'fallback'], $detail, UnavailableLanguageDisplay::Disabled);
        $german = $this->entry($entries, 'de');

        $this->assertTrue($german->isUnavailable());
        $this->assertNull($german->toArray()['href'], 'No fallback reader-page or list URL may be offered.');
        $this->assertSame($reason->value, $german->reason);
    }

    /**
     * @return iterable<string, array{ResourceAvailabilityReason, string}>
     */
    public static function unavailableDetailReasons(): iterable
    {
        yield 'missing news' => [ResourceAvailabilityReason::MissingDetailTranslation, DetailContext::NEWS];
        yield 'unpublished news' => [ResourceAvailabilityReason::UnpublishedDetailTranslation, DetailContext::NEWS];
        yield 'missing event' => [ResourceAvailabilityReason::MissingDetailTranslation, DetailContext::EVENT];
        yield 'unpublished event' => [ResourceAvailabilityReason::UnpublishedDetailTranslation, DetailContext::EVENT];
        yield 'missing faq' => [ResourceAvailabilityReason::MissingDetailTranslation, DetailContext::FAQ];
        yield 'unpublished faq' => [ResourceAvailabilityReason::UnpublishedDetailTranslation, DetailContext::FAQ];
        yield 'invalid alias' => [ResourceAvailabilityReason::InvalidDetailAlias, DetailContext::FAQ];
    }

    /** Requirement 29: an unrepresentable occurrence makes the target unavailable. */
    public function testUnrepresentableOccurrenceParametersMakeTheTargetUnavailable(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::EVENT, 7, 'source-event', ['2024-05-01']),
            [],
            ResourceAvailabilityReason::UnrepresentableParameters,
        );

        $entries = $this->detailEntries('en', ['de' => 'fallback'], $detail, UnavailableLanguageDisplay::Disabled);

        $this->assertTrue($this->entry($entries, 'de')->isUnavailable());
    }

    /** Requirement 28: occurrence parameters are kept in the target URL. */
    public function testOccurrenceParametersAreRetained(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::EVENT, 7, 'source-event', ['2024-05-01']),
            ['de' => DetailTargetResult::available('/de/termine/event/2024-05-01', '/de/termine/event/2024-05-01', 'event')],
        );

        $entries = $this->detailEntries('en', ['de' => 'fallback'], $detail);

        $this->assertStringEndsWith('/2024-05-01', (string) $this->entry($entries, 'de')->href);
    }

    /** Requirement 24: switching back to the default language uses the source detail URL. */
    public function testSwitchingBackToTheDefaultLanguageUsesTheSourceDetailUrl(): void
    {
        $detail = new FakeDetailTargetResolver(
            new DetailContext(DetailContext::NEWS, 7, 'source-news'),
            ['en' => DetailTargetResult::available('/about-us/source-news', '/about-us/source-news', 'source-news')],
        );

        $entries = $this->detailEntries('de', ['de' => 'fallback'], $detail, UnavailableLanguageDisplay::Disabled);

        $this->assertSame('/about-us/source-news', $this->entry($entries, 'en')->href);
        $this->assertTrue($this->entry($entries, 'de')->isActive());
    }

    /** Requirement 34 */
    public function testHideModeOmitsUnavailableEntries(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'strict'], [], '/about-us', UnavailableLanguageDisplay::Hide);

        $this->assertSame(['en'], array_map(static fn (SwitcherEntry $e): string => $e->language, $entries));
    }

    /** Requirements 35 and 36 */
    public function testDisabledModeRendersNoHrefAndMarksTheEntryDisabled(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'strict'], [], '/about-us', UnavailableLanguageDisplay::Disabled);
        $german = $this->entry($entries, 'de')->toArray();

        $this->assertSame('unavailable', $german['status']);
        $this->assertNull($german['href']);
        $this->assertTrue($german['unavailable']);
        $this->assertFalse($german['active']);
        $this->assertFalse($german['available']);
    }

    public function testHideActiveStillRemovesTheActiveEntry(): void
    {
        $entries = $this->pageEntries('de', ['de' => 'fallback'], [], '/de/about-us', UnavailableLanguageDisplay::Hide, true);

        $this->assertSame(['en'], array_map(static fn (SwitcherEntry $e): string => $e->language, $entries));
    }

    /** Requirement 38: every entry keeps a label for flag-only rendering. */
    public function testEveryEntryCarriesALabelAndHreflangCode(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'fallback'], [], '/about-us');

        foreach ($entries as $entry) {
            $this->assertNotSame('', $entry->label);
            $this->assertNotSame('', $entry->hreflang);
        }
    }

    /** Requirements 63, 64, 65 and 67: sites stay isolated. */
    public function testOtherRootSitesAreExcluded(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback')
            ->add(2, 'fr', 'default')->add(2, 'it', 'strict');

        $page = $this->mockRegularPage(10, 1, 'about-us');
        $stack = new AvailabilityStack($registry, [], FakeDetailTargetResolver::none(), $page, 'en', '/about-us');
        $entries = $stack->switcherBuilder->build($stack->request, $page, UnavailableLanguageDisplay::Disabled);

        $this->assertSame(['en', 'de'], array_map(static fn (SwitcherEntry $e): string => $e->language, $entries));
    }

    public function testASecondSiteUsesItsOwnDefaultLanguage(): void
    {
        $registry = (new FakeSiteLanguageRegistry())
            ->add(1, 'en', 'default')->add(1, 'de', 'fallback')
            ->add(2, 'de', 'default')->add(2, 'en', 'fallback');

        $page = $this->mockRegularPage(20, 2, 'ueber-uns');
        $stack = new AvailabilityStack($registry, [], FakeDetailTargetResolver::none(), $page, 'de', '/ueber-uns');
        $entries = $stack->switcherBuilder->build($stack->request, $page, UnavailableLanguageDisplay::Disabled);

        $this->assertSame('/ueber-uns', $this->entry($entries, 'de')->href, 'The site default language is unprefixed.');
        $this->assertSame('/en/ueber-uns', $this->entry($entries, 'en')->href);
    }

    /** Requirement 5: an unexpected availability state never produces a link. */
    public function testUnknownLanguageProducesNoEntry(): void
    {
        $entries = $this->pageEntries('en', ['de' => 'fallback'], [], '/about-us', UnavailableLanguageDisplay::Disabled);

        $this->assertNull($this->findEntry($entries, 'fr'));
    }

    /**
     * @param array<string, string> $languages
     * @param array<string, object> $pageTranslations
     *
     * @return list<SwitcherEntry>
     */
    private function pageEntries(
        string $activeLanguage,
        array $languages,
        array $pageTranslations,
        string $path,
        UnavailableLanguageDisplay $display = UnavailableLanguageDisplay::Hide,
        bool $hideActive = false,
    ): array {
        $stack = $this->stack($activeLanguage, $languages, $pageTranslations, FakeDetailTargetResolver::none(), $path);

        return $stack->switcherBuilder->build(
            $stack->request,
            $stack->request->attributes->get('pageModel'),
            $display,
            $hideActive,
        );
    }

    /**
     * @param array<string, string> $languages
     *
     * @return list<SwitcherEntry>
     */
    private function detailEntries(
        string $activeLanguage,
        array $languages,
        FakeDetailTargetResolver $detailResolver,
        UnavailableLanguageDisplay $display = UnavailableLanguageDisplay::Hide,
    ): array {
        $stack = $this->stack($activeLanguage, $languages, [], $detailResolver, '/about-us/source-news');

        return $stack->switcherBuilder->build(
            $stack->request,
            $stack->request->attributes->get('pageModel'),
            $display,
        );
    }

    /**
     * @param array<string, string> $languages
     * @param array<string, object> $pageTranslations
     */
    private function stack(
        string $activeLanguage,
        array $languages,
        array $pageTranslations,
        FakeDetailTargetResolver $detailResolver,
        string $path,
    ): AvailabilityStack {
        $registry = (new FakeSiteLanguageRegistry())->add(1, 'en', 'default');

        foreach ($languages as $language => $mode) {
            $registry->add(1, (string) $language, $mode);
        }

        return new AvailabilityStack(
            $registry,
            $pageTranslations,
            $detailResolver,
            $this->mockRegularPage(10, 1, 'about-us'),
            $activeLanguage,
            $path,
        );
    }

    /**
     * @param list<SwitcherEntry> $entries
     */
    private function entry(array $entries, string $language): SwitcherEntry
    {
        $entry = $this->findEntry($entries, $language);

        $this->assertNotNull($entry, sprintf('No switcher entry for "%s".', $language));

        return $entry;
    }

    /**
     * @param list<SwitcherEntry> $entries
     */
    private function findEntry(array $entries, string $language): ?SwitcherEntry
    {
        foreach ($entries as $entry) {
            if ($entry->language === $language) {
                return $entry;
            }
        }

        return null;
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
