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

namespace Vtinnovations\ContaoMultilingualPagetree\Distribution;

/**
 * The public answer to one inbound update request.
 *
 * Success carries the applied version; every failure is a generic category. The
 * body never names the failing check, and never contains a key, a payload, a
 * digest, a signature or a path.
 */
final class ChannelUpdateResult
{
    private function __construct(
        public readonly int $httpStatus,
        public readonly string $status,
        public readonly ?string $requestId = null,
        public readonly ?int $version = null,
    ) {
    }

    public static function updated(string $requestId, int $version): self
    {
        return new self(200, 'updated', $requestId, $version);
    }

    public static function alreadyProcessed(string $requestId, ?int $version): self
    {
        return new self(200, 'already_processed', $requestId, $version);
    }

    /** Authenticated, but the offered state may not replace the active one. */
    public static function conflict(?string $requestId = null): self
    {
        return new self(409, 'rejected', $requestId);
    }

    /** Unsigned, malformed, stale, replayed or otherwise unauthenticated. */
    public static function unauthorized(): self
    {
        return new self(401, 'rejected');
    }

    /** Authenticated but not permitted, for example a host that is not ours. */
    public static function forbidden(): self
    {
        return new self(403, 'rejected');
    }

    /** The ledger, the lock or the storage is temporarily unavailable. */
    public static function unavailable(): self
    {
        return new self(503, 'unavailable');
    }

    /**
     * The response body, in the exact shape the protocol defines.
     *
     * @return array<string, string|int>
     */
    public function toPayload(): array
    {
        $payload = ['status' => $this->status];

        if (null !== $this->requestId) {
            $payload['request_id'] = $this->requestId;
        }

        if (null !== $this->version) {
            $payload['license_version'] = $this->version;
        }

        return $payload;
    }
}
