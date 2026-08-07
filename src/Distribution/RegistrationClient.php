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

use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Helper\Clock;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageFormatException;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageReader;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;

/**
 * The installation-initiated half of the channel: first activation and
 * administrator refresh.
 *
 * Both send one JSON packet to the fixed verification endpoint and expect one
 * complete signed package back - never a semantic delta. Validation runs in a
 * fixed order and every failure is non-destructive: a network error, a timeout,
 * a malformed answer or a service outage leaves working local state exactly as
 * it was. Only a newer, fully verified package ever replaces it.
 */
final class RegistrationClient
{
    /**
     * The stage an attempt reached.
     *
     * The value is a deployment diagnostic, printed into the operation log so an
     * operator can see how far a real exchange got. It is never a decision:
     * nothing anywhere reads a stage and grants something because of it.
     */
    public const STAGE_SIGNING_KEY_STORE = 'signing_key_store';
    public const STAGE_TRANSPORT = 'transport';
    public const STAGE_RESPONSE_ENVELOPE = 'response_envelope';
    public const STAGE_PACKAGE_VERIFICATION = 'package_verification';
    public const STAGE_ENTITLEMENT = 'entitlement';
    public const STAGE_ACTIVATION = 'activation';

    public function __construct(
        private readonly ChannelTransportInterface $transport,
        private readonly PackageReader $reader,
        private readonly PackageActivator $activator,
        private readonly PackageStoreInterface $store,
        private readonly InstallationIdentity $identity,
        private readonly CanonicalHost $hosts,
        private readonly Clock $clock,
        private readonly DetachedSignature $signatures,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RootScope $rootContext = null,
    ) {
    }

    /**
     * First activation with a subscription key.
     */
    public function activate(string $subscriptionKey): RegistrationOutcome
    {
        $key = preg_replace('/\A[ \t\r\n]+|[ \t\r\n]+\z/', '', $subscriptionKey) ?? $subscriptionKey;

        if ('' === $key) {
            return RegistrationOutcome::failure(RegistrationOutcome::MISSING_KEY);
        }
        if (strlen($key) > ProductProfile::MAX_LICENSE_KEY_BYTES || 1 === preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return RegistrationOutcome::failure(RegistrationOutcome::INVALID_KEY);
        }

        return $this->exchange('activate', $key, null);
    }

    /**
     * Administrator refresh of an already activated installation.
     *
     * The current version is sent so the service can decide whether anything
     * changed; the answer is still a complete package.
     */
    public function refresh(): RegistrationOutcome
    {
        $now = $this->clock->now();
        $active = $this->store->load($now);

        if (null === $active) {
            return RegistrationOutcome::notActivated();
        }

        return $this->exchange('refresh', $active->document->subscriptionKey, $active->document->version);
    }

    private function exchange(string $action, string $subscriptionKey, ?int $currentVersion): RegistrationOutcome
    {
        $host = $this->identity->current();

        if (null === $host) {
            return RegistrationOutcome::hostUnknown();
        }

        $now = $this->clock->now();
        $requestId = bin2hex(random_bytes(16));
        $startedAt = microtime(true);

        $stage = self::STAGE_SIGNING_KEY_STORE;

        if (!$this->signatures->isOperational()) {
            // Without pinned material nothing can be verified, so nothing may be
            // accepted. Failing here also avoids sending a key pointlessly.
            $this->logAttempt(RegistrationOutcome::SIGNING_KEY_STORE_EMPTY, $host, $requestId, null, null, 0, null, 0.0, null, null, $stage);

            return RegistrationOutcome::failure(RegistrationOutcome::SIGNING_KEY_STORE_EMPTY, $requestId);
        }

        $stage = self::STAGE_TRANSPORT;

        $packet = [
            'action' => $action,
            'project' => ProductProfile::PROJECT,
            'project_slug' => ProductProfile::PROJECT_SLUG,
            'product_id' => ProductProfile::PRODUCT_ID,
            'license_key' => $subscriptionKey,
            'domain' => $host,
            'request_id' => $requestId,
            'timestamp' => $now,
            'nonce' => bin2hex(random_bytes(24)),
        ];

        if (null !== $currentVersion) {
            $packet['current_license_version'] = $currentVersion;
        }

        try {
            $json = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = $this->transport->postJson(
                ChannelAddress::verify(),
                $json,
                ProductProfile::CONNECT_TIMEOUT,
                ProductProfile::TOTAL_TIMEOUT,
                ProductProfile::MAX_RESPONSE_BYTES,
            );
        } catch (ChannelTransportException $exception) {
            $status = match ($exception->category) {
                ChannelTransportException::CURL_EXTENSION_MISSING => RegistrationOutcome::CURL_EXTENSION_MISSING,
                ChannelTransportException::TRANSPORT_TIMEOUT => RegistrationOutcome::TRANSPORT_TIMEOUT,
                ChannelTransportException::TLS_FAILURE => RegistrationOutcome::TLS_FAILURE,
                ChannelTransportException::RESPONSE_TOO_LARGE => RegistrationOutcome::RESPONSE_TOO_LARGE,
                ChannelTransportException::INTERNAL_ERROR => RegistrationOutcome::INTERNAL_ERROR,
                default => RegistrationOutcome::NOT_OPERATIONAL,
            };
            $this->logAttempt($status, $host, $requestId, null, null, 0, null, microtime(true) - $startedAt, $exception->category, null, $stage);

            return RegistrationOutcome::failure($status, $requestId);
        } catch (\JsonException) {
            $this->logAttempt(RegistrationOutcome::INTERNAL_ERROR, $host, $requestId, null, null, 0, null, microtime(true) - $startedAt, null, null, $stage);

            return RegistrationOutcome::failure(RegistrationOutcome::INTERNAL_ERROR, $requestId);
        }

        $outcome = $this->consume($response, $requestId, $host, $now, $stage);
        $keyId = null;
        $schema = null;
        if ($response->isJson()) {
            $decoded = json_decode($response->body, true);
            $candidateKeyId = is_array($decoded) && is_string($decoded['integrity']['key_id'] ?? null) ? $decoded['integrity']['key_id'] : null;
            $keyId = null !== $candidateKeyId && 1 === preg_match('/^[A-Za-z0-9._-]{1,64}$/', $candidateKeyId) ? $candidateKeyId : null;
            $payload = is_array($decoded) && is_string($decoded['license_payload_b64'] ?? null) ? base64_decode($decoded['license_payload_b64'], true) : false;
            $document = is_string($payload) ? json_decode($payload, true) : null;
            $schema = is_array($document) && is_int($document['schema_version'] ?? null) ? $document['schema_version'] : null;
        }
        $safeContentType = preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+(?:;[ A-Za-z0-9=._+-]+)?$/', $response->contentType) ? $response->contentType : null;
        $this->logAttempt($outcome->status, $host, $requestId, $response->statusCode, $safeContentType, strlen($response->body), $keyId, microtime(true) - $startedAt, null, $schema, $stage);

        return $outcome;
    }

    private function consume(ChannelResponse $response, string $requestId, string $host, int $sentAt, ?string &$stage = null): RegistrationOutcome
    {
        $stage = self::STAGE_RESPONSE_ENVELOPE;

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            // A 5xx or any other status is transient as far as this
            // installation is concerned: nothing local changes.
            return $response->statusCode >= 500
                ? RegistrationOutcome::failure(RegistrationOutcome::NOT_OPERATIONAL, $requestId)
                : RegistrationOutcome::failure(RegistrationOutcome::INVALID_KEY, $requestId);
        }

        if (!$response->isJson()) {
            return RegistrationOutcome::failure(RegistrationOutcome::UNEXPECTED_CONTENT_TYPE, $requestId);
        }
        if (strlen($response->body) > ProductProfile::MAX_RESPONSE_BYTES) {
            return RegistrationOutcome::failure(RegistrationOutcome::RESPONSE_TOO_LARGE, $requestId);
        }

        try {
            $data = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return RegistrationOutcome::failure(RegistrationOutcome::MALFORMED, $requestId);
        }

        if (!is_array($data)) {
            return RegistrationOutcome::failure(RegistrationOutcome::MALFORMED, $requestId);
        }

        $echoed = $data['request_id'] ?? null;

        if (!is_string($echoed) || !hash_equals($requestId, $echoed)) {
            // An answer that does not correlate could belong to another
            // exchange entirely and is never applied.
            return RegistrationOutcome::failure(RegistrationOutcome::MALFORMED, $requestId);
        }

        $serverTime = $data['server_time'] ?? null;

        if (!is_int($serverTime) || abs($serverTime - $sentAt) > ProductProfile::SERVER_TIME_TOLERANCE) {
            return RegistrationOutcome::failure(RegistrationOutcome::MALFORMED, $requestId);
        }

        $status = $data['status'] ?? null;

        if (!is_string($status) || !hash_equals('valid', $status)) {
            // An unsigned denial never deletes anything locally. A real
            // revocation arrives as a signed document with a newer version.
            $this->logger?->notice('Contao Multilingual Pagetree: the distribution service did not grant a package.');

            return RegistrationOutcome::failure($this->denialCode($status), $requestId);
        }

        $now = $this->clock->now();
        $stage = self::STAGE_PACKAGE_VERIFICATION;

        try {
            $package = $this->reader->readPackage($data['license_payload_b64'] ?? null, $data['integrity'] ?? null, $now);
        } catch (PackageFormatException $exception) {
            $this->logger?->warning('Contao Multilingual Pagetree: a package from the distribution service failed verification.');

            return RegistrationOutcome::failure($this->packageFailureCode($exception), $requestId);
        }

        $stage = self::STAGE_ENTITLEMENT;

        // The service signs the authorised host set into every current package.
        // One that carries none is either a stale build on the other side or a
        // replayed pre-upgrade document; neither may become active state.
        if ($package->document->isLegacyHostBinding()) {
            return RegistrationOutcome::failure(RegistrationOutcome::UNSUPPORTED_SCHEMA, $requestId);
        }

        // The exact intersection this scope was activated for: the host that was
        // asked about is the configured host of the selected root, and it has to
        // be one the issuer actually authorised. The activator additionally
        // requires it to equal the signed operation host.
        if (!$package->document->authorises($host, $this->hosts)) {
            return RegistrationOutcome::failure(RegistrationOutcome::WRONG_DOMAIN, $requestId);
        }

        // This product is issued for life, so a document carrying an end date is
        // refused before it is ever written. Rejecting here rather than only at
        // the entitlement gate is what keeps an administrator from activating a
        // licence that would appear to succeed and then grant nothing. State
        // already on disk is left untouched by a refused activation.
        if (!$package->document->lifetime) {
            return RegistrationOutcome::failure(RegistrationOutcome::WRONG_PACKAGE, $requestId);
        }

        if ($package->document->startsAt > $now) {
            return RegistrationOutcome::failure(RegistrationOutcome::NOT_YET_VALID, $requestId);
        }
        if ('expired' === $package->document->status->value) {
            return RegistrationOutcome::failure(RegistrationOutcome::EXPIRED, $requestId);
        }
        if ('valid' !== $package->document->status->value) {
            return RegistrationOutcome::failure(RegistrationOutcome::INVALID_KEY, $requestId);
        }

        $stage = self::STAGE_ACTIVATION;

        // The packet host and the trusted host are the same value here by
        // construction; the activator still re-checks both against the signed
        // host, so this path cannot skip the binding rule.
        $outcome = $this->activator->apply($package, $host, $host, $now);

        return (match (true) {
            $outcome->isApplied() => RegistrationOutcome::applied($package->document->version),
            ActivationOutcome::AlreadyCurrent === $outcome => RegistrationOutcome::unchanged($package->document->version),
            default => RegistrationOutcome::rejected($outcome, $this->activator->activeVersion($now)),
        })->withRequestId($requestId);
    }

    /** Convenience for diagnostics: the canonical host we would send. */
    public function currentHost(): ?string
    {
        return $this->hosts->normalize($this->identity->current());
    }

    private function denialCode(mixed $status): string
    {
        return match ($status) {
            'missing_key' => RegistrationOutcome::MISSING_KEY,
            'invalid', 'invalid_key', 'denied' => RegistrationOutcome::INVALID_KEY,
            'wrong_domain', 'domain_mismatch' => RegistrationOutcome::WRONG_DOMAIN,
            'wrong_project', 'project_mismatch' => RegistrationOutcome::WRONG_PROJECT,
            'wrong_package', 'package_mismatch' => RegistrationOutcome::WRONG_PACKAGE,
            'not_yet_valid' => RegistrationOutcome::NOT_YET_VALID,
            'expired' => RegistrationOutcome::EXPIRED,
            default => RegistrationOutcome::INVALID_KEY,
        };
    }

    private function packageFailureCode(PackageFormatException $exception): string
    {
        $reason = $exception->getMessage();

        return match (true) {
            str_contains($reason, 'Unknown signing key') => RegistrationOutcome::UNKNOWN_SIGNING_KEY,
            str_contains($reason, 'signature') => RegistrationOutcome::SIGNATURE_INVALID,
            str_contains($reason, 'schema') || str_contains($reason, 'unsupported fields') => RegistrationOutcome::UNSUPPORTED_SCHEMA,
            str_contains($reason, 'another product') => RegistrationOutcome::WRONG_PROJECT,
            str_contains($reason, 'Unknown tier') => RegistrationOutcome::WRONG_PACKAGE,
            default => RegistrationOutcome::MALFORMED,
        };
    }

    /**
     * The one operation record written per explicit activation or refresh.
     *
     * Everything here is deployment metadata. Deliberately absent, and never to
     * be added: the licence key or any length, hash or fingerprint of it; the
     * request or response packet; the nonce; the Base64 payload; the decoded
     * document; the digest; any signature; any canonical signing input; any
     * request or response SHA-256; and the contents of a public key. The public
     * key *id* is kept, because rotation diagnostics need it and it is not
     * secret.
     */
    private function logAttempt(string $result, string $domain, string $requestId, ?int $httpStatus, ?string $contentType, int $bytes, ?string $keyId, float $elapsed, ?string $transportFailure = null, ?int $schema = null, ?string $stage = null): void
    {
        $now = $this->clock->now();

        $this->logger?->info('Contao Multilingual Pagetree licence verification attempt.', [
            'result_code' => $result,
            'verification_stage' => $stage,
            'root_id' => $this->rootContext?->rootId() ?? 0,
            'domain' => $domain,
            'http_status' => $httpStatus,
            'transport_failure' => $transportFailure,
            'response_content_type' => $contentType,
            'response_bytes' => $bytes,
            'schema_version' => $schema,
            'signing_key_id' => $keyId,

            // Deployment readiness: these four answer "does this build have the
            // material it needs?" without revealing any of that material.
            'key_provider' => $this->signatures->directoryClass(),
            'configured_key_count' => $this->signatures->keyCount(),
            'signing_key_store_populated' => $this->signatures->isOperational(),
            'requested_key_available' => null === $keyId ? null : $this->signatures->hasUsableKey($keyId, $now),

            'request_id' => $requestId,
            'elapsed_ms' => (int) round($elapsed * 1000),
        ]);
    }
}
