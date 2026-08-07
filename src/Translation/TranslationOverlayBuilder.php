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

use Contao\StringUtil;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;

/**
 * Central pre-render translation overlay.
 *
 * The builder turns a source record plus its translation record into the row
 * that Contao's own renderer should see. It never renders, never produces HTML,
 * never persists anything and never mutates the source record.
 *
 * Applying the resulting row to the record Contao is about to render is the
 * responsibility of {@see ScopedModelOverlay}.
 */
final class TranslationOverlayBuilder
{
    private const MEDIA_METADATA_FIELDS = ['alt', 'caption', 'imageTitle', 'titleText', 'url'];

    public function __construct(
        private readonly TranslationOverlayResolver $resolver,
        private readonly TranslationFieldRegistry $fields,
    ) {
    }

    /**
     * Returns the complete rendering row for $source with the field-state-aware
     * overlay of $translation applied.
     *
     * Without a translation record the untouched source row is returned, which
     * makes the caller fall back to Contao's normal rendering data.
     *
     * `$languageMode` is the target language's configured rule. It decides what
     * an untranslated field renders: the source value under the fallback rule,
     * nothing under the strict rule. It is never a per-field decision.
     *
     * @return array<string, mixed>
     */
    public function buildRow(
        object|array $source,
        object|array|null $translation,
        string $translationTable,
        ?string $language = null,
        ?PageAvailabilityMode $languageMode = null,
    ): array {
        $row = $this->readRow($source);

        if ($translation === null) {
            return $row;
        }

        $translationRow = $this->readRow($translation);
        $resolved = [];

        $contentType = 'tl_content_translation' === $translationTable ? (string) ($row['type'] ?? '') : null;
        foreach ($this->fields->fieldNames($translationTable, array_keys($translationRow), $contentType) as $field) {
            // Field-state resolution (inherit/custom/empty) stays in the point 2 resolver.
            $resolved[$field] = $this->resolver->resolveField($source, $translation, $field, $translationTable, $languageMode);
        }

        foreach ($resolved as $field => $value) {
            // Structural fields the source row does not know are only introduced
            // when the translation really carries a value for them. No truthiness
            // check: "0", '' and false are legitimate translated values.
            if (array_key_exists($field, $row) || null !== $value) {
                $row[$field] = $value;
            }
        }

        if ('tl_content_translation' === $translationTable) {
            $row = $this->applyMediaMetadata($row, $translation, $resolved, $language);
        }

        return $row;
    }

    /**
     * Contao reads image/media metadata from the language-keyed "meta" field of
     * the content record. The translated values are folded into that field so
     * the normal renderer picks them up for the active language.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $resolved
     *
     * @return array<string, mixed>
     */
    private function applyMediaMetadata(array $row, object|array $translation, array $resolved, ?string $language): array
    {
        if (null === $language || '' === $language) {
            return $row;
        }

        if ([] === array_intersect(self::MEDIA_METADATA_FIELDS, array_keys($resolved))) {
            return $row;
        }

        $existingMeta = StringUtil::deserialize($row['meta'] ?? null, true);

        $imageTitle = $resolved['imageTitle'] ?? '';
        $titleText = $resolved['titleText'] ?? '';
        $title = FieldStateMap::INHERIT !== $this->resolver->state($translation, 'imageTitle')
            ? $imageTitle
            : ('' !== (string) $imageTitle ? $imageTitle : $titleText);

        $existingMeta[$language] = [
            'title' => $title,
            'alt' => $resolved['alt'] ?? '',
            'caption' => $resolved['caption'] ?? '',
            'link' => $resolved['url'] ?? '',
        ];

        $encodedMeta = serialize($existingMeta);
        if (($row['meta'] ?? null) !== $encodedMeta) {
            $row['meta'] = $encodedMeta;
            $row['overwriteMeta'] = '1';
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRow(object|array $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (method_exists($record, 'row')) {
            $row = $record->row();

            if (is_array($row)) {
                return $row;
            }
        }

        return get_object_vars($record);
    }
}
