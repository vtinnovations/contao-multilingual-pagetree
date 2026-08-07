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

namespace Vtinnovations\ContaoMultilingualPagetree\Security;

/**
 * An inbound request whose signature, metadata and product fields have all been
 * verified.
 *
 * The payload is carried through untouched; whoever consumes it still runs the
 * full package verification chain on it.
 */
final class VerifiedChannelRequest
{
    /**
     * @param array<array-key, mixed>|null $seal
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $nonceDigest,
        public readonly string $fingerprint,
        public readonly string $host,
        public readonly mixed $payloadBase64,
        public readonly ?array $seal,
    ) {
    }
}
