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

use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Support\CanonicalInput;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;

/**
 * Authenticates an inbound server-to-server request cryptographically.
 *
 * Trust is established by a signature over the method, the exact path, the
 * request metadata and a SHA-256 of the raw body - nothing else. A claimed web
 * origin proves nothing here: `Origin`, `Referer`, the user agent, reverse DNS
 * and the source address are not consulted at all, because any of them can be
 * set by whoever sends the request. Optional mutual TLS or a network allowlist
 * in front of the application may add defence in depth, but never replaces this
 * check.
 *
 * The request deliberately does not depend on a backend session or a browser
 * CSRF token: it is machine-to-machine traffic that arrives without either.
 *
 * Every failure raises the same kind of rejection with an internal category; the
 * caller turns that into one generic public response.
 */
final class ChannelRequestVerifier
{
    public const HEADER_REQUEST_ID = 'X-VT-Request-ID';
    public const HEADER_TIMESTAMP = 'X-VT-Timestamp';
    public const HEADER_NONCE = 'X-VT-Nonce';
    public const HEADER_KEY_ID = 'X-VT-Key-ID';
    public const HEADER_SIGNATURE = 'X-VT-Signature';

    public function __construct(
        private readonly DetachedSignature $signatures,
        private readonly CanonicalHost $hosts,
    ) {
    }

    /**
     * @param array<string, string|null> $headers the five signed metadata headers
     *
     * @throws ChannelRequestRejected
     */
    public function verify(string $method, string $path, array $headers, string $rawBody, int $now): VerifiedChannelRequest
    {
        if (!$this->signatures->isOperational()) {
            // No pinned material means nothing can be authenticated, so nothing
            // is accepted. This build simply has no trusted counterpart.
            throw new ChannelRequestRejected('signing_key_store_empty');
        }

        $requestId = $this->header($headers, self::HEADER_REQUEST_ID, '/^[A-Za-z0-9._:-]{8,64}$/');
        $nonce = $this->header($headers, self::HEADER_NONCE, '/^[A-Za-z0-9._:-]{16,128}$/');
        $keyId = $this->header($headers, self::HEADER_KEY_ID, '/^[A-Za-z0-9._-]{1,64}$/');
        $signature = $this->header($headers, self::HEADER_SIGNATURE, '/^[A-Za-z0-9+\/=]{16,512}$/');
        $timestamp = $this->timestamp($headers, $now);

        if ('' === $rawBody || strlen($rawBody) > ProductProfile::MAX_REQUEST_BYTES) {
            throw new ChannelRequestRejected('body_size');
        }

        // The signature covers the verb and the exact path as well as the body
        // digest, so a captured request cannot be replayed against another route
        // or another method. The key id selects the key and is deliberately not
        // part of the signed message: swapping it cannot help, because the
        // signature over everything else would then no longer verify.
        try {
            $canonical = CanonicalInput::request(
                $method,
                $path,
                $requestId,
                $timestamp,
                $nonce,
                hash('sha256', $rawBody),
            );
        } catch (\InvalidArgumentException) {
            throw new ChannelRequestRejected('canonical_input');
        }

        if (!$this->signatures->verifyWithPinnedScheme($canonical, $signature, $keyId, $now)) {
            throw new ChannelRequestRejected('signature');
        }

        $body = $this->body($rawBody);

        // The signed headers and the duplicated body fields must agree; a
        // mismatch means one of them was not covered by what we verified.
        $this->requireSame($body, 'request_id', $requestId);
        $this->requireSame($body, 'nonce', $nonce);

        if (($body['timestamp'] ?? null) !== $timestamp) {
            throw new ChannelRequestRejected('timestamp_mismatch');
        }

        $this->requireSame($body, 'action', 'license_update');
        $this->requireSame($body, 'project', ProductProfile::PROJECT);
        $this->requireSame($body, 'project_slug', ProductProfile::PROJECT_SLUG);
        $this->requireSame($body, 'product_id', ProductProfile::PRODUCT_ID);

        $host = $this->hosts->normalize($body['domain'] ?? null);

        if (null === $host) {
            throw new ChannelRequestRejected('domain');
        }

        $seal = $body['integrity'] ?? null;

        return new VerifiedChannelRequest(
            $requestId,
            hash('sha256', $nonce),
            hash('sha256', $rawBody),
            $host,
            $body['license_payload_b64'] ?? null,
            is_array($seal) ? $seal : null,
        );
    }

    /**
     * @param array<string, string|null> $headers
     */
    private function header(array $headers, string $name, string $pattern): string
    {
        $value = $headers[$name] ?? null;

        if (!is_string($value) || 1 !== preg_match($pattern, $value)) {
            throw new ChannelRequestRejected('header:'.strtolower($name));
        }

        return $value;
    }

    /**
     * @param array<string, string|null> $headers
     */
    private function timestamp(array $headers, int $now): int
    {
        $value = $headers[self::HEADER_TIMESTAMP] ?? null;

        if (!is_string($value) || 1 !== preg_match('/^\d{1,12}$/', $value)) {
            throw new ChannelRequestRejected('header:timestamp');
        }

        $timestamp = (int) $value;

        // A narrow window in both directions: an old capture is stale, and a
        // far-future stamp would otherwise extend the replay window.
        if ($timestamp < $now - ProductProfile::REQUEST_PAST_TOLERANCE
            || $timestamp > $now + ProductProfile::REQUEST_FUTURE_TOLERANCE
        ) {
            throw new ChannelRequestRejected('timestamp_window');
        }

        return $timestamp;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(string $rawBody): array
    {
        try {
            $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ChannelRequestRejected('body_json');
        }

        if (!is_array($decoded)) {
            throw new ChannelRequestRejected('body_shape');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function requireSame(array $body, string $field, string $expected): void
    {
        $value = $body[$field] ?? null;

        if (!is_string($value) || !hash_equals($expected, $value)) {
            throw new ChannelRequestRejected('field:'.$field);
        }
    }
}
