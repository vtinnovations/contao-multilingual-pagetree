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

namespace Vtinnovations\ContaoMultilingualPagetree\Detail;

final class SafeQueryParameters
{
    private const DENIED = [
        'language', 'auto_item', 'items', 'event', 'faq',
        'rt', 'token', 'request_token', 'csrf_token', 'phpsessid',
        'contao_preview', 'preview', 'redirect', 'redirect_url',
    ];

    public function filter(array $query): array
    {
        $safe = [];
        foreach ($query as $name => $value) {
            $normalized = strtolower((string) $name);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', (string) $name)
                || str_starts_with($normalized, '_')
                || str_contains($normalized, 'session')
                || str_contains($normalized, 'csrf')
                || in_array($normalized, self::DENIED, true)) {
                continue;
            }
            if ($this->isSafeValue($value)) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    private function isSafeValue(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isSafeValue($item)) {
                return false;
            }
        }

        return true;
    }
}
