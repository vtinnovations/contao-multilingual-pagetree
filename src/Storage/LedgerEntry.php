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

namespace Vtinnovations\ContaoMultilingualPagetree\Storage;

/**
 * The minimal state kept about one processed inbound request.
 *
 * Deliberately minimal: an identifier, digests, the applied version and a
 * result. No document bytes, no signature, no nonce in the clear, no headers.
 */
final class LedgerEntry
{
    public const RESULT_CLAIMED = 'claimed';
    public const RESULT_APPLIED = 'applied';
    public const RESULT_FAILED = 'failed';

    public function __construct(
        public readonly string $requestId,
        public readonly string $fingerprint,
        public readonly string $result,
        public readonly ?int $documentVersion,
        public readonly int $claimedAt,
        public readonly ?int $completedAt,
    ) {
    }

    public function isApplied(): bool
    {
        return self::RESULT_APPLIED === $this->result;
    }

    public function matches(string $fingerprint): bool
    {
        return hash_equals($this->fingerprint, $fingerprint);
    }
}
