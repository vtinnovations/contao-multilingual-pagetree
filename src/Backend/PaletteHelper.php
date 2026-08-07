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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

/** Safely adds and removes optional DCA palette fields without empty legends. */
final class PaletteHelper
{
    /** @param list<string> $fields */
    public static function addToPalette(string $palette, string $legend, array $fields): string
    {
        $fields = self::normaliseFields($fields);
        if ([] === $fields) {
            return self::removeFields($palette, []);
        }
        $segments = self::segments(self::removeFields($palette, $fields));
        $legendToken = '{'.$legend.'}';

        foreach ($segments as &$segment) {
            [$token, $existing] = self::splitSegment($segment);
            if ($token !== $legendToken) {
                continue;
            }
            $segment = $token.','.implode(',', self::normaliseFields([...$existing, ...$fields]));

            return implode(';', $segments);
        }
        unset($segment);

        array_unshift($segments, $legendToken.','.implode(',', $fields));

        return implode(';', $segments);
    }

    /**
     * Insert a complete legend before the first matching legend, and never lose
     * it when no anchor exists.
     *
     * Anchors are tried in order. An anchor that would place the legend first is
     * skipped, because the section must never become the opening section of the
     * form. When no usable anchor is found at all, the legend is appended as the
     * last section instead of being dropped silently - an invisible section is
     * indistinguishable from a broken integration.
     *
     * @param list<string> $fields
     * @param list<string> $beforeLegends
     */
    public static function insertLegend(string $palette, string $legend, array $fields, array $beforeLegends): string
    {
        $fields = self::normaliseFields($fields);
        $palette = self::removeFields($palette, $fields);

        if ([] === $fields) {
            return $palette;
        }

        $segments = self::segments($palette);
        $section = '{'.$legend.'},'.implode(',', $fields);

        if ([] === $segments) {
            return $section;
        }

        foreach (self::normaliseFields($beforeLegends) as $anchor) {
            foreach ($segments as $index => $segment) {
                [$token] = self::splitSegment($segment);

                if (1 !== preg_match('/^\{([^}:]+)(?::[^}]*)?\}$/', $token, $matches) || $matches[1] !== $anchor) {
                    continue;
                }

                // Position 0 would make the licence section the first thing an
                // editor sees; the next anchor is tried instead.
                if (0 === $index) {
                    continue 2;
                }

                array_splice($segments, $index, 0, [$section]);

                return implode(';', $segments);
            }
        }

        $segments[] = $section;

        return implode(';', $segments);
    }

    /** @param list<string> $fields */
    public static function removeFields(string $palette, array $fields): string
    {
        $remove = array_fill_keys(self::normaliseFields($fields), true);
        $result = [];

        foreach (self::segments($palette) as $segment) {
            [$legend, $existing] = self::splitSegment($segment);
            $remaining = array_values(array_filter($existing, static fn (string $field): bool => !isset($remove[$field])));

            if ('' !== $legend) {
                if ([] !== $remaining) {
                    $result[] = $legend.','.implode(',', $remaining);
                }
            } elseif ([] !== $remaining) {
                $result[] = implode(',', $remaining);
            }
        }

        return implode(';', $result);
    }

    /** @param list<string> $fields */
    public static function removeFromTable(string $table, array $fields): void
    {
        foreach ($GLOBALS['TL_DCA'][$table]['palettes'] ?? [] as $name => $palette) {
            if ('__selector__' !== $name && is_string($palette)) {
                $GLOBALS['TL_DCA'][$table]['palettes'][$name] = self::removeFields($palette, $fields);
            }
        }
    }

    /** @param list<string> $fields */
    public static function addToTable(string $table, string $legend, array $fields, ?string $paletteName = null): void
    {
        foreach ($GLOBALS['TL_DCA'][$table]['palettes'] ?? [] as $name => $palette) {
            if ('__selector__' === $name || !is_string($palette) || (null !== $paletteName && $name !== $paletteName)) {
                continue;
            }
            $GLOBALS['TL_DCA'][$table]['palettes'][$name] = self::addToPalette($palette, $legend, $fields);
        }
    }

    /** @return list<string> */
    private static function segments(string $palette): array
    {
        return array_values(array_filter(array_map('trim', explode(';', trim($palette, ';'))), static fn (string $value): bool => '' !== $value));
    }

    /** @return array{string, list<string>} */
    private static function splitSegment(string $segment): array
    {
        if (preg_match('/^(\{[^}]+\})(?:,(.*))?$/', $segment, $matches)) {
            return [$matches[1], self::normaliseFields(explode(',', $matches[2] ?? ''))];
        }

        return ['', self::normaliseFields(explode(',', $segment))];
    }

    /** @param list<string> $fields
     *  @return list<string>
     */
    private static function normaliseFields(array $fields): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $fields), static fn (string $field): bool => '' !== $field)));
    }
}
