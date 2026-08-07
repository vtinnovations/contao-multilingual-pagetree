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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

/**
 * The only read/write access to the content translation store.
 *
 * `tl_content_translation` holds one row per source element and language. It is
 * storage, not a Contao backend table: it has no palettes, no operations and no
 * data container, and it is never opened for editing. The backend edits the
 * native `tl_content` record and this repository moves the translated values in
 * and out.
 *
 * Every write is keyed by the exact source id *and* the exact language, so two
 * languages of one element can never share a row and a translation can never be
 * written against a different source element.
 */
final class ContentTranslationRepository implements ResetInterface
{
    public const TABLE = ContentTranslationFieldPolicy::TRANSLATION_TABLE;

    /** Memoised physical column names, lower case; null until first read. */
    private ?array $columns = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly FieldStateMap $states,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The stored translation of one source element, or null when the editor has
     * never saved anything for that language.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $sourceId, string $language): ?array
    {
        if ($sourceId <= 0 || '' === $language) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.self::TABLE.' WHERE pid = :pid AND language = :language LIMIT 1',
                ['pid' => $sourceId, 'language' => $language],
            );

            return false === $row ? null : $row;
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not read the translation of content element %d in "%s": %s',
                $sourceId,
                $language,
                $exception->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Stores the approved translated values of one language.
     *
     * The row is created on first save and updated afterwards - never when a
     * form is merely opened. Only the columns handed in are written, so nothing
     * outside the canonical field policy can reach the table.
     *
     * @param array<string, mixed>  $values approved translated values only
     * @param array<string, string> $states provenance of those values
     */
    public function save(int $sourceId, string $language, array $values, array $states): bool
    {
        if ($sourceId <= 0 || '' === $language) {
            return false;
        }

        $values['fieldStates'] = $this->states->encode($states);
        $values['tstamp'] = time();

        $operation = 'insert';

        try {
            $existing = $this->connection->fetchOne(
                'SELECT id FROM '.self::TABLE.' WHERE pid = :pid AND language = :language LIMIT 1',
                ['pid' => $sourceId, 'language' => $language],
            );

            if (false === $existing || null === $existing) {
                $values['pid'] = $sourceId;
                $values['language'] = $language;

                // Content translations carry no review state of their own - the
                // editorial review workflow lives on page, article, news, event
                // and FAQ translations. Older builds seeded the column here
                // regardless, which made every first save fail with "unknown
                // column" on a table that never had it. It is written now only
                // where an installation actually still carries it.
                if ($this->hasColumn('reviewStatus')) {
                    $values['reviewStatus'] ??= ReviewStatus::Unreviewed->value;
                }

                $this->connection->insert(self::TABLE, $this->writable($values));

                return true;
            }

            $operation = 'update';

            // pid and language identify the row; they are never part of an update.
            unset($values['pid'], $values['language'], $values['id']);

            $this->connection->update(self::TABLE, $this->writable($values), ['id' => (int) $existing]);

            return true;
        } catch (\Throwable $exception) {
            // The category the editor sees is deliberately vague; the record
            // below is what makes the failure diagnosable. It carries the keys
            // of the write and the exception, never a translated value, never a
            // token, session, credential or licence detail.
            $this->logger?->error('Contao Multilingual Pagetree: storing a content translation failed.', [
                'source_id' => $sourceId,
                'language' => $language,
                'table' => self::TABLE,
                'operation' => $operation,
                'columns' => array_keys($values),
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The subset of a write the table can actually accept.
     *
     * A column the store does not have is dropped rather than sent, because one
     * unknown column fails the whole statement and takes every other translated
     * value of that save with it. Dropping is safe: the value set has already
     * passed the canonical field policy, so what is discarded here is a column
     * this installation's schema does not carry - never arbitrary input.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function writable(array $values): array
    {
        $physical = $this->columns();

        if ([] === $physical) {
            // The schema could not be read. Writing unchanged is the previous
            // behaviour and still fails loudly rather than silently dropping
            // values that may well be storable.
            return $values;
        }

        return array_filter(
            $values,
            static fn (string $column): bool => in_array(strtolower($column), $physical, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Whether the store physically carries a column, whatever its casing. */
    private function hasColumn(string $column): bool
    {
        return in_array(strtolower($column), $this->columns(), true);
    }

    /**
     * The provenance map of a stored translation.
     *
     * @return array<string, string>
     */
    public function states(int $sourceId, string $language): array
    {
        return $this->states->decode($this->find($sourceId, $language)['fieldStates'] ?? null);
    }

    /**
     * The physical columns of the store, so a value can never be written to a
     * column that does not exist.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        // Read once per request: a save consults it for every write, and the
        // schema cannot change underneath a single request.
        if (null !== $this->columns) {
            return $this->columns;
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([self::TABLE])) {
                return $this->columns = [];
            }

            // Lowercased explicitly rather than relying on the driver: every
            // comparison against this list is made in lower case.
            return $this->columns = array_values(array_map(
                'strtolower',
                array_keys($schemaManager->listTableColumns(self::TABLE)),
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Drops the memoised schema; called between worker cycles. */
    public function reset(): void
    {
        $this->columns = null;
    }
}
