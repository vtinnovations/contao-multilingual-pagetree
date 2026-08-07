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
 * The safe, categorised result of an outbound activation or refresh.
 *
 * The status values are deliberately coarse: they are printed by the console
 * command and written to the log, so they must never describe which
 * cryptographic step failed.
 */
final class RegistrationOutcome
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const MALFORMED = 'malformed_response';
    public const HOST_UNKNOWN = 'host_unknown';
    public const NOT_ACTIVATED = 'not_activated';
    public const NOT_OPERATIONAL = 'verification_unavailable';
    public const REJECTED = 'rejected';
    public const MISSING_KEY = 'missing_key';
    public const INVALID_KEY = 'invalid_key';
    public const WRONG_DOMAIN = 'wrong_domain';
    public const WRONG_PROJECT = 'wrong_project';
    public const WRONG_PACKAGE = 'wrong_package';
    public const NOT_YET_VALID = 'not_yet_valid';
    public const EXPIRED = 'expired';
    public const UNSUPPORTED_SCHEMA = 'unsupported_schema';
    public const SIGNATURE_INVALID = 'signature_invalid';

    /**
     * This build has no pinned verification material at all.
     *
     * Distinct from {@see self::UNKNOWN_SIGNING_KEY}, which means the material
     * exists but does not contain the offered id. Both are diagnostics and
     * neither is ever a bypass.
     */
    public const SIGNING_KEY_STORE_EMPTY = 'signing_key_store_empty';
    public const UNKNOWN_SIGNING_KEY = 'unknown_signing_key';
    public const TRANSPORT_TIMEOUT = 'transport_timeout';
    public const TLS_FAILURE = 'tls_failure';
    public const RESPONSE_TOO_LARGE = 'response_too_large';
    public const UNEXPECTED_CONTENT_TYPE = 'unexpected_content_type';
    public const CURL_EXTENSION_MISSING = 'curl_extension_missing';
    public const STORAGE_FAILURE = 'storage_failure';
    public const INTERNAL_ERROR = 'internal_error';

    private function __construct(
        public readonly bool $successful,
        public readonly string $status,
        public readonly ?int $version = null,
        public readonly ?string $requestId = null,
    ) {
    }

    public static function applied(int $version): self
    {
        return new self(true, self::APPLIED, $version);
    }

    public static function unchanged(?int $version): self
    {
        return new self(true, self::UNCHANGED, $version);
    }

    public static function hostUnknown(): self
    {
        return new self(false, self::HOST_UNKNOWN);
    }

    public static function notActivated(): self
    {
        return new self(false, self::NOT_ACTIVATED);
    }

    public static function failure(string $status, ?string $requestId = null): self
    {
        return new self(false, $status, null, $requestId);
    }

    /** The package verified but was refused by the activation rules. */
    public static function rejected(ActivationOutcome $outcome, ?int $version = null): self
    {
        $status = match ($outcome) {
            ActivationOutcome::HostMismatch => self::WRONG_DOMAIN,
            ActivationOutcome::StorageFailure, ActivationOutcome::Busy => self::STORAGE_FAILURE,
            default => self::REJECTED.':'.$outcome->value,
        };

        return new self(false, $status, $version);
    }

    public function withRequestId(string $requestId): self
    {
        return new self($this->successful, $this->status, $this->version, $requestId);
    }
}
