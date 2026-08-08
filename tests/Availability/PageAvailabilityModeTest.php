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
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Routing\CanonicalUrlPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;

class PageAvailabilityModeTest extends TestCase
{
    /** Requirement 1 */
    public function testStrictIsAccepted(): void
    {
        $this->assertSame(PageAvailabilityMode::Strict, PageAvailabilityMode::fromValue('strict'));
        $this->assertTrue(PageAvailabilityMode::fromValue('strict')->isStrict());
    }

    /** Requirement 2 */
    public function testFallbackIsAccepted(): void
    {
        $this->assertSame(PageAvailabilityMode::Fallback, PageAvailabilityMode::fromValue('fallback'));
        $this->assertFalse(PageAvailabilityMode::fromValue('fallback')->isStrict());
    }

    /**
     * Requirement 3
     *
     * @dataProvider invalidValues
     */
    public function testInvalidValuesNormaliseToFallback(mixed $value): void
    {
        $this->assertSame(PageAvailabilityMode::Fallback, PageAvailabilityMode::fromValue($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown string' => ['nonsense'];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'array' => [['strict']];
    }

    /** Requirement 4 */
    public function testMissingValueNormalisesToFallback(): void
    {
        $record = new FakeModel('tl_inline_language', ['language' => 'de', 'fallback' => false]);

        $this->assertSame(PageAvailabilityMode::Fallback, PageAvailabilityMode::fromValue($record->pageAvailabilityMode));
    }

    public function testValuesAreCaseAndWhitespaceInsensitive(): void
    {
        $this->assertSame(PageAvailabilityMode::Strict, PageAvailabilityMode::fromValue(' STRICT '));
    }

    /** Requirement 5: the default language never carries a meaningful mode. */
    public function testDefaultLanguageIgnoresTheConfiguredMode(): void
    {
        $registry = $this->registry([
            ['language' => 'en', 'label' => 'English', 'flag' => 'gb', 'fallback' => true, 'pageAvailabilityMode' => 'strict'],
            ['language' => 'de', 'label' => 'Deutsch', 'flag' => 'de', 'fallback' => false, 'pageAvailabilityMode' => 'strict'],
        ], 'en');

        $this->assertSame(PageAvailabilityMode::Fallback, $registry->mode(1, 'en'));
        $this->assertSame(PageAvailabilityMode::Strict, $registry->mode(1, 'de'));

        $languages = $registry->languages(1);
        $this->assertCount(2, $languages);
        $this->assertTrue($languages[0]->isDefault);
        $this->assertSame(PageAvailabilityMode::Fallback, $languages[0]->mode, 'A default language is always fallback.');
        $this->assertSame('strict', $languages[1]->toArray()['mode']);
    }

    public function testUnknownLanguageResolvesToFallback(): void
    {
        $registry = $this->registry([
            ['language' => 'en', 'fallback' => true],
        ], 'en');

        $this->assertSame(PageAvailabilityMode::Fallback, $registry->mode(1, 'fr'));
        $this->assertFalse($registry->isEnabled(1, 'fr'));
    }

    public function testInvalidLanguageCodesAreIgnored(): void
    {
        $registry = $this->registry([
            ['language' => 'en', 'fallback' => true],
            ['language' => 'not-a-language', 'fallback' => false],
        ], 'en');

        $this->assertCount(1, $registry->languages(1));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function registry(array $records, string $defaultLanguage): ModelSiteLanguageRegistry
    {
        $models = array_map(static fn (array $row): FakeModel => new FakeModel('tl_inline_language', $row), $records);

        return new class($models, $defaultLanguage) extends ModelSiteLanguageRegistry {
            /**
             * @param list<FakeModel> $models
             */
            public function __construct(private readonly array $models, private readonly string $default)
            {
                // No framework is needed: both model lookups are overridden below.
                parent::__construct(null, new CanonicalUrlPolicy());
            }

            protected function fetchLanguageRecords(int $rootPageId): iterable
            {
                return $this->models;
            }

            protected function fetchDefaultLanguage(int $rootPageId): ?string
            {
                return $this->default;
            }
        };
    }
}
