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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Content;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Content\ConnectedToFreeImporter;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\ImportSummary;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeTranslationRecordLocator;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryFreeContentStorage;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\PackageFactory;

class ConnectedToFreeImporterTest extends TestCase
{
    /** Requirement 81: nothing happens without an explicit confirmation. */
    public function testTheImportRequiresExplicitConfirmation(): void
    {
        $storage = $this->storage();
        $summary = $this->importer($storage)->import(10, 'de', 1, false);

        $this->assertSame(ImportSummary::STATUS_UNCONFIRMED, $summary->status);
        $this->assertSame([], $storage->freeRecords('tl_article', 'de'));
        $this->assertFalse($storage->committed);
    }

    /**
     * Requirements 82, 83, 84 and 85: new ids, preserved order and nesting.
     */
    public function testTheImportCreatesNewRecordsPreservingOrderAndNesting(): void
    {
        $storage = $this->storage();
        $summary = $this->importer($storage)->import(10, 'de', 1, true);

        $this->assertTrue($summary->isSuccessful());
        $this->assertSame(2, $summary->articles);
        $this->assertSame(3, $summary->contentElements);

        $articles = $storage->freeRecords('tl_article', 'de');
        $this->assertCount(2, $articles);

        foreach ($articles as $article) {
            $this->assertGreaterThanOrEqual(1000, (int) $article['id'], 'Imported records receive new ids.');
            $this->assertSame(1, (int) $article[ContentOwnership::FIELD_ROOT]);
            $this->assertSame(10, (int) $article['pid'], 'The free article belongs to the same source page.');
        }

        $this->assertSame([1, 2], array_map(static fn (array $a): int => (int) $a['sorting'], $articles));

        // The nested element points at the new parent id, not at the source one.
        $elements = $storage->freeRecords('tl_content', 'de');
        $this->assertCount(3, $elements);

        $parents = array_column($elements, 'pid');
        $nested = array_values(array_filter($elements, static fn (array $e): bool => 'tl_content' === ($e['ptable'] ?? '')));
        $this->assertCount(1, $nested);
        $this->assertGreaterThanOrEqual(1000, (int) $nested[0]['pid']);
        $this->assertContains((int) $nested[0]['pid'], array_map('intval', $parents));
    }

    /**
     * Requirements 86, 87 and 88: inherited, custom and empty values resolve
     * through the point 2 field states.
     */
    public function testTranslatedValuesAreResolvedThroughFieldStates(): void
    {
        $storage = $this->storage();
        $this->importer($storage)->import(10, 'de', 1, true);

        $elements = $storage->freeRecords('tl_content', 'de');
        $byText = [];

        foreach ($elements as $element) {
            $byText[(int) $element['sorting']] = $element;
        }

        $this->assertSame('Übersetzter Text', $byText[1]['text'], 'A custom value is used.');
        $this->assertSame('Second source text', $byText[2]['text'], 'An inherited value follows the source.');
        $this->assertSame('', $byText[3]['text'], 'A deliberately empty value stays empty.');
    }

    /** Requirement 89: no field-state or review metadata is copied. */
    public function testConnectedMetadataIsNeverCopiedIntoFreeRecords(): void
    {
        $storage = $this->storage();
        $this->importer($storage)->import(10, 'de', 1, true);

        foreach ([...$storage->freeRecords('tl_article', 'de'), ...$storage->freeRecords('tl_content', 'de')] as $record) {
            $this->assertArrayNotHasKey('fieldStates', $record);
            $this->assertArrayNotHasKey('reviewStatus', $record);
            $this->assertArrayNotHasKey('reviewedSourceRevision', $record);
        }
    }

    /** Requirements 90 and 91: source records and connected translations survive. */
    public function testSourceRecordsAndConnectedTranslationsAreUntouched(): void
    {
        $storage = $this->storage();
        $sourceArticlesBefore = $storage->findSourceArticles(10);
        $sourceContentBefore = $storage->findChildContent('tl_article', 1);

        $this->importer($storage)->import(10, 'de', 1, true);

        $this->assertEquals($sourceArticlesBefore, $storage->findSourceArticles(10));
        $this->assertEquals($sourceContentBefore, $storage->findChildContent('tl_article', 1));
    }

    /** Requirement 92: a repeated import is prevented instead of duplicating. */
    public function testARepeatedImportIsPrevented(): void
    {
        $storage = $this->storage();
        $importer = $this->importer($storage);

        $this->assertTrue($importer->import(10, 'de', 1, true)->isSuccessful());
        $second = $importer->import(10, 'de', 1, true);

        $this->assertSame(ImportSummary::STATUS_ALREADY_IMPORTED, $second->status);
        $this->assertCount(2, $storage->freeRecords('tl_article', 'de'));
    }

    /** Requirement 93: a partial failure rolls back. */
    public function testAPartialFailureRollsBack(): void
    {
        $storage = $this->storage();
        $storage->failInsert = true;

        $summary = $this->importer($storage)->import(10, 'de', 1, true);

        $this->assertSame(ImportSummary::STATUS_FAILED, $summary->status);
        $this->assertTrue($storage->rolledBack);
        $this->assertFalse($storage->committed);
    }

    public function testTheDryRunReportsWithoutWriting(): void
    {
        $storage = $this->storage();
        $summary = $this->importer($storage)->dryRun(10, 'de', 1);

        $this->assertSame(ImportSummary::STATUS_PLANNED, $summary->status);
        $this->assertSame(2, $summary->articles);
        $this->assertSame(3, $summary->contentElements);
        $this->assertSame([], $storage->freeRecords('tl_article', 'de'));
    }

    private function importer(InMemoryFreeContentStorage $storage): ConnectedToFreeImporter
    {
        $registry = new TranslationFieldRegistry();

        return new ConnectedToFreeImporter(
            $storage,
            new FakeTranslationRecordLocator([
                'tl_article_translation|1|de' => new FakeModel('tl_article_translation', [
                    'id' => 500, 'pid' => 1, 'language' => 'de', 'title' => 'Übersetzter Artikel',
                    'fieldStates' => json_encode(['title' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR),
                ]),
                'tl_content_translation|11|de' => new FakeModel('tl_content_translation', [
                    'id' => 511, 'pid' => 11, 'language' => 'de', 'text' => 'Übersetzter Text',
                    'fieldStates' => json_encode(['text' => FieldStateMap::CUSTOM], JSON_THROW_ON_ERROR),
                ]),
                'tl_content_translation|13|de' => new FakeModel('tl_content_translation', [
                    'id' => 513, 'pid' => 13, 'language' => 'de', 'text' => 'ignored',
                    'fieldStates' => json_encode(['text' => FieldStateMap::EMPTY], JSON_THROW_ON_ERROR),
                ]),
            ]),
            new TranslationOverlayResolver($registry, new FieldStateMap()),
            PackageFactory::grantingPolicy(),
        );
    }

    private function storage(): InMemoryFreeContentStorage
    {
        return (new InMemoryFreeContentStorage())
            ->put('tl_article', ['id' => 1, 'pid' => 10, 'sorting' => 1, 'inColumn' => 'main', 'title' => 'First', 'published' => '1'])
            ->put('tl_article', ['id' => 2, 'pid' => 10, 'sorting' => 2, 'inColumn' => 'main', 'title' => 'Second', 'published' => '1'])
            ->put('tl_content', ['id' => 11, 'pid' => 1, 'ptable' => 'tl_article', 'sorting' => 1, 'type' => 'text', 'text' => 'Source text'])
            ->put('tl_content', ['id' => 12, 'pid' => 1, 'ptable' => 'tl_article', 'sorting' => 2, 'type' => 'text', 'text' => 'Second source text'])
            ->put('tl_content', ['id' => 13, 'pid' => 11, 'ptable' => 'tl_content', 'sorting' => 3, 'type' => 'text', 'text' => 'Third source text']);
    }
}
