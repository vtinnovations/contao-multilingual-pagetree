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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationRepository;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\CapturingLogger;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

/**
 * The content translation store, exercised against a real database.
 *
 * These run against an in-memory connection rather than a mock on purpose: the
 * defect they cover was a column that did not exist, and no mock of the
 * repository's own collaborator can reproduce that. The table below is built
 * from the columns the shipped definition actually declares.
 *
 * The regression: every first save failed because the insert seeded a
 * `reviewStatus` column. Content translations carry no review state - that
 * workflow lives on page, article, news, event and FAQ translations - so the
 * column is not in this table, and one unknown column failed the whole
 * statement. The form rendered, the prefill worked and the source stayed safe,
 * which is exactly why it looked like everything except persistence worked.
 */
final class ContentTranslationPersistenceTest extends TestCase
{
    /**
     * The columns the shipped storage definition declares: the identity columns
     * from the DCA plus the translatable columns the canonical policy persists.
     * Deliberately without `reviewStatus`.
     */
    private const COLUMNS = [
        'pid' => 'INTEGER NOT NULL DEFAULT 0',
        'tstamp' => 'INTEGER NOT NULL DEFAULT 0',
        'language' => 'TEXT NOT NULL DEFAULT \'\'',
        'fieldStates' => 'TEXT NULL',
        'headline' => 'TEXT NULL',
        'text' => 'TEXT NULL',
        'type' => 'TEXT NULL',
        'alt' => 'TEXT NULL',
        'caption' => 'TEXT NULL',
    ];

    private Connection $connection;
    private CapturingLogger $logger;
    private ContentTranslationRepository $repository;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createStore();

        $this->logger = new CapturingLogger();
        $this->repository = new ContentTranslationRepository($this->connection, new FieldStateMap(), $this->logger);
    }

    /**
     * @param array<string, string> $extraColumns
     */
    private function createStore(array $extraColumns = []): void
    {
        $columns = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];

        foreach (self::COLUMNS + $extraColumns as $name => $type) {
            $columns[] = '"'.$name.'" '.$type;
        }

        $this->connection->executeStatement(
            'CREATE TABLE '.ContentTranslationRepository::TABLE.' ('.implode(', ', $columns).')',
        );
        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX pid_language ON '.ContentTranslationRepository::TABLE.' (pid, language)',
        );
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.ContentTranslationRepository::TABLE);
    }

    // ---------------------------------------------------------------- the defect

    /** The regression: the very first save of a language has to insert. */
    public function testTheFirstTranslationSaveInsertsARow(): void
    {
        $saved = $this->repository->save(12, 'en', ['headline' => 'About us', 'text' => '<p>Hello</p>'], [
            'headline' => FieldStateMap::CUSTOM,
            'text' => FieldStateMap::CUSTOM,
        ]);

        self::assertTrue($saved, 'The first save must persist: '.$this->logger->flatten());
        self::assertSame(1, $this->rowCount());

        $stored = $this->repository->find(12, 'en');
        self::assertNotNull($stored);
        self::assertSame('About us', $stored['headline']);
        self::assertSame('<p>Hello</p>', $stored['text']);
    }

    /** Nothing at all is logged when the save succeeds. */
    public function testASuccessfulSaveLogsNothing(): void
    {
        $this->repository->save(12, 'en', ['headline' => 'About us'], ['headline' => FieldStateMap::CUSTOM]);

        self::assertSame([], $this->logger->records);
    }

    // ---------------------------------------------------------------- insert/update

    public function testASecondSaveUpdatesTheSameRow(): void
    {
        $this->repository->save(12, 'en', ['headline' => 'First'], ['headline' => FieldStateMap::CUSTOM]);
        $this->repository->save(12, 'en', ['headline' => 'Second'], ['headline' => FieldStateMap::CUSTOM]);

        self::assertSame(1, $this->rowCount(), 'A second save must update, never insert a duplicate.');
        self::assertSame('Second', $this->repository->find(12, 'en')['headline']);
    }

    /** What was written under a key must come back under the same key. */
    public function testLoadAndSaveUseTheSameStorageKey(): void
    {
        $this->repository->save(12, 'en', ['headline' => 'Bound to 12/en'], ['headline' => FieldStateMap::CUSTOM]);

        self::assertNotNull($this->repository->find(12, 'en'));
        self::assertNull($this->repository->find(12, 'ru'), 'Another language is another row.');
        self::assertNull($this->repository->find(13, 'en'), 'Another source element is another row.');
    }

    /** Two languages of one element never share a row or a value. */
    public function testLanguagesRemainIndependent(): void
    {
        $this->repository->save(12, 'en', ['headline' => 'About us', 'text' => '<p>English</p>'], [
            'headline' => FieldStateMap::CUSTOM,
            'text' => FieldStateMap::CUSTOM,
        ]);
        $this->repository->save(12, 'ru', ['headline' => 'О нас', 'text' => '<p>Русский</p>'], [
            'headline' => FieldStateMap::CUSTOM,
            'text' => FieldStateMap::CUSTOM,
        ]);

        self::assertSame(2, $this->rowCount());
        self::assertSame('About us', $this->repository->find(12, 'en')['headline']);
        self::assertSame('О нас', $this->repository->find(12, 'ru')['headline']);
        self::assertSame('<p>English</p>', $this->repository->find(12, 'en')['text']);

        // Updating one language leaves the other exactly as it was.
        $this->repository->save(12, 'en', ['headline' => 'Changed'], ['headline' => FieldStateMap::CUSTOM]);

        self::assertSame('О нас', $this->repository->find(12, 'ru')['headline']);
    }

    /** RTE markup survives the round trip byte for byte. */
    public function testRichTextSurvivesTheRoundTrip(): void
    {
        $rte = '<p>Ünïcode &amp; <strong>bold</strong></p><p><a href="https://example.com">link</a></p>';

        $this->repository->save(12, 'en', ['text' => $rte], ['text' => FieldStateMap::CUSTOM]);

        self::assertSame($rte, $this->repository->find(12, 'en')['text']);
    }

    /** A deliberate blank is stored as a blank, not as a missing translation. */
    public function testADeliberateBlankIsStored(): void
    {
        $this->repository->save(12, 'en', ['headline' => ''], ['headline' => FieldStateMap::EMPTY]);

        $stored = $this->repository->find(12, 'en');
        self::assertSame('', $stored['headline']);
        self::assertSame(FieldStateMap::EMPTY, $this->repository->states(12, 'en')['headline'] ?? null);
    }

    public function testProvenanceIsStoredAlongsideTheValues(): void
    {
        $this->repository->save(12, 'en', ['headline' => 'About us'], [
            'headline' => FieldStateMap::CUSTOM,
            'text' => FieldStateMap::INHERIT,
        ]);

        $states = $this->repository->states(12, 'en');

        self::assertSame(FieldStateMap::CUSTOM, $states['headline']);
        self::assertSame(FieldStateMap::INHERIT, $states['text']);
    }

    // ---------------------------------------------------------------- schema tolerance

    /**
     * The fix itself: a column this installation's schema does not carry is
     * dropped, and every other translated value of that save still lands.
     */
    public function testAColumnTheStoreDoesNotHaveDoesNotFailTheWholeWrite(): void
    {
        $saved = $this->repository->save(
            12,
            'en',
            ['headline' => 'About us', 'text' => '<p>Hello</p>', 'reviewStatus' => 'unreviewed'],
            ['headline' => FieldStateMap::CUSTOM],
        );

        self::assertTrue($saved, 'One absent column must not take the whole save with it.');
        self::assertSame('About us', $this->repository->find(12, 'en')['headline']);
        self::assertSame('<p>Hello</p>', $this->repository->find(12, 'en')['text']);
    }

    /** An installation that still carries the legacy column keeps it seeded. */
    public function testALegacySchemaStillReceivesItsReviewColumn(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $columns = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];

        foreach (self::COLUMNS + ['reviewStatus' => 'TEXT NULL'] as $name => $type) {
            $columns[] = '"'.$name.'" '.$type;
        }

        $connection->executeStatement(
            'CREATE TABLE '.ContentTranslationRepository::TABLE.' ('.implode(', ', $columns).')',
        );

        $repository = new ContentTranslationRepository($connection, new FieldStateMap(), $this->logger);

        self::assertTrue($repository->save(12, 'en', ['headline' => 'About us'], ['headline' => FieldStateMap::CUSTOM]));
        self::assertSame(
            'unreviewed',
            $connection->fetchOne('SELECT reviewStatus FROM '.ContentTranslationRepository::TABLE.' WHERE pid = 12'),
        );
    }

    public function testTheDeclaredColumnSetCarriesNoReviewState(): void
    {
        self::assertNotContains('reviewstatus', $this->repository->columns());
        self::assertContains('headline', $this->repository->columns());
        self::assertContains('text', $this->repository->columns());
        self::assertContains('fieldstates', $this->repository->columns());
    }

    // ---------------------------------------------------------------- refusals

    /**
     * @dataProvider unusableKeys
     */
    public function testAnUnusableStorageKeyIsRefusedWithoutTouchingTheStore(int $sourceId, string $language): void
    {
        self::assertFalse($this->repository->save($sourceId, $language, ['headline' => 'x'], []));
        self::assertNull($this->repository->find($sourceId, $language));
        self::assertSame(0, $this->rowCount());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'no source element' => [0, 'en'];
        yield 'negative source element' => [-1, 'en'];
        yield 'no language' => [12, ''];
    }

    // ---------------------------------------------------------------- failure reporting

    /**
     * A real persistence failure is reported as a failure and recorded with
     * enough context to diagnose it - which is what the generic editor-facing
     * message used to make impossible.
     */
    public function testARealPersistenceFailureIsReportedAndRecorded(): void
    {
        $this->connection->executeStatement('DROP TABLE '.ContentTranslationRepository::TABLE);
        $this->repository->reset();

        $saved = $this->repository->save(12, 'en', ['headline' => 'About us'], ['headline' => FieldStateMap::CUSTOM]);

        self::assertFalse($saved, 'A failed write must never be reported as success.');
        self::assertCount(1, $this->logger->records);

        $context = $this->logger->records[0]['context'];

        self::assertSame(12, $context['source_id']);
        self::assertSame('en', $context['language']);
        self::assertSame('insert', $context['operation']);
        self::assertSame(ContentTranslationRepository::TABLE, $context['table']);
        self::assertContains('headline', $context['columns']);
        self::assertNotSame('', (string) $context['reason']);
        self::assertTrue(is_a($context['exception'], \Throwable::class, true));
    }

    /** The record identifies the write; it never carries what was written. */
    public function testTheFailureRecordCarriesNoTranslatedContent(): void
    {
        $this->connection->executeStatement('DROP TABLE '.ContentTranslationRepository::TABLE);
        $this->repository->reset();

        $this->repository->save(12, 'en', ['headline' => 'Secret headline', 'text' => 'Secret body'], []);

        $flattened = $this->logger->flatten();

        self::assertStringNotContainsString('Secret headline', $flattened);
        self::assertStringNotContainsString('Secret body', $flattened);
        self::assertStringContainsString('headline', $flattened, 'The column names are what makes it diagnosable.');
    }

    /** A read failure is equally survivable and equally quiet about content. */
    public function testAReadFailureReturnsNothingRatherThanBreaking(): void
    {
        $this->connection->executeStatement('DROP TABLE '.ContentTranslationRepository::TABLE);

        self::assertNull($this->repository->find(12, 'en'));
        self::assertSame([], $this->repository->states(12, 'en'));
    }
}
