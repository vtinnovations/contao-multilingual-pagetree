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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Review;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Review\CanonicalValueNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceFingerprintCalculator;
use Vtinnovations\ContaoMultilingualPagetree\Review\SourceValuePreview;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryReviewStorage;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Requirement 78: showing status for many translations must not degrade into
 * one source query per row.
 */
class ReviewListPerformanceTest extends TestCase
{
    public function testStatusForAllTranslationsOfARecordUsesTwoReads(): void
    {
        $storage = new InMemoryReviewStorage();
        $storage->put('tl_page', ['id' => 10, 'title' => 'About us', 'alias' => 'about-us']);

        foreach (['de', 'fr', 'it', 'es', 'nl'] as $index => $language) {
            $storage->put('tl_page_translation', [
                'id' => 100 + $index,
                'pid' => 10,
                'language' => $language,
                TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value,
                TranslationReviewResolver::FIELD_REVISION => '',
            ]);
        }

        $resolver = $this->resolver();

        // The batch access pattern used by the language tabs and list views:
        // one query for all translations, one for the shared source record.
        $translations = $storage->findTranslationsOfSource('tl_page_translation', 10);
        $source = $storage->findSource('tl_page', 10);
        $statuses = [];

        foreach ($translations as $translation) {
            $statuses[] = $resolver->resolve('tl_page_translation', $translation, $source)->status;
        }

        $this->assertCount(5, $statuses);
        $this->assertSame(1, $storage->sourceReads, 'The source record is read once for all rows.');
        $this->assertSame(1, $storage->translationReads, 'All translations are read with one query.');
    }

    public function testResolvingNeverWritesAnything(): void
    {
        $storage = new InMemoryReviewStorage();
        $storage->put('tl_page', ['id' => 10, 'title' => 'About us']);
        $storage->put('tl_page_translation', [
            'id' => 5,
            'pid' => 10,
            'language' => 'de',
            TranslationReviewResolver::FIELD_STATUS => ReviewStatus::UpToDate->value,
            TranslationReviewResolver::FIELD_REVISION => str_repeat('a', 64),
        ]);

        $before = $storage->tables;
        $this->resolver()->resolve(
            'tl_page_translation',
            $storage->row('tl_page_translation', 5),
            $storage->row('tl_page', 10),
        );

        $this->assertSame($before, $storage->tables, 'A passive status calculation never persists anything.');
        $this->assertSame(0, $storage->statusRefreshes, 'And it never creates a version or status write.');
    }

    private function resolver(): TranslationReviewResolver
    {
        $registry = new TranslationFieldRegistry();

        return new TranslationReviewResolver(
            $registry,
            new SourceFingerprintCalculator($registry, new CanonicalValueNormalizer()),
            new SourceValuePreview(),
        );
    }
}
