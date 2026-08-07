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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

use Contao\StringUtil;

/**
 * Turns a source value into a canonical, type-preserving representation.
 *
 * The representation is what fingerprints and reviewed snapshots are built
 * from, so it must be deterministic and must keep semantically different
 * values apart: 0, "0", false, null, [] and "" all normalise differently.
 */
final class CanonicalValueNormalizer
{
    private const MAX_DEPTH = 12;

    /**
     * @param string|null $valueType The declared policy type of the field
     *
     * @return array{t: string, v?: mixed}
     */
    public function normalize(mixed $value, ?string $valueType = null, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['t' => 'truncated'];
        }

        if ('headline' === $valueType) {
            return $this->normalizeHeadline($value, $depth);
        }

        if (null === $value) {
            return ['t' => 'null'];
        }

        if (is_bool($value)) {
            return ['t' => 'bool', 'v' => $value];
        }

        if (is_int($value)) {
            return ['t' => 'int', 'v' => $value];
        }

        if (is_float($value)) {
            // Keep a stable textual representation instead of a locale/precision
            // dependent float literal.
            return ['t' => 'float', 'v' => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.')];
        }

        if (is_array($value)) {
            return ['t' => 'array', 'v' => $this->normalizeArray($value, $depth)];
        }

        if (is_object($value)) {
            return ['t' => 'array', 'v' => $this->normalizeArray(get_object_vars($value), $depth)];
        }

        if (is_string($value)) {
            $deserialized = $this->deserialize($value);

            if (is_array($deserialized)) {
                // An equivalent serialised array must fingerprint like the array.
                return ['t' => 'array', 'v' => $this->normalizeArray($deserialized, $depth)];
            }

            return ['t' => 'string', 'v' => $this->normalizeText($value)];
        }

        return ['t' => 'unknown'];
    }

    /**
     * Normalises line endings so a rich-text editor rewriting CRLF does not
     * look like an editorial change.
     */
    public function normalizeText(string $value): string
    {
        $value = (string) preg_replace("/\r\n?/", "\n", $value);

        return rtrim($value, "\n");
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value, int $depth): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalize($item, null, $depth + 1);
        }

        // Key order must never influence the fingerprint.
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Contao headline values are either a plain string or a value/unit map.
     *
     * @return array{t: string, v: array<string, mixed>}
     */
    private function normalizeHeadline(mixed $value, int $depth): array
    {
        $headline = $value;

        if (is_string($value)) {
            $deserialized = $this->deserialize($value);
            $headline = is_array($deserialized) ? $deserialized : ['value' => $value, 'unit' => ''];
        }

        if (!is_array($headline)) {
            $headline = ['value' => null === $headline ? '' : $headline, 'unit' => ''];
        }

        return [
            't' => 'headline',
            'v' => [
                'unit' => $this->normalize($headline['unit'] ?? '', null, $depth + 1),
                'value' => $this->normalize($headline['value'] ?? '', null, $depth + 1),
            ],
        ];
    }

    private function deserialize(string $value): mixed
    {
        try {
            return StringUtil::deserialize($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
