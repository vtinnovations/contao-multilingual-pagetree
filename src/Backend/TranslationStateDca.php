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

use Contao\DataContainer;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

final class TranslationStateDca
{
    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly FieldStateMap $states,
    ) {
    }

    public static function configure(string $table): void
    {
        System::loadLanguageFile('default');
        $container = System::getContainer();
        $registry = $container !== null && $container->has(TranslationFieldRegistry::class)
            ? $container->get(TranslationFieldRegistry::class)
            : new TranslationFieldRegistry();
        $supportedFields = $registry->fieldNames($table, array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []));
        if ($supportedFields === [] || !isset($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        $GLOBALS['TL_DCA'][$table]['fields']['fieldStates'] = [
            'sql' => "text NULL",
            'eval' => ['doNotShow' => true],
        ];

        foreach ($supportedFields as $field) {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field])) {
                continue;
            }

            $stateField = 'fieldState_'.$field;
            $GLOBALS['TL_DCA'][$table]['fields'][$stateField] = [
                'label' => &$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldState'],
                'inputType' => 'select',
                'options' => [FieldStateMap::INHERIT, FieldStateMap::CUSTOM, FieldStateMap::EMPTY],
                'reference' => &$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeFieldStates'],
                'load_callback' => [[self::class, 'loadState']],
                'save_callback' => [[self::class, 'saveState']],
                'wizard' => [[self::class, 'renderSourcePreview']],
                'sql' => null,
                'eval' => [
                    'includeBlankOption' => false,
                    'tl_class' => 'w50 clr contao-multilingual-pagetree-state',
                    'data-field' => $field,
                ],
            ];
        }

        foreach (($GLOBALS['TL_DCA'][$table]['palettes'] ?? []) as $name => $palette) {
            if ($name === '__selector__' || !is_string($palette)) {
                continue;
            }
            foreach ($supportedFields as $field) {
                if (preg_match('/(^|[,;])'.preg_quote($field, '/').'(?=,|;|$)/', $palette)) {
                    $palette = preg_replace(
                        '/(^|[,;])'.preg_quote($field, '/').'(?=,|;|$)/',
                        '$1fieldState_'.$field.','.$field,
                        $palette,
                        1,
                    );
                }
            }
            $GLOBALS['TL_DCA'][$table]['palettes'][$name] = $palette;
        }

        $GLOBALS['TL_DCA'][$table]['config']['onsubmit_callback'][] = [self::class, 'normalizeStates'];
        $GLOBALS['TL_DCA'][$table]['config']['onbeforesubmit_callback'][] = [self::class, 'removeVirtualStateFields'];
        $GLOBALS['TL_JAVASCRIPT']['contao_multilingual_pagetree_field_states'] = 'bundles/vtinnovationscontaomultilingualpagetree/js/translation-field-states.js';
    }

    public function loadState(mixed $value, DataContainer $dc): string
    {
        $field = $this->fieldName($dc->field ?? '');

        return $this->states->state($dc->activeRecord->fieldStates ?? null, $field);
    }

    public function saveState(mixed $value, DataContainer $dc): string
    {
        $field = $this->fieldName($dc->field ?? '');
        $table = (string) $dc->table;
        $allowedFields = $this->allowedFields($table, $dc);
        $state = in_array($value, [FieldStateMap::INHERIT, FieldStateMap::CUSTOM, FieldStateMap::EMPTY], true)
            ? (string) $value
            : FieldStateMap::INHERIT;

        if ($field === '' || !in_array($field, $allowedFields, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown translation state field "%s".', $field));
        }

        // Persistence is deliberately deferred to removeVirtualStateFields(),
        // where all selectors are collapsed into fieldStates and saved in the
        // same native DC_Table UPDATE as their translated values.
        return $state;
    }

    public function normalizeStates(DataContainer $dc): void
    {
        if ((int) $dc->id <= 0) {
            return;
        }

        $table = (string) $dc->table;
        $allowedFields = $this->allowedFields($table, $dc);
        $record = Database::getInstance()->prepare("SELECT fieldStates FROM {$table} WHERE id=?")->execute((int) $dc->id);
        if (!$record->numRows) {
            return;
        }

        $encoded = $this->states->encode($this->states->normalize($this->states->decode($record->fieldStates), $allowedFields));
        Database::getInstance()->prepare("UPDATE {$table} SET fieldStates=? WHERE id=?")->execute($encoded, (int) $dc->id);
    }

    /**
     * The fieldState_* selectors are virtual controls backed by the single
     * fieldStates JSON column. DC_Table otherwise treats every submitted DCA
     * widget as a physical column and includes it in the UPDATE statement.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function removeVirtualStateFields(array $values, DataContainer $dc): array
    {
        $allowedFields = $this->allowedFields((string) $dc->table, $dc);
        return self::collapseVirtualStateFields(
            $values,
            $allowedFields,
            $dc->activeRecord->fieldStates ?? null,
            $this->states,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    public static function collapseVirtualStateFields(
        array $values,
        array $allowedFields,
        mixed $existingStates,
        FieldStateMap $states,
    ): array {
        $map = $states->decode($existingStates);

        foreach ($allowedFields as $field) {
            $stateField = 'fieldState_'.$field;
            if (array_key_exists($stateField, $values)) {
                $map[$field] = is_string($values[$stateField]) ? $values[$stateField] : FieldStateMap::INHERIT;
            }
        }

        $values = self::withoutVirtualStateFields($values, $allowedFields);
        $values['fieldStates'] = $states->encode($states->normalize($map, $allowedFields));

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    public static function withoutVirtualStateFields(array $values, array $allowedFields): array
    {
        $allowedStateFields = array_fill_keys(
            array_map(static fn (string $field): string => 'fieldState_'.$field, $allowedFields),
            true,
        );

        foreach (array_keys($values) as $field) {
            if (!is_string($field) || !str_starts_with($field, 'fieldState_')) {
                continue;
            }

            if (!isset($allowedStateFields[$field])) {
                throw new \InvalidArgumentException(sprintf('Unknown translation state field "%s".', $field));
            }

            unset($values[$field]);
        }

        return $values;
    }

    public function renderSourcePreview(DataContainer $dc): string
    {
        $field = $this->fieldName($dc->field ?? '');
        $sourceTable = $this->fields->sourceTable((string) $dc->table);
        $sourceId = (int) ($dc->activeRecord->pid ?? 0);
        if ($field === ''
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)
            || !in_array($field, $this->allowedFields((string) $dc->table, $dc), true)
            || $sourceTable === null
            || $sourceId <= 0) {
            return '';
        }

        $record = Database::getInstance()->prepare("SELECT {$field} FROM {$sourceTable} WHERE id=?")->execute($sourceId);
        if (!$record->numRows) {
            return '';
        }

        $value = $record->$field;
        if (is_string($value)) {
            $deserialized = StringUtil::deserialize($value);
            if (is_array($deserialized)) {
                $value = json_encode($deserialized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
            }
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($value === null) {
            $value = 'NULL';
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $preview = mb_strimwidth((string) $value, 0, 240, '…');

        return '<div class="contao-multilingual-pagetree-source-preview"><strong>'
            .StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeSourcePreview'] ?? ''))
            .':</strong> '.StringUtil::specialchars($preview).'</div>';
    }

    private function fieldName(string $stateField): string
    {
        return str_starts_with($stateField, 'fieldState_') ? substr($stateField, strlen('fieldState_')) : '';
    }

    /**
     * Content elements no longer use per-field state selectors: their
     * additional-language form is the native tl_content form and the provenance
     * of a translated value is derived when the form is submitted.
     */
    private function allowedFields(string $table, DataContainer $dc): array
    {
        return $this->fields->fieldNames($table, array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []));
    }
}
