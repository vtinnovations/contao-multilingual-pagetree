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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

use Contao\StringUtil;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

/**
 * Derives, without any editor-facing control, whether a submitted content value
 * is a real translation, an untouched fallback, or a deliberate blank.
 *
 * The additional-language form shows the source value when no translation
 * exists yet, so a plain "the field was submitted" test would silently copy the
 * source language into the translation on the first save. Comparing the
 * submission against the source instead keeps the three states the bundle
 * already stores in `fieldStates`, and keeps them invisible:
 *
 *  - identical to the source        -> inherit  (nothing is claimed; later
 *                                      source edits keep flowing through)
 *  - blank while the source is not  -> empty    (a deliberate blank)
 *  - anything else                  -> custom   (a real translation)
 *
 * No new storage format is introduced and no per-field selector is rendered.
 */
final class ContentValueProvenance
{
    public function __construct(private readonly FieldStateMap $states)
    {
    }

    /**
     * The field-state map for a submission.
     *
     * @param array<string, mixed> $submitted    approved translated values only
     * @param array<string, mixed> $source       the source content row
     * @param mixed                $existingMap  the currently stored fieldStates
     * @param list<string>         $fields       the approved translatable fields
     *
     * @return array<string, string>
     */
    public function derive(array $submitted, array $source, mixed $existingMap, array $fields): array
    {
        $map = $this->states->decode($existingMap);

        foreach ($fields as $field) {
            if (!array_key_exists($field, $submitted)) {
                // Not part of this submission - the stored state is untouched.
                continue;
            }

            $map[$field] = $this->state($submitted[$field], $source[$field] ?? null);
        }

        return $this->states->normalize($map, $fields);
    }

    public function state(mixed $submitted, mixed $source): string
    {
        if ($this->equals($submitted, $source)) {
            return FieldStateMap::INHERIT;
        }

        if ($this->isBlank($submitted)) {
            // A blank submission is only a deliberate blank when the source
            // actually had something to blank out; otherwise both are empty and
            // the comparison above already returned "inherit".
            return FieldStateMap::EMPTY;
        }

        return FieldStateMap::CUSTOM;
    }

    /**
     * Value equality across the shapes Contao stores a field in: a widget may
     * hand back an array where the column holds a serialised string, and a
     * numeric column may arrive as a string.
     */
    public function equals(mixed $left, mixed $right): bool
    {
        $left = $this->canonical($left);
        $right = $this->canonical($right);

        if (is_array($left) && is_array($right)) {
            return $this->canonicalJson($left) === $this->canonicalJson($right);
        }

        if (is_array($left) || is_array($right)) {
            return false;
        }

        return (string) $left === (string) $right;
    }

    public function isBlank(mixed $value): bool
    {
        $value = $this->canonical($value);

        if (is_array($value)) {
            return [] === array_filter(
                $value,
                fn (mixed $entry): bool => !$this->isBlank($entry),
            );
        }

        return null === $value || '' === trim((string) $value);
    }

    /** Serialised arrays and scalars are compared in one shape. */
    private function canonical(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (null === $value || is_scalar($value)) {
            if (is_string($value)) {
                $deserialized = StringUtil::deserialize($value);

                if (is_array($deserialized)) {
                    return $deserialized;
                }
            }

            return $value;
        }

        return is_array($value) ? $value : (string) $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        $normalise = static function (array $input) use (&$normalise): array {
            ksort($input);

            foreach ($input as $key => $entry) {
                if (is_array($entry)) {
                    $input[$key] = $normalise($entry);
                }
            }

            return $input;
        };

        try {
            return json_encode($normalise($value), JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return serialize($value);
        }
    }
}
