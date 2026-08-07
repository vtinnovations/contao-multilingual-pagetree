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
use Vtinnovations\ContaoMultilingualPagetree\Switcher\UnavailableLanguageDisplay;

/**
 * Initialises the unavailable-language presentation of existing language
 * switcher modules.
 *
 * Existing module records keep working unchanged: every empty or invalid value
 * becomes "hide", which is how the switcher behaved before the setting existed.
 * A value an editor already chose is never overwritten and a second run changes
 * nothing.
 */
final class UnavailableLanguageDisplayMigration extends AbstractMigration
{
    private const TABLE = 'tl_module';
    private const COLUMN = 'unavailableLanguageDisplay';
    private const MODULE_TYPE = 'language_switcher';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return $this->label('contaoMultilingualPagetreeSwitcherDisplayMigration', self::class);
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
                [self::COLUMN => UnavailableLanguageDisplay::Hide->value],
                ['id' => $record['id']],
            );
            ++$updated;
        }

        return $this->createResult(true, sprintf($this->label('contaoMultilingualPagetreeSwitcherDisplayMigrated', '%d'), $updated));
    }

    /**
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
                sprintf('SELECT id, %s FROM %s WHERE type = :type', self::COLUMN, self::TABLE),
                ['type' => self::MODULE_TYPE],
            );
        } catch (\Throwable) {
            // A missing table or column simply means there is nothing to migrate.
            return [];
        }

        $pending = [];

        foreach ($records as $record) {
            $value = $record[self::COLUMN] ?? null;

            if (is_string($value) && null !== UnavailableLanguageDisplay::tryFrom($value)) {
                continue;
            }

            $pending[] = ['id' => $record['id']];
        }

        return $pending;
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
