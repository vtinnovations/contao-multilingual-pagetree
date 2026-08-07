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

final class LegacyValueComparator
{
    public function equivalent(mixed $left, mixed $right): bool
    {
        return $this->normalize($left) === $this->normalize($right);
    }

    public function isAmbiguouslyEmpty(mixed $value): bool
    {
        $value = $this->normalize($value);

        return $value === null || $value === '' || $value === [];
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed === '0') {
            return 0;
        }
        if ($trimmed === '1') {
            return 1;
        }

        if ($this->looksSerialized($trimmed)) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($decoded !== false || $trimmed === 'b:0;') {
                return $this->normalizeRecursive($decoded);
            }
        }

        return $value;
    }

    private function normalizeRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeRecursive($item);
        }

        return $value;
    }

    private function looksSerialized(string $value): bool
    {
        return (bool) preg_match('/^(?:a|b|d|i|s|O|C|N):/', $value);
    }
}
