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
use Vtinnovations\ContaoMultilingualPagetree\Support\CanonicalInput;

/**
 * The signed seal that belongs to one exact set of document bytes.
 *
 * The seal is what makes the byte digest meaningful: the digest is trusted only
 * because the seal carrying it is signed. Editing the stored document by hand
 * breaks the digest; editing the digest as well breaks the signature. The digest
 * itself is an exact-byte tripwire, never proof of authenticity.
 */
final class PackageSeal
{
    /** @var list<string> */
    public const FIELDS = [
        'project',
        'project_slug',
        'license_version',
        'license_md5',
        'generated_at',
        'key_id',
        'signature_algorithm',
        'signature',
    ];

    /**
     * @param array<array-key, mixed> $raw the decoded envelope exactly as it was signed
     */
    private function __construct(
        private readonly array $raw,
        public readonly string $project,
        public readonly string $projectSlug,
        public readonly int $documentVersion,
        public readonly string $digest,
        public readonly int $generatedAt,
        public readonly string $keyId,
        public readonly string $scheme,
        public readonly string $signature,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws PackageFormatException
     */
    public static function fromArray(array $data): self
    {
        if ([] !== array_diff(array_keys($data), self::FIELDS) || [] !== array_diff(self::FIELDS, array_keys($data))) {
            throw new PackageFormatException('The seal has an unexpected shape.');
        }

        $project = self::string($data, 'project');
        $slug = self::string($data, 'project_slug');

        if (!hash_equals(ProductProfile::PROJECT, $project) || !hash_equals(ProductProfile::PROJECT_SLUG, $slug)) {
            throw new PackageFormatException('The seal belongs to another product.');
        }

        $version = $data['license_version'] ?? null;
        $generatedAt = $data['generated_at'] ?? null;

        if (!is_int($version) || $version < 1 || !is_int($generatedAt) || $generatedAt < 1) {
            throw new PackageFormatException('The seal has invalid version or generation data.');
        }

        $digest = self::string($data, 'license_md5');

        if (1 !== preg_match('/^[0-9a-f]{32}$/', $digest)) {
            throw new PackageFormatException('The seal has no usable digest.');
        }

        $keyId = self::string($data, 'key_id');

        if (1 !== preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            throw new PackageFormatException('The seal has no usable key id.');
        }

        return new self(
            $data,
            $project,
            $slug,
            $version,
            $digest,
            $generatedAt,
            $keyId,
            self::string($data, 'signature_algorithm'),
            self::string($data, 'signature'),
        );
    }

    /**
     * The canonical signing input of this seal.
     *
     * The digest is bound to the product, the document version, the generation
     * time and the key that signed it, so a seal cannot be lifted from one
     * version and reattached to another.
     */
    public function signingInput(): string
    {
        return CanonicalInput::envelope($this->raw);
    }

    /**
     * The seal as it is written next to the document bytes, in the exact field
     * order of its canonical signing input.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'project_slug' => $this->projectSlug,
            'license_version' => $this->documentVersion,
            'license_md5' => $this->digest,
            'generated_at' => $this->generatedAt,
            'key_id' => $this->keyId,
            'signature_algorithm' => $this->scheme,
            'signature' => $this->signature,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || '' === $value || 1 === preg_match('/[\r\n]/', $value)) {
            throw new PackageFormatException(sprintf('Seal field "%s" is unusable.', $key));
        }

        return $value;
    }
}
