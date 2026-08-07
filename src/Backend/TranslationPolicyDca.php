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

use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/** Builds deliberately small translation DCAs without cloning source palettes. */
final class TranslationPolicyDca
{
    private const SAFE_KEYS = ['label', 'inputType', 'default', 'options', 'reference', 'explanation', 'sql'];
    private const SAFE_EVAL = [
        'maxlength', 'minlength', 'rgxp', 'decodeEntities', 'preserveTags',
        'rte', 'allowHtml', 'basicEntities', 'multiple', 'size', 'tl_class', 'rows',
        'cols', 'includeBlankOption', 'chosen', 'nospace', 'doNotCopy',
    ];

    public static function configure(string $translationTable, ?array $aliasCallback = null): void
    {
        $registry = self::registry();
        $policy = $registry->getPolicy($translationTable);
        if ($policy->sourceTable === '' || !isset($GLOBALS['TL_DCA'][$translationTable])) {
            return;
        }
        $GLOBALS['TL_LANG'][$translationTable]['translation_legend'] =
            $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeTranslationLegend'] ?? 'Translatable content';

        $sourceFields = $GLOBALS['TL_DCA'][$policy->sourceTable]['fields'] ?? [];
        $baseFields = $GLOBALS['TL_DCA'][$translationTable]['fields'] ?? [];
        $allowed = array_unique([...array_keys($policy->fields()), ...$policy->independentFields]);
        foreach ($allowed as $field) {
            if (!isset($sourceFields[$field]) || !is_array($sourceFields[$field])) {
                continue;
            }
            $definition = self::safeField($sourceFields[$field]);

            // A translated value is editable only when Contao's source DCA
            // declares a physical column for it. This keeps the render and
            // save allowlist identical to the schema that Contao Manager sees.
            if (!array_key_exists('sql', $definition) || null === $definition['sql']) {
                continue;
            }

            $baseFields[$field] = $definition;
            if ($field === 'alias' && $aliasCallback !== null) {
                $baseFields[$field]['save_callback'] = [$aliasCallback];
            }
        }
        $GLOBALS['TL_DCA'][$translationTable]['fields'] = $baseFields;
        $GLOBALS['TL_DCA'][$translationTable]['subpalettes'] = [];
        $GLOBALS['TL_DCA'][$translationTable]['palettes'] = self::palettes(
            $policy,
            array_keys($baseFields),
            $GLOBALS['TL_DCA'][$policy->sourceTable] ?? [],
        );
    }

    private static function safeField(array $source): array
    {
        $safe = array_intersect_key($source, array_flip(self::SAFE_KEYS));
        if (isset($source['eval']) && is_array($source['eval'])) {
            $safe['eval'] = array_intersect_key($source['eval'], array_flip(self::SAFE_EVAL));
            unset($safe['eval']['submitOnChange'], $safe['eval']['doNotShow']);
        }

        // Source-table callbacks, relations, pickers and wizards are intentionally
        // denied. Alias validation is attached explicitly by the translation DCA.
        return $safe;
    }

    /**
     * Content elements are deliberately absent here: their additional-language
     * form is the native tl_content form, built by
     * {@see ContentTranslationAdapter}. This builder serves the small, deliberately
     * reduced translation forms of pages, articles, news, events and FAQs,
     * whose per-field translation states remain unchanged.
     */
    private static function palettes(TranslationFieldPolicy $policy, array $available, array $sourceDca): array
    {
        return ['default' => self::palette($policy->translatableFields, $policy, $available)];
    }

    private static function palette(array $fields, TranslationFieldPolicy $policy, array $available): string
    {
        $content = array_values(array_intersect(array_keys($fields), $available));
        $publication = array_values(array_intersect($policy->independentFields, $available));
        $parts = ['{language_legend},language_tabs'];
        if ($content !== []) {
            $parts[] = '{translation_legend},'.implode(',', $content);
        }
        if ($publication !== []) {
            $parts[] = '{publish_legend},'.implode(',', $publication);
        }

        return implode(';', $parts);
    }

    private static function registry(): TranslationFieldRegistry
    {
        $container = System::getContainer();

        return $container !== null && $container->has(TranslationFieldRegistry::class)
            ? $container->get(TranslationFieldRegistry::class)
            : new TranslationFieldRegistry();
    }
}
