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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageDocument;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageReader;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\PackageSeal;
use Vtinnovations\ContaoMultilingualPagetree\Packaging\SealedPackage;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Support\CanonicalInput;
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;
use Vtinnovations\ContaoMultilingualPagetree\Support\KeyDirectory;
use Vtinnovations\ContaoMultilingualPagetree\Support\SignatureScheme;
use Vtinnovations\ContaoMultilingualPagetree\Support\VerificationKey;

/**
 * Builds genuinely signed packages for the test suite.
 *
 * The key pair is generated per instance and exists only in memory: no product
 * key material is used, invented or committed anywhere. Everything the suite
 * verifies therefore runs through the real chain - seal signature, exact-byte
 * digest, document signature - rather than a stub.
 */
final class PackageFactory
{
    public const KEY_ID = 'test-key-a';
    public const HOST = 'example.com';

    /** A second bound host of the same licence; a distinct identity, not a scope. */
    public const SECOND_HOST = 'staging.example.com';

    public const NOW = 1784880547;

    private readonly string $secretKey;
    private readonly string $publicKeyBase64;

    public function __construct(private readonly string $keyId = self::KEY_ID)
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_sign_keypair');
    }

    public function keyDirectory(?int $retiredAt = null, int $window = VerificationKey::DEFAULT_WINDOW): KeyDirectory
    {
        $key = VerificationKey::create($this->keyId, SignatureScheme::Ed25519, $this->publicKeyBase64, $retiredAt, $window);

        return new KeyDirectory(null === $key ? [] : [$key]);
    }

    public function signatures(?KeyDirectory $keys = null): DetachedSignature
    {
        return new DetachedSignature($keys ?? $this->keyDirectory());
    }

    public function reader(?KeyDirectory $keys = null): PackageReader
    {
        return new PackageReader($this->signatures($keys), new CanonicalHost());
    }

    /** Base64 detached signature over the given canonical input. */
    public function sign(string $canonicalInput): string
    {
        return base64_encode(sodium_crypto_sign_detached($canonicalInput, $this->secretKey));
    }

    /**
     * The default document fields; override any of them per test.
     *
     * @return array<string, mixed>
     */
    public static function documentFields(): array
    {
        return [
            'schema_version' => ProductProfile::SCHEMA_VERSION,
            'project' => ProductProfile::PROJECT,
            'project_slug' => ProductProfile::PROJECT_SLUG,
            'license_key' => 'CMP-TEST-0000-0000',
            'license_domain' => self::HOST,
            // Canonical, sorted and unique, exactly as the issuer signs it. The
            // second entry is a different identity, not a wider one.
            'license_domains' => [self::HOST, self::SECOND_HOST],
            'license_max_domains' => 3,
            'license_package' => 'pro',
            'license_features' => [],
            'license_version' => 7,
            'license_issued_at' => self::NOW - 86400,
            'license_starts_at' => self::NOW - 86400,
            'license_expires_at' => self::NOW + 2592000,
            'license_lifetime' => false,
            'license_verified_at' => self::NOW,
            'free_available' => true,
            'signature' => 'placeholder',
            'validation_status' => 'valid',
        ];
    }

    /**
     * The exact bytes of a signed document.
     *
     * @param array<string, mixed> $overrides
     */
    public function documentBytes(array $overrides = []): string
    {
        $fields = array_merge(self::documentFields(), $overrides);
        $fields['signature'] = 'placeholder';

        // A test that binds the document to another host gets the matching
        // signed set unless it states one itself, so overriding the operation
        // host still produces the document the issuer would have signed.
        if (!array_key_exists('license_domains', $overrides) && is_array($fields['license_domains'] ?? null)) {
            $host = (new CanonicalHost())->normalize($fields['license_domain'] ?? null);

            if (null !== $host && !in_array($host, $fields['license_domains'], true)) {
                $fields['license_domains'] = [$host];
            }
        }

        return $this->signDocument($fields);
    }

    /**
     * The exact bytes of a signed document as it looked before the issuer added
     * the signed host set - authentic, but without that pair.
     *
     * @param array<string, mixed> $overrides
     */
    public function legacyDocumentBytes(array $overrides = []): string
    {
        $fields = array_merge(self::documentFields(), $overrides);

        foreach (PackageDocument::BOUND_HOST_FIELDS as $field) {
            unset($fields[$field]);
        }

        return $this->signDocument($fields);
    }

    /**
     * A complete wire package built from a pre-upgrade document.
     *
     * @param array<string, mixed> $documentOverrides
     *
     * @return array{payload: string, integrity: array<string, mixed>, bytes: string}
     */
    public function legacyWirePackage(array $documentOverrides = []): array
    {
        $bytes = $this->legacyDocumentBytes($documentOverrides);

        return [
            'payload' => base64_encode($bytes),
            'integrity' => $this->seal($bytes),
            'bytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function signDocument(array $fields): string
    {
        $fields['signature'] = 'placeholder';
        $document = PackageDocument::fromArray($fields, new CanonicalHost());
        $fields['signature'] = $this->sign($document->signingInput());

        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A signed seal for the given bytes.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function seal(string $bytes, array $overrides = []): array
    {
        $decoded = json_decode($bytes, true);

        $seal = array_merge([
            'project' => ProductProfile::PROJECT,
            'project_slug' => ProductProfile::PROJECT_SLUG,
            'license_version' => is_array($decoded) ? ($decoded['license_version'] ?? 7) : 7,
            'license_md5' => md5($bytes),
            'generated_at' => self::NOW,
            'key_id' => $this->keyId,
            'signature_algorithm' => 'ed25519',
        ], $overrides);

        $seal['signature'] = 'placeholder';
        $seal['signature'] = $this->sign(PackageSeal::fromArray($seal)->signingInput());

        return $seal;
    }

    /**
     * A complete wire package: Base64 payload plus signed seal.
     *
     * @param array<string, mixed> $documentOverrides
     * @param array<string, mixed> $sealOverrides
     *
     * @return array{payload: string, integrity: array<string, mixed>, bytes: string}
     */
    public function wirePackage(array $documentOverrides = [], array $sealOverrides = []): array
    {
        $bytes = $this->documentBytes($documentOverrides);

        return [
            'payload' => base64_encode($bytes),
            'integrity' => $this->seal($bytes, $sealOverrides),
            'bytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $documentOverrides
     * @param array<string, mixed> $sealOverrides
     */
    public function sealedPackage(array $documentOverrides = [], array $sealOverrides = [], int $now = self::NOW): SealedPackage
    {
        $package = $this->wirePackage($documentOverrides, $sealOverrides);

        return $this->reader()->readPackage($package['payload'], $package['integrity'], $now);
    }

    /**
     * Verified state that predates the signed host set, as an installation
     * upgraded from an earlier release would hold it.
     *
     * @param array<string, mixed> $documentOverrides
     */
    public function legacySealedPackage(array $documentOverrides = [], int $now = self::NOW): SealedPackage
    {
        $package = $this->legacyWirePackage($documentOverrides);

        return $this->reader()->readPackage($package['payload'], $package['integrity'], $now);
    }

    /** An identity bound to a live request on the given host. */
    public function identity(PackageStoreInterface $store, ?string $requestHost = self::HOST): InstallationIdentity
    {
        $stack = new RequestStack();

        if (null !== $requestHost) {
            $stack->push(Request::create('https://'.$requestHost.'/'));
        }

        return new InstallationIdentity(new CanonicalHost(), $store, $stack);
    }

    /**
     * A policy over a store that already holds the given package.
     *
     * @param array<string, mixed> $documentOverrides
     */
    public function policy(array $documentOverrides = [], ?string $requestHost = self::HOST, int $now = self::NOW): CapabilityPolicy
    {
        $store = new InMemoryPackageStore();
        $store->package = $this->sealedPackage($documentOverrides, [], $now);
        $store->host = $store->package->document->boundHost;

        return new CapabilityPolicy($store, $this->identity($store, $requestHost), new CanonicalHost(), new FrozenClock($now));
    }

    /** A policy that grants the full paid tier; for tests of unrelated features. */
    public static function grantingPolicy(): CapabilityPolicy
    {
        return (new self())->policy();
    }

    /** A policy over an installation that has never been activated. */
    public static function deniedPolicy(): CapabilityPolicy
    {
        $factory = new self();
        $store = new InMemoryPackageStore();

        return new CapabilityPolicy($store, $factory->identity($store), new CanonicalHost(), new FrozenClock());
    }

    /**
     * The five signed headers for an inbound request over the given raw body.
     *
     * @return array<string, string>
     */
    public function requestHeaders(string $method, string $path, string $rawBody, int $timestamp = self::NOW, string $requestId = 'req-00000001', string $nonce = 'nonce-000000000001'): array
    {
        $canonical = CanonicalInput::request($method, $path, $requestId, $timestamp, $nonce, hash('sha256', $rawBody));

        return [
            'X-VT-Request-ID' => $requestId,
            'X-VT-Timestamp' => (string) $timestamp,
            'X-VT-Nonce' => $nonce,
            'X-VT-Key-ID' => $this->keyId,
            'X-VT-Signature' => $this->sign($canonical),
        ];
    }

    /**
     * A complete inbound update body.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function updateBody(array $overrides = [], array $documentOverrides = []): array
    {
        $package = $this->wirePackage($documentOverrides);

        return array_merge([
            'action' => 'license_update',
            'project' => ProductProfile::PROJECT,
            'project_slug' => ProductProfile::PROJECT_SLUG,
            'product_id' => ProductProfile::PRODUCT_ID,
            'domain' => self::HOST,
            'request_id' => 'req-00000001',
            'timestamp' => self::NOW,
            'nonce' => 'nonce-000000000001',
            'license_payload_b64' => $package['payload'],
            'integrity' => $package['integrity'],
        ], $overrides);
    }
}
