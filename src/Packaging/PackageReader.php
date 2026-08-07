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
use Vtinnovations\ContaoMultilingualPagetree\Support\DetachedSignature;

/**
 * Turns a wire package - Base64 payload plus signed seal - into a verified
 * {@see SealedPackage}, in one fixed order.
 *
 * The order is the heart of the trust model:
 *
 *  1. the seal must be well formed;
 *  2. the seal signature must verify, because only then does its digest mean
 *     anything at all;
 *  3. the payload must decode from strict, canonical Base64;
 *  4. the digest of the **exact decoded bytes** must equal the signed digest, in
 *     a constant-time comparison;
 *  5. only then are those same bytes parsed as JSON - never re-serialised, so
 *     the digest keeps covering exactly what is stored;
 *  6. the document must satisfy the schema, product, date and lifetime rules;
 *  7. the document signature must verify over its own canonical input;
 *  8. seal and document must agree on the version.
 *
 * Step 4 uses MD5 because the protocol specifies it as the exact-byte tripwire.
 * It is never treated as proof of authenticity: that is what steps 2 and 7 are
 * for.
 */
final class PackageReader
{
    public function __construct(
        private readonly DetachedSignature $signatures,
        private readonly CanonicalHost $hosts,
    ) {
    }

    /**
     * @param array<array-key, mixed>|mixed $seal
     *
     * @throws PackageFormatException
     */
    public function readPackage(mixed $payloadBase64, mixed $seal, int $now): SealedPackage
    {
        if (!is_array($seal)) {
            throw new PackageFormatException('The package has no seal.');
        }

        $verifiedSeal = $this->verifiedSeal(PackageSeal::fromArray($seal), $now);

        return $this->fromVerifiedSeal($this->decodePayload($payloadBase64), $verifiedSeal, $now);
    }

    /**
     * Re-validates stored state with exactly the same chain that accepted it, so
     * tampering with either part after activation is detected on the next read.
     *
     * @throws PackageFormatException
     */
    public function readStored(string $bytes, string $sealJson, int $now): SealedPackage
    {
        $seal = $this->verifiedSeal(PackageSeal::fromArray($this->decodeJson($sealJson)), $now);

        return $this->fromVerifiedSeal($bytes, $seal, $now);
    }

    /**
     * @throws PackageFormatException
     */
    private function verifiedSeal(PackageSeal $seal, int $now): PackageSeal
    {
        if (!$this->signatures->hasUsableKey($seal->keyId, $now)) {
            throw new PackageFormatException('Unknown signing key.');
        }

        if (!$this->signatures->verify($seal->signingInput(), $seal->signature, $seal->keyId, $seal->scheme, $now)) {
            throw new PackageFormatException('The seal signature is not valid.');
        }

        return $seal;
    }

    /**
     * @throws PackageFormatException
     */
    private function fromVerifiedSeal(string $bytes, PackageSeal $seal, int $now): SealedPackage
    {
        // Constant-time comparison over the exact bytes. Re-serialising the JSON
        // first would let an attacker change insignificant whitespace and still
        // produce a matching digest.
        if (!hash_equals($seal->digest, md5($bytes))) {
            throw new PackageFormatException('The document bytes do not match the signed digest.');
        }

        $document = PackageDocument::fromArray($this->decodeJson($bytes), $this->hosts);

        // The document does not name its signing key, so every currently usable
        // pinned key is tried. Only pinned keys are ever candidates.
        if (!$this->signatures->verifyWithAnyKey($document->signingInput(), $document->signature, $now)) {
            throw new PackageFormatException('The document signature is not valid.');
        }

        // A signed seal and a signed document that disagree would leave it open
        // which version is authoritative, so the pair is refused.
        if ($seal->documentVersion !== $document->version) {
            throw new PackageFormatException('Seal and document disagree about the version.');
        }

        return new SealedPackage($bytes, $seal, $document);
    }

    /**
     * Strict, canonical Base64 only. Whitespace, missing padding and other
     * alternative encodings of the same bytes are refused, because they would
     * make "the exact bytes" ambiguous.
     *
     * @throws PackageFormatException
     */
    private function decodePayload(mixed $payloadBase64): string
    {
        if (!is_string($payloadBase64) || '' === $payloadBase64) {
            throw new PackageFormatException('The package carries no payload.');
        }

        if (strlen($payloadBase64) > 4 * (int) ceil(ProductProfile::MAX_RESPONSE_BYTES / 3)) {
            throw new PackageFormatException('The payload is too large.');
        }

        $bytes = base64_decode($payloadBase64, true);

        if (false === $bytes || '' === $bytes || base64_encode($bytes) !== $payloadBase64) {
            throw new PackageFormatException('The payload is not canonical Base64.');
        }

        return $bytes;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws PackageFormatException
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PackageFormatException('The data is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new PackageFormatException('The data is not a JSON object.');
        }

        return $decoded;
    }
}
