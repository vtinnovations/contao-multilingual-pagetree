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

final class TranslationOverlayResolver
{
    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly FieldStateMap $states,
    ) {
    }

    /**
     * @param PageAvailabilityMode|null $languageMode the language-level rule of the
     *                                                target language. Under the strict
     *                                                rule an untranslated field must not
     *                                                fall back to the source value.
     */
    public function resolveField(
        object|array $sourceRecord,
        object|array|null $translationRecord,
        string $fieldName,
        ?string $translationTable = null,
        ?PageAvailabilityMode $languageMode = null,
    ): mixed {
        $sourceValue = $this->read($sourceRecord, $fieldName);
        if ($translationRecord === null) {
            return $sourceValue;
        }

        $translationTable ??= $this->inferTable($translationRecord);
        if ($translationTable === null || !$this->fields->isTranslatable(
            $translationTable,
            $fieldName,
            $translationTable === 'tl_content_translation' ? $this->contentType($sourceRecord) : null,
        )) {
            return $sourceValue;
        }
        $state = $this->state($translationRecord, $fieldName);
        $type = $this->fields->type($translationTable, $fieldName);

        return match ($state) {
            FieldStateMap::CUSTOM => $this->read($translationRecord, $fieldName),
            FieldStateMap::EMPTY => $this->emptyValue($sourceValue, $type),
            // "Inherit" means no translation was made. Whether that shows the
            // source text is the language's own configured rule, not a
            // per-field decision: the fallback rule renders the source, the
            // strict rule renders nothing.
            default => true === $languageMode?->isStrict() ? $this->emptyValue($sourceValue, $type) : $sourceValue,
        };
    }

    public function state(object|array|null $translationRecord, string $fieldName): string
    {
        if ($translationRecord === null) {
            return FieldStateMap::INHERIT;
        }

        return $this->states->state($this->read($translationRecord, 'fieldStates'), $fieldName);
    }

    private function emptyValue(mixed $sourceValue, ?string $type): mixed
    {
        if ($type === 'headline') {
            $headline = StringUtil::deserialize($sourceValue, true);

            return serialize(['value' => '', 'unit' => (string) ($headline['unit'] ?? '')]);
        }
        if ($type === 'serialized_array') {
            return is_array($sourceValue) ? [] : serialize([]);
        }
        if (is_string($sourceValue)) {
            $deserialized = StringUtil::deserialize($sourceValue);
            if (is_array($deserialized)) {
                return serialize([]);
            }
        }
        if (is_array($sourceValue)) {
            return [];
        }
        if (is_bool($sourceValue)) {
            return false;
        }
        if (is_int($sourceValue)) {
            return 0;
        }
        if (is_float($sourceValue)) {
            return 0.0;
        }

        return $sourceValue === null ? null : '';
    }

    private function read(object|array $record, string $field): mixed
    {
        if (is_array($record)) {
            return $record[$field] ?? null;
        }
        if (method_exists($record, 'row')) {
            $row = $record->row();
            if (is_array($row) && array_key_exists($field, $row)) {
                return $row[$field];
            }
        }

        return $record->$field ?? null;
    }

    private function inferTable(object|array $translationRecord): ?string
    {
        if (is_object($translationRecord) && method_exists($translationRecord, 'getTable')) {
            return $translationRecord->getTable();
        }

        return null;
    }

    private function contentType(object|array $sourceRecord): ?string
    {
        $type = $this->read($sourceRecord, 'type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
