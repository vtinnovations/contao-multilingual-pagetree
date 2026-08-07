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
 * Outcome of the explicit connected-to-free import.
 */
final class ImportSummary
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_ALREADY_IMPORTED = 'already_imported';
    public const STATUS_UNCONFIRMED = 'unconfirmed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DENIED = 'denied';

    private function __construct(
        public readonly string $status,
        public readonly int $articles,
        public readonly int $contentElements,
    ) {
    }

    public static function planned(int $articles, int $contentElements): self
    {
        return new self(self::STATUS_PLANNED, $articles, $contentElements);
    }

    public static function imported(int $articles, int $contentElements): self
    {
        return new self(self::STATUS_IMPORTED, $articles, $contentElements);
    }

    public static function alreadyImported(): self
    {
        return new self(self::STATUS_ALREADY_IMPORTED, 0, 0);
    }

    public static function unconfirmed(): self
    {
        return new self(self::STATUS_UNCONFIRMED, 0, 0);
    }

    public static function failed(): self
    {
        return new self(self::STATUS_FAILED, 0, 0);
    }

    /** The installation is not entitled to free content trees. */
    public static function denied(): self
    {
        return new self(self::STATUS_DENIED, 0, 0);
    }

    public function isSuccessful(): bool
    {
        return self::STATUS_IMPORTED === $this->status;
    }

    public function createdRecords(): int
    {
        return $this->articles + $this->contentElements;
    }
}
