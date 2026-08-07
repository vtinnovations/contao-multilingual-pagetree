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

namespace Vtinnovations\ContaoMultilingualPagetree\Support;

/**
 * The two deterministic byte encoders every signature in this bundle is checked
 * against. Both are fixed by the exchange protocol; neither may be "improved"
 * locally, because the issuing side builds exactly the same bytes.
 *
 * {@see self::document()} and {@see self::envelope()} implement the JSON
 * profile: the top-level `signature` field is removed, object keys are sorted
 * bytewise at every depth, array order is preserved exactly, the result is
 * compact UTF-8 JSON without escaped slashes or escaped Unicode, and scalar
 * types survive untouched - `false` never becomes `"false"` and `null` never
 * becomes `0`.
 *
 * {@see self::request()} implements the updater profile: six lines joined with
 * a single `\n` and no trailing newline. The key id selects the key but is
 * deliberately absent from the signed lines.
 *
 * Both encoders run over the values exactly as they were decoded from the wire.
 * Nothing is re-typed, re-ordered by PHP array order or rebuilt from a parsed
 * object, because any of those could produce bytes the issuer never signed. The
 * exact byte strings are pinned by the vectors in
 * `tests/Support/CanonicalInputTest.php`.
 *
 * @internal this class is not a supported extension point
 */
final class CanonicalInput
{
    /** The field that is never part of what it protects. */
    private const SIGNATURE_FIELD = 'signature';

    /** Maximum nesting the encoder walks before refusing the value. */
    private const MAX_DEPTH = 16;

    private function __construct()
    {
    }

    /**
     * Canonical bytes of a licence document.
     *
     * @param array<array-key, mixed> $document as decoded from the exact payload bytes
     *
     * @throws \InvalidArgumentException when the value cannot be encoded deterministically
     */
    public static function document(array $document): string
    {
        return self::json($document);
    }

    /**
     * Canonical bytes of an integrity envelope.
     *
     * @param array<array-key, mixed> $envelope as decoded from the response
     *
     * @throws \InvalidArgumentException when the value cannot be encoded deterministically
     */
    public static function envelope(array $envelope): string
    {
        return self::json($envelope);
    }

    /**
     * Canonical bytes of an inbound updater request.
     *
     * @param string $bodySha256 lowercase hexadecimal SHA-256 of the exact raw body
     *
     * @throws \InvalidArgumentException when a component is unusable
     */
    public static function request(string $method, string $path, string $requestId, int $timestamp, string $nonce, string $bodySha256): string
    {
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('A canonical timestamp is never negative.');
        }

        if (1 !== preg_match('/^[0-9a-f]{64}$/', $bodySha256)) {
            throw new \InvalidArgumentException('A canonical body digest must be lowercase hexadecimal SHA-256.');
        }

        $lines = [
            strtoupper($method),
            $path,
            $requestId,
            (string) $timestamp,
            $nonce,
            $bodySha256,
        ];

        foreach ($lines as $line) {
            if ('' === $line || 1 === preg_match('/[\r\n]/', $line)) {
                throw new \InvalidArgumentException('A canonical request line is unusable.');
            }
        }

        // One newline between the lines, deliberately none at the end.
        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function json(array $data): string
    {
        unset($data[self::SIGNATURE_FIELD]);

        try {
            $encoded = json_encode(
                self::normalise($data, 0),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The canonical form could not be encoded.', 0, $exception);
        }

        if ('' === $encoded) {
            throw new \InvalidArgumentException('The canonical form is empty.');
        }

        return $encoded;
    }

    /**
     * Sorts every object key bytewise and leaves every list position alone.
     *
     * A float would serialise differently across runtimes, so it is refused
     * rather than rounded into something the issuer never signed.
     */
    private static function normalise(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('The canonical value nests too deeply.');
        }

        if (is_float($value)) {
            throw new \InvalidArgumentException('A canonical value must not be a floating-point number.');
        }

        if (!is_array($value)) {
            return $value;
        }

        // A list keeps its order; only maps are sorted. json_encode then emits
        // the list as a JSON array and the map as a JSON object, as decoded.
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalise($item, $depth + 1), $value);
        }

        $normalised = [];

        foreach ($value as $key => $item) {
            $normalised[(string) $key] = $item;
        }

        // Bytewise ascending, independent of locale and of insertion order.
        uksort($normalised, static fn (string $left, string $right): int => strcmp($left, $right));

        foreach ($normalised as $key => $item) {
            $normalised[$key] = self::normalise($item, $depth + 1);
        }

        // Cast so an object with numeric-looking keys still encodes as a JSON
        // object rather than collapsing into an array.
        return (object) $normalised;
    }
}
