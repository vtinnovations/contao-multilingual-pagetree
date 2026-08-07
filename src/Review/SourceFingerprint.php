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

/**
 * A deterministic fingerprint of the reviewable state of one source record.
 *
 * The hash covers only explicitly translatable fields of the point 7 policy;
 * structural, technical and publication fields never participate.
 */
final class SourceFingerprint
{
    /**
     * @param array<string, array<string, mixed>> $values Canonical field => value map
     * @param list<string>                        $fields Fields the fingerprint covers
     */
    private function __construct(
        public readonly string $hash,
        public readonly array $values,
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    public static function create(string $hash, array $values): self
    {
        return new self($hash, $values, array_keys($values));
    }

    public static function empty(): self
    {
        return new self('', [], []);
    }

    public function equalsHash(?string $hash): bool
    {
        if ('' === $this->hash || null === $hash || '' === $hash) {
            return false;
        }

        return hash_equals($this->hash, $hash);
    }

    public function isEmpty(): bool
    {
        return '' === $this->hash;
    }

    /**
     * The canonical snapshot persisted as the reviewed baseline.
     */
    public function toJson(): string
    {
        try {
            return json_encode(
                $this->values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (\Throwable) {
            return '{}';
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function decodeSnapshot(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (!is_string($json) || '' === trim($json)) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
