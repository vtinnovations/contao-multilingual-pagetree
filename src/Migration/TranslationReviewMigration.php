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

namespace Vtinnovations\ContaoMultilingualPagetree\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Initialises the review metadata of existing translation records.
 *
 * Conservative by design: a record without a usable reviewed baseline becomes
 * "unreviewed" and never "up to date" or "needs review". Translated values,
 * field states and publication fields are never touched, and no snapshot is
 * fabricated that would pretend an editor had reviewed the record.
 */
final class TranslationReviewMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationFieldRegistry $fields,
    ) {
    }

    public function getName(): string
    {
        return $this->label('contaoMultilingualPagetreeReviewMigration', self::class);
    }

    public function shouldRun(): bool
    {
        foreach ($this->tables() as $table) {
            if ([] !== $this->recordsToNormalise($table)) {
                return true;
            }
        }

        return false;
    }

    public function run(): MigrationResult
    {
        $updated = 0;

        foreach ($this->tables() as $table) {
            foreach ($this->recordsToNormalise($table) as $record) {
                $this->connection->update(
                    $table,
                    [TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value],
                    ['id' => $record['id']],
                );
                ++$updated;
            }
        }

        return $this->createResult(true, sprintf($this->label('contaoMultilingualPagetreeReviewMigrated', '%d'), $updated));
    }

    /**
     * Records whose persisted status is unusable: either the value itself is
     * invalid, or it claims a review that no valid baseline supports. An
     * orphaned record simply falls into the same conservative bucket.
     *
     * @return list<array{id: int|string}>
     */
    private function recordsToNormalise(string $table): array
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([$table])) {
                return [];
            }

            $columns = $schemaManager->listTableColumns($table);

            if (!isset($columns[strtolower(TranslationReviewResolver::FIELD_STATUS)], $columns[strtolower(TranslationReviewResolver::FIELD_REVISION)])) {
                return [];
            }

            $records = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT id, %s, %s FROM %s',
                    TranslationReviewResolver::FIELD_STATUS,
                    TranslationReviewResolver::FIELD_REVISION,
                    $table,
                ),
            );
        } catch (\Throwable) {
            // A missing table or column means there is nothing to migrate.
            return [];
        }

        $pending = [];

        foreach ($records as $record) {
            $status = $record[TranslationReviewResolver::FIELD_STATUS] ?? null;
            $revision = $record[TranslationReviewResolver::FIELD_REVISION] ?? null;
            $hasBaseline = is_string($revision) && 1 === preg_match('/^[a-f0-9]{64}$/i', trim($revision));

            if ($hasBaseline && is_string($status) && null !== ReviewStatus::tryFrom($status)) {
                // Valid existing review metadata is preserved.
                continue;
            }

            if (!$hasBaseline && ReviewStatus::Unreviewed->value === $status) {
                continue;
            }

            $pending[] = ['id' => $record['id']];
        }

        return $pending;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = [];

        foreach ($this->fields->policies() as $policy) {
            if ('' !== $policy->translationTable) {
                $tables[] = $policy->translationTable;
            }
        }

        return array_values(array_unique($tables));
    }

    private function label(string $key, string $default): string
    {
        try {
            \Contao\System::loadLanguageFile('default');
        } catch (\Throwable) {
            // The migration must remain usable without a booted framework.
        }

        $label = $GLOBALS['TL_LANG']['MSC'][$key] ?? null;

        return is_string($label) && '' !== $label ? $label : $default;
    }
}
