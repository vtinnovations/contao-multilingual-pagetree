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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

final class FieldStateMap
{
    public const INHERIT = 'inherit';
    public const CUSTOM = 'custom';
    public const EMPTY = 'empty';

    private const VALID_STATES = [self::INHERIT, self::CUSTOM, self::EMPTY];

    public function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalize($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $this->normalize($decoded) : [];
    }

    public function encode(array $states): string
    {
        return json_encode($this->normalize($states), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function state(mixed $map, string $field): string
    {
        return $this->decode($map)[$field] ?? self::INHERIT;
    }

    public function normalize(array $states, ?array $allowedFields = null): array
    {
        $normalized = [];
        foreach ($states as $field => $state) {
            if (!is_string($field) || ($allowedFields !== null && !in_array($field, $allowedFields, true))) {
                continue;
            }
            $normalized[$field] = is_string($state) && in_array($state, self::VALID_STATES, true)
                ? $state
                : self::INHERIT;
        }

        ksort($normalized);

        return $normalized;
    }

    public function withDefaults(array $fields): array
    {
        return array_fill_keys($fields, self::INHERIT);
    }
}
