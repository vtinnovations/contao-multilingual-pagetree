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
final class ContentTranslationRepository
{
    public const TABLE = ContentTranslationFieldPolicy::TRANSLATION_TABLE;

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

        try {
            $existing = $this->connection->fetchOne(
                'SELECT id FROM '.self::TABLE.' WHERE pid = :pid AND language = :language LIMIT 1',
                ['pid' => $sourceId, 'language' => $language],
            );

            if (false === $existing || null === $existing) {
                $values['pid'] = $sourceId;
                $values['language'] = $language;
                $values['reviewStatus'] ??= ReviewStatus::Unreviewed->value;

                $this->connection->insert(self::TABLE, $values);

                return true;
            }

            // pid and language identify the row; they are never part of an update.
            unset($values['pid'], $values['language'], $values['id']);

            $this->connection->update(self::TABLE, $values, ['id' => (int) $existing]);

            return true;
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not store the translation of content element %d in "%s": %s',
                $sourceId,
                $language,
                $exception->getMessage(),
            ));

            return false;
        }
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
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([self::TABLE])) {
                return [];
            }

            return array_keys($schemaManager->listTableColumns(self::TABLE));
        } catch (\Throwable) {
            return [];
        }
    }
}
