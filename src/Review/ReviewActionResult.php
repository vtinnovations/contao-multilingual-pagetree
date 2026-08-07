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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

/**
 * Outcome of an explicit review action.
 */
final class ReviewActionResult
{
    public const REASON_OK = 'ok';
    public const REASON_INVALID_RECORD = 'invalid_record';
    public const REASON_SOURCE_MISSING = 'source_missing';
    public const REASON_DENIED = 'denied';
    public const REASON_INVALID_TOKEN = 'invalid_token';
    public const REASON_FAILED = 'failed';

    private function __construct(
        public readonly bool $successful,
        public readonly string $reason,
        public readonly ?string $revision = null,
    ) {
    }

    public static function success(string $revision): self
    {
        return new self(true, self::REASON_OK, $revision);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }
}
