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
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\InvalidLanguageUrlException;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\ProtocolMode;

/**
 * Brings the persisted language URL mapping into its canonical stored form.
 *
 * The columns themselves are created by Contao's own DCA-driven schema update,
 * so this migration only normalises values. It is deliberately conservative:
 *
 *  - an empty protocol, domain or entry point is left empty, because empty is
 *    the state that preserves the previous URL behaviour of that record;
 *  - a value that is already canonical is never rewritten;
 *  - a value that cannot be normalised is cleared rather than guessed, which
 *    returns that record to the previous behaviour instead of inventing a URL;
 *  - running it a second time changes nothing.
 *
 * No row is ever deleted and no other column is touched.
 */
final class LanguageUrlMigration extends AbstractMigration
{
    private const TABLE = 'tl_inline_language';

    /** @var list<string> */
    private const COLUMNS = ['urlProtocol', 'urlDomain', 'urlEntryPoint'];

    public function __construct(
        private readonly Connection $connection,
        private readonly LanguageDomainNormalizer $domains,
        private readonly EntryPointNormalizer $entryPoints,
    ) {
    }

    public function getName(): string
    {
        return $this->label('contaoMultilingualPagetreeLanguageUrlMigration', self::class);
    }

    public function shouldRun(): bool
    {
        return [] !== $this->pendingRecords();
    }

    public function run(): MigrationResult
    {
        $updated = 0;

        foreach ($this->pendingRecords() as $record) {
            $this->connection->update(self::TABLE, $record['values'], ['id' => $record['id']]);
            ++$updated;
        }

        return $this->createResult(
            true,
            sprintf($this->label('contaoMultilingualPagetreeLanguageUrlMigrated', '%d'), $updated),
        );
    }

    /**
     * @return list<array{id: int|string, values: array<string, string>}>
     */
    private function pendingRecords(): array
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([self::TABLE])) {
                return [];
            }

            $columns = $schemaManager->listTableColumns(self::TABLE);

            foreach (self::COLUMNS as $column) {
                if (!isset($columns[strtolower($column)])) {
                    // The schema update has not created the columns yet; the
                    // migration simply has nothing to normalise.
                    return [];
                }
            }

            $records = $this->connection->fetchAllAssociative(
                sprintf('SELECT id, %s FROM %s', implode(', ', self::COLUMNS), self::TABLE),
            );
        } catch (\Throwable) {
            return [];
        }

        $pending = [];

        foreach ($records as $record) {
            $values = $this->normalise($record);

            if ([] !== $values) {
                $pending[] = ['id' => $record['id'], 'values' => $values];
            }
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, string> only the columns whose stored form differs
     */
    private function normalise(array $record): array
    {
        $values = [];

        $protocol = ProtocolMode::fromValue($record['urlProtocol'] ?? null)->value;

        if ($protocol !== (string) ($record['urlProtocol'] ?? '')) {
            $values['urlProtocol'] = $protocol;
        }

        $storedDomain = (string) ($record['urlDomain'] ?? '');

        try {
            $domain = '' === trim($storedDomain) ? '' : ($this->domains->normalize($storedDomain) ?? '');
        } catch (InvalidLanguageUrlException) {
            $domain = '';
        }

        if ($domain !== $storedDomain) {
            $values['urlDomain'] = $domain;
        }

        $storedEntryPoint = (string) ($record['urlEntryPoint'] ?? '');
        $entryPoint = $this->entryPoints->normalizeOrLegacy($storedEntryPoint);

        if ($entryPoint !== $storedEntryPoint) {
            $values['urlEntryPoint'] = $entryPoint;
        }

        return $values;
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
