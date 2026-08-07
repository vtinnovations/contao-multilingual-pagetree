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

/**
 * Language ownership of one article or content record.
 *
 * Three record kinds exist in this bundle and this value object distinguishes
 * the two that live in Contao's own tables:
 *
 *  - source/default record: no owning language (the shared structure)
 *  - free-language record:  owned by exactly one non-default language of one root site
 *
 * Connected translation records live in the separate tl_*_translation tables and
 * are never represented here.
 */
final class ContentOwnership
{
    // Legacy persisted column names from releases before the product rename.
    // They intentionally remain stable so existing free-content ownership data
    // does not need a risky schema/data migration.
    public const FIELD_LANGUAGE = 'languageFlowLanguage';
    public const FIELD_ROOT = 'languageFlowRoot';

    private function __construct(
        public readonly string $language,
        public readonly int $rootPageId,
    ) {
    }

    public static function source(): self
    {
        return new self('', 0);
    }

    public static function free(string $language, int $rootPageId): self
    {
        $language = trim($language);

        return '' === $language ? self::source() : new self($language, max(0, $rootPageId));
    }

    /**
     * Reads the ownership of a record row. Anything unusable is a source record,
     * which is the safe default for legacy rows.
     *
     * @param array<string, mixed>|object $record
     */
    public static function fromRecord(array|object $record): self
    {
        $row = self::row($record);
        $language = $row[self::FIELD_LANGUAGE] ?? null;

        if (!is_string($language)) {
            return self::source();
        }

        $language = trim($language);

        if ('' === $language || 1 !== preg_match('/^[A-Za-z]{2}(?:[_-][A-Za-z]{2})?$/', $language)) {
            return self::source();
        }

        $rootPageId = $row[self::FIELD_ROOT] ?? 0;

        return new self($language, is_numeric($rootPageId) ? (int) $rootPageId : 0);
    }

    public function isSource(): bool
    {
        return '' === $this->language;
    }

    public function isFree(): bool
    {
        return '' !== $this->language;
    }

    public function belongsTo(string $language): bool
    {
        return '' !== $this->language
            && strtolower(str_replace('-', '_', $this->language)) === strtolower(str_replace('-', '_', $language));
    }

    /**
     * A child record must carry exactly the ownership of its owner.
     */
    public function matches(self $other): bool
    {
        return $this->language === $other->language && $this->rootPageId === $other->rootPageId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            self::FIELD_LANGUAGE => $this->language,
            self::FIELD_ROOT => $this->rootPageId,
        ];
    }

    /**
     * @param array<string, mixed>|object $record
     *
     * @return array<string, mixed>
     */
    private static function row(array|object $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (method_exists($record, 'row')) {
            $row = $record->row();

            if (is_array($row)) {
                return $row;
            }
        }

        return get_object_vars($record);
    }
}
