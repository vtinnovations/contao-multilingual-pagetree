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

namespace Vtinnovations\ContaoMultilingualPagetree\Packaging;

use Vtinnovations\ContaoMultilingualPagetree\Distribution\ProductProfile;
use Vtinnovations\ContaoMultilingualPagetree\Helper\CanonicalHost;
use Vtinnovations\ContaoMultilingualPagetree\Support\CanonicalInput;

/**
 * The parsed canonical document of a distributed package.
 *
 * Parsing is strict on purpose. Every field is type-checked, the schema version
 * is pinned, unknown top-level keys are refused and the lifetime/expiry rule is
 * enforced. Input that cannot be understood exactly as it was signed is rejected
 * instead of coerced, because a coerced value would produce a different
 * canonical signing input than the one that was actually signed. The same rule
 * governs the signed host set: it is validated as canonical, never sorted or
 * de-duplicated locally.
 *
 * The object never re-serialises itself for verification: the authoritative
 * bytes stay with {@see SealedPackage} and are what the digest tripwire and the
 * stored file are built from.
 */
final class PackageDocument
{
    /**
     * The top-level keys every schema-2 document must contain.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'schema_version',
        'project',
        'project_slug',
        'license_key',
        'license_domain',
        'license_package',
        'license_features',
        'license_version',
        'license_issued_at',
        'license_starts_at',
        'license_expires_at',
        'license_lifetime',
        'license_verified_at',
        'free_available',
        'signature',
        'validation_status',
    ];

    /**
     * The signed exact-host set and its reported allowance.
     *
     * The issuer added both to schema 2 without raising the schema version, so a
     * document that predates that change is still structurally valid. They are
     * therefore accepted as a pair - never one without the other - and a
     * document that carries neither is legacy state: readable, comparable and
     * usable as rollback material, but not entitlement-bearing until a refresh
     * has fetched the signed set.
     *
     * @var list<string>
     */
    public const BOUND_HOST_FIELDS = ['license_domains', 'license_max_domains'];

    /**
     * @param list<string>            $features
     * @param list<string>|null       $boundHosts the signed exact-host set, or null for legacy state
     * @param array<array-key, mixed> $raw        the decoded map exactly as it was signed
     */
    private function __construct(
        private readonly array $raw,
        public readonly int $schemaVersion,
        public readonly string $project,
        public readonly string $projectSlug,
        public readonly string $subscriptionKey,
        public readonly string $boundHost,
        public readonly ?array $boundHosts,
        public readonly ?int $boundHostAllowance,
        public readonly ServiceTier $tier,
        public readonly array $features,
        public readonly int $version,
        public readonly int $issuedAt,
        public readonly int $startsAt,
        public readonly ?int $expiresAt,
        public readonly bool $lifetime,
        public readonly int $verifiedAt,
        public readonly bool $freeAvailable,
        public readonly string $signature,
        public readonly DocumentStatus $status,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws PackageFormatException
     */
    public static function fromArray(array $data, CanonicalHost $hosts): self
    {
        if ([] !== array_diff(array_keys($data), self::FIELDS, self::BOUND_HOST_FIELDS)) {
            throw new PackageFormatException('The document contains unsupported fields.');
        }

        if ([] !== array_diff(self::FIELDS, array_keys($data))) {
            throw new PackageFormatException('The document is incomplete.');
        }

        $present = array_intersect(self::BOUND_HOST_FIELDS, array_keys($data));

        // Half of the pair is not a legacy document and not a current one. It
        // would leave the authorised set or its allowance to a local guess.
        if ([] !== $present && count($present) !== count(self::BOUND_HOST_FIELDS)) {
            throw new PackageFormatException('The document is incomplete.');
        }

        if (ProductProfile::SCHEMA_VERSION !== self::int($data, 'schema_version')) {
            throw new PackageFormatException('Unsupported document schema version.');
        }

        $project = self::string($data, 'project');
        $slug = self::string($data, 'project_slug');

        if (!hash_equals(ProductProfile::PROJECT, $project) || !hash_equals(ProductProfile::PROJECT_SLUG, $slug)) {
            throw new PackageFormatException('The document belongs to another product.');
        }

        // Representation is canonicalised, scope never is: a wildcard, an IP or
        // an unparsable host is refused rather than interpreted.
        $host = $hosts->normalize($data['license_domain'] ?? null);

        if (null === $host) {
            throw new PackageFormatException('The document has no usable exact host binding.');
        }

        $boundHosts = [] === $present ? null : self::boundHosts($data, $hosts);
        $allowance = [] === $present ? null : self::allowance($data);

        // The operation host is one member of the authorised set, never a
        // separate binding beside it.
        if (null !== $boundHosts && !in_array($host, $boundHosts, true)) {
            throw new PackageFormatException('The operation host is not part of the signed host set.');
        }

        $tier = ServiceTier::tryFromValue($data['license_package'] ?? null);

        if (null === $tier) {
            throw new PackageFormatException('Unknown tier.');
        }

        $status = DocumentStatus::tryFromValue($data['validation_status'] ?? null);

        if (null === $status) {
            throw new PackageFormatException('Unknown validation status.');
        }

        $version = self::int($data, 'license_version');
        $issuedAt = self::int($data, 'license_issued_at');
        $startsAt = self::int($data, 'license_starts_at');
        $verifiedAt = self::int($data, 'license_verified_at');
        $lifetime = self::bool($data, 'license_lifetime');
        $expiresAt = self::nullableInt($data, 'license_expires_at');

        if ($version < 1 || $issuedAt < 1 || $startsAt < 1 || $verifiedAt < 1) {
            throw new PackageFormatException('The document has invalid version or date values.');
        }

        // A non-lifetime entitlement without an expiry would never end; a
        // lifetime entitlement with an expiry contradicts itself. Both are
        // refused rather than resolved in the installation's favour.
        if ($lifetime && null !== $expiresAt) {
            throw new PackageFormatException('A lifetime document must not carry an expiry.');
        }

        if (!$lifetime && (null === $expiresAt || $expiresAt <= $startsAt)) {
            throw new PackageFormatException('A non-lifetime document needs an expiry after its start.');
        }

        return new self(
            $data,
            ProductProfile::SCHEMA_VERSION,
            $project,
            $slug,
            self::nonEmptyString($data, 'license_key'),
            $host,
            $boundHosts,
            $allowance,
            $tier,
            self::features($data),
            $version,
            $issuedAt,
            $startsAt,
            $expiresAt,
            $lifetime,
            $verifiedAt,
            self::bool($data, 'free_available'),
            self::nonEmptyString($data, 'signature'),
            $status,
        );
    }

    /**
     * The canonical signing input of this document.
     *
     * It is built from the decoded map exactly as it arrived - not from the
     * typed properties above - so the bytes are the ones the issuer signed even
     * where parsing normalised a representation. The document's own `signature`
     * field is excluded by the encoder.
     */
    public function signingInput(): string
    {
        return CanonicalInput::document($this->raw);
    }

    /**
     * Whether this document predates the signed exact-host set.
     *
     * Such a document is authentic - it passed every signature and the
     * exact-byte digest - but it does not say which hosts the issuer authorised
     * beyond the single operation host. Nothing local may fill that gap, so it
     * is kept as rollback material until a refresh delivers the signed set.
     */
    public function isLegacyHostBinding(): bool
    {
        return null === $this->boundHosts;
    }

    /**
     * Whether the issuer authorised exactly this host.
     *
     * Membership is exact. The set is never widened here: no apex or `www`
     * counterpart, no parent, child, sibling or nested subdomain, no alias and
     * no suffix rule. A reported allowance - including the instance-bound
     * `9999` - authorises nothing on its own.
     */
    public function authorises(string $host, CanonicalHost $hosts): bool
    {
        $candidate = $hosts->normalize($host);

        if (null === $candidate || null === $this->boundHosts) {
            return false;
        }

        foreach ($this->boundHosts as $bound) {
            if (hash_equals($bound, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The feature identifiers granted by the tier baseline and the signed list.
     *
     * @return list<string>
     */
    public function grantedFeatures(): array
    {
        $granted = $this->tier->baselineFeatures();

        foreach ($this->features as $feature) {
            if (!in_array($feature, $granted, true)) {
                $granted[] = $feature;
            }
        }

        return array_values($granted);
    }

    /**
     * The signed exact-host set, validated as it arrived.
     *
     * The list takes part in the canonical signing input, where array order is
     * preserved, so it is checked for canonical form rather than repaired: a
     * locally sorted, de-duplicated or normalised list would no longer be the
     * list that was signed. Every entry must already be exactly the canonical
     * form of a real hostname, entries must be unique, and the order must be
     * ascending bytewise.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function boundHosts(array $data, CanonicalHost $hosts): array
    {
        $domains = $data['license_domains'] ?? null;

        if (!is_array($domains) || !array_is_list($domains) || [] === $domains) {
            throw new PackageFormatException('The signed host set must be a non-empty JSON array.');
        }

        $result = [];
        $previous = null;

        foreach ($domains as $domain) {
            if (!is_string($domain) || $hosts->normalize($domain) !== $domain) {
                // Wildcards, ports, trailing dots, mixed case, IP literals and
                // anything unparsable land here. None of them is interpreted.
                throw new PackageFormatException('The signed host set contains an unusable host.');
            }

            if (null !== $previous && strcmp($previous, $domain) >= 0) {
                throw new PackageFormatException('The signed host set is not canonically ordered.');
            }

            $previous = $domain;
            $result[] = $domain;
        }

        return $result;
    }

    /**
     * The reported allowance.
     *
     * It is authenticated state, so it is type-checked, but it is deliberately
     * not enforced against the size of the set: the issuer keeps existing
     * bindings alive after lowering an allowance, and a local count guard would
     * take those installations dark. `9999` is the instance-bound report and is
     * not a wildcard.
     *
     * @param array<array-key, mixed> $data
     */
    private static function allowance(array $data): int
    {
        $allowance = self::int($data, 'license_max_domains');

        if ($allowance < 1) {
            throw new PackageFormatException('The signed host allowance must be positive.');
        }

        return $allowance;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function features(array $data): array
    {
        $features = $data['license_features'] ?? null;

        // `array_is_list()` rather than a range comparison: for an empty list
        // `range(0, -1)` yields `[0, -1]`, which would reject the perfectly
        // ordinary document that grants only its tier baseline.
        if (!is_array($features) || !array_is_list($features)) {
            throw new PackageFormatException('The feature list must be a JSON array.');
        }

        $result = [];

        foreach ($features as $feature) {
            if (!is_string($feature) || 1 !== preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $feature)) {
                throw new PackageFormatException('The feature list contains an unusable identifier.');
            }

            $result[] = $feature;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        // A float or a numeric string would canonicalise differently on the two
        // sides of the signature, so only a real integer is accepted.
        if (!is_int($value)) {
            throw new PackageFormatException(sprintf('Field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_int($value)) {
            throw new PackageFormatException(sprintf('Field "%s" must be an integer or null.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            throw new PackageFormatException(sprintf('Field "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value)) {
            throw new PackageFormatException(sprintf('Field "%s" must be a string.', $key));
        }

        // A line break would make the canonical signing input ambiguous.
        if (1 === preg_match('/[\r\n]/', $value)) {
            throw new PackageFormatException(sprintf('Field "%s" must not contain line breaks.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function nonEmptyString(array $data, string $key): string
    {
        $value = self::string($data, $key);

        if ('' === $value) {
            throw new PackageFormatException(sprintf('Field "%s" must not be empty.', $key));
        }

        return $value;
    }
}
