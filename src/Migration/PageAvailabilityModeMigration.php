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
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;

/**
 * Initialises the page-availability mode of existing language configurations.
 *
 * Every non-default language of an existing installation becomes "fallback",
 * which is exactly how those installations behaved before the mode existed.
 * Values an editor already chose are never overwritten, invalid values are
 * normalised, and running the migration again changes nothing.
 */
final class PageAvailabilityModeMigration extends AbstractMigration
{
    private const TABLE = 'tl_inline_language';
    private const COLUMN = 'pageAvailabilityMode';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return $this->label('contaoMultilingualPagetreeAvailabilityMigration', self::class);
    }

    public function shouldRun(): bool
    {
        return [] !== $this->recordsToNormalise();
    }

    public function run(): MigrationResult
    {
        $updated = 0;

        foreach ($this->recordsToNormalise() as $record) {
            $this->connection->update(
                self::TABLE,
                [self::COLUMN => PageAvailabilityMode::Fallback->value],
                ['id' => $record['id']],
            );
            ++$updated;
        }

        return $this->createResult(true, sprintf($this->label('contaoMultilingualPagetreeAvailabilityMigrated', '%d'), $updated));
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

    /**
     * Non-default language records whose persisted mode is not one of the
     * supported values.
     *
     * @return list<array{id: int|string}>
     */
    private function recordsToNormalise(): array
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([self::TABLE])) {
                return [];
            }

            $columns = $schemaManager->listTableColumns(self::TABLE);

            if (!isset($columns[strtolower(self::COLUMN)])) {
                return [];
            }

            $records = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT id, %s FROM %s WHERE (fallback IS NULL OR fallback != 1)',
                    self::COLUMN,
                    self::TABLE,
                ),
            );
        } catch (\Throwable) {
            // A missing table or column simply means there is nothing to migrate.
            return [];
        }

        $pending = [];

        foreach ($records as $record) {
            $value = $record[self::COLUMN] ?? null;

            // An explicitly configured valid value is authoritative.
            if (is_string($value) && null !== PageAvailabilityMode::tryFrom($value)) {
                continue;
            }

            $pending[] = ['id' => $record['id']];
        }

        return $pending;
    }
}
