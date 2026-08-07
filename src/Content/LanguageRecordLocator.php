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

/**
 * Reads one tl_inline_language configuration record.
 *
 * Record ids never come from unvalidated request input: the DataContainer
 * resolved them before they reach this class.
 */
class LanguageRecordLocator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, pid, language, fallback, contentTranslationMode FROM tl_inline_language WHERE id = :id',
                ['id' => $id],
            );
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }
}
