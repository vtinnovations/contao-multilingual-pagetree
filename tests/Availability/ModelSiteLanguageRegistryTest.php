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
use Vtinnovations\ContaoMultilingualPagetree\Availability\ModelSiteLanguageRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;

final class ModelSiteLanguageRegistryTest extends TestCase
{
    public function testNativeSourcePlusTwoRootScopedTargetsExposeThreeLanguages(): void
    {
        $registry = new InMemoryModelSiteLanguageRegistry([
            1 => [$this->language('de', true), $this->language('fr', true)],
            2 => [$this->language('it', true)],
        ], [1 => 'en', 2 => 'de']);

        self::assertSame('en', $registry->defaultLanguage(1));
        self::assertSame(['en', 'de', 'fr'], array_map(static fn ($language): string => $language->language, $registry->languages(1)));
        self::assertSame('gb', $registry->languages(1)[0]->flag);
        self::assertCount(3, $registry->languages(1));
        self::assertSame(['de', 'it'], array_map(static fn ($language): string => $language->language, $registry->languages(2)));
    }

    public function testSourceDuplicatesAndUnpublishedTargetsAreExcluded(): void
    {
        $registry = new InMemoryModelSiteLanguageRegistry([
            1 => [$this->language('en', true), $this->language('de', true), $this->language('fr', false)],
        ], [1 => 'en']);

        self::assertSame(['en', 'de'], array_map(static fn ($language): string => $language->language, $registry->languages(1)));
    }

    private function language(string $code, bool $published): object
    {
        return (object) [
            'language' => $code, 'label' => strtoupper($code), 'flag' => '', 'fallback' => false,
            'published' => $published, 'pageAvailabilityMode' => 'strict', 'contentTranslationMode' => 'connected',
        ];
    }
}

final class InMemoryModelSiteLanguageRegistry extends ModelSiteLanguageRegistry
{
    /** @param array<int, list<object>> $records
     *  @param array<int, string> $defaults
     */
    public function __construct(private array $records, private array $defaults)
    {
        parent::__construct(null, new CanonicalUrlPolicy());
    }

    protected function fetchLanguageRecords(int $rootPageId): iterable
    {
        return array_values(array_filter($this->records[$rootPageId] ?? [], static fn (object $record): bool => (bool) $record->published));
    }

    protected function fetchDefaultLanguage(int $rootPageId): ?string
    {
        return $this->defaults[$rootPageId] ?? null;
    }
}
