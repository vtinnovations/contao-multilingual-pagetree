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

use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * The one canonical answer to "what may an additional language do with this
 * content-element field?".
 *
 * It is deliberately independent of the rendered form. The additional-language
 * form reuses the *native* tl_content palette so it looks and behaves like the
 * source form, which means the browser sees many more fields than a translation
 * may own. This policy - not the palette - decides what is stored, so a crafted
 * POST can never turn a structural or technical field into a translated value.
 *
 * Three inputs decide a role:
 *
 *  1. the technical/structural/independent lists of the existing
 *     {@see TranslationFieldRegistry}, which stay authoritative;
 *  2. the registry's translatable field map for the element type, including the
 *     entries third-party bundles contribute;
 *  3. the physical columns of the translation table, because a value can only
 *     be stored where a column exists. A field the registry approves but the
 *     schema does not carry degrades to "inherited" instead of failing.
 */
final class ContentTranslationFieldPolicy
{
    public const TRANSLATION_TABLE = 'tl_content_translation';
    public const SOURCE_TABLE = 'tl_content';

    /**
     * Never rendered and never accepted from a translated POST, whatever the
     * native DCA or a third-party extension declares.
     *
     * @var list<string>
     */
    private const TECHNICAL = [
        'id', 'pid', 'ptable', 'sorting', 'tstamp', 'language', 'fieldStates',
        'language_tabs', 'reviewInfo', 'reviewStatus', 'reviewedSourceRevision',
        'reviewedSourceSnapshot', 'reviewedAt', 'reviewedBy',
        'cmpLanguage', 'cmpLanguageRoot', 'cmpSource',
    ];

    /**
     * Selector fields that drive the native palette.
     *
     * Contao chooses a palette by reading these columns straight from the edited
     * table with SQL, before any callback runs, so a value that only exists on
     * the source row cannot select a palette: the form collapses to the generic
     * default one. They are therefore mirrored into the translation row and kept
     * in sync with the source - and, being structure rather than content, they
     * stay read-only and are never accepted from a translated submission.
     *
     * `type` is the one Contao itself declares as a `tl_content` selector. A
     * third-party selector is added here only when its subpalette has to expand
     * for a translated record too.
     *
     * @var list<string>
     */
    public const STRUCTURAL_COLUMNS = ['type'];

    public function __construct(private readonly TranslationFieldRegistry $registry)
    {
    }

    /**
     * The columns the translation table persists, across every element type.
     *
     * This set is derived from the registry alone - never from a live database
     * read - so the declared schema is identical in a web request, in
     * `contao:migrate` and during a cold cache warmup. A set that changed with
     * the connection state could make a schema update propose dropping columns
     * that hold real translations.
     *
     * @return list<string>
     */
    public function persistedColumns(): array
    {
        $policy = $this->registry->getPolicy(self::TRANSLATION_TABLE);

        // fields(null) is already the union of the shared fields and every
        // registered element type, third-party contributions included.
        $columns = array_keys($policy->fields());

        foreach ($policy->independentFields as $field) {
            $columns[] = $field;
        }

        // The palette selectors are part of the schema: without them Contao
        // cannot resolve the native palette of a translated record.
        foreach (self::STRUCTURAL_COLUMNS as $field) {
            $columns[] = $field;
        }

        $columns = array_values(array_unique(array_filter(
            $columns,
            static fn (string $field): bool => !in_array($field, self::TECHNICAL, true),
        )));

        sort($columns);

        return $columns;
    }

    /**
     * @param list<string> $translationColumns physical columns of the translation table
     */
    public function role(string $field, ?string $contentType, array $translationColumns): ContentFieldRole
    {
        if ('' === $field || in_array($field, self::TECHNICAL, true)) {
            return ContentFieldRole::Technical;
        }

        // A palette selector is resolved before the registry is consulted: the
        // registry lists `type` as technical, which is correct for *writing* but
        // would leave the column out of the schema and break palette selection.
        if (in_array($field, self::STRUCTURAL_COLUMNS, true)) {
            return ContentFieldRole::Structural;
        }

        $policy = $this->registry->getPolicy(self::TRANSLATION_TABLE);

        if (in_array($field, $policy->technicalFields, true)) {
            return ContentFieldRole::Technical;
        }

        if (in_array($field, $policy->independentFields, true)) {
            // A language owns its own publication window only where a column
            // exists to hold it.
            return in_array($field, $translationColumns, true)
                ? ContentFieldRole::Independent
                : ContentFieldRole::Inherited;
        }

        if (!$this->registry->isTranslatable(self::TRANSLATION_TABLE, $field, $contentType)) {
            return ContentFieldRole::Inherited;
        }

        // Approved, but only storable where the schema carries the column.
        return in_array($field, $translationColumns, true)
            ? ContentFieldRole::Translatable
            : ContentFieldRole::Inherited;
    }

    /**
     * The palette selectors mirrored into the translation row.
     *
     * @param list<string> $translationColumns
     *
     * @return list<string>
     */
    public function structuralColumns(array $translationColumns): array
    {
        return array_values(array_filter(
            self::STRUCTURAL_COLUMNS,
            static fn (string $field): bool => in_array($field, $translationColumns, true),
        ));
    }

    /**
     * Every field the translation record may persist for one element type.
     *
     * @param list<string> $translationColumns
     *
     * @return list<string>
     */
    public function editableFields(?string $contentType, array $translationColumns): array
    {
        $editable = [];

        foreach ($translationColumns as $column) {
            if ($this->role($column, $contentType, $translationColumns)->isEditable()) {
                $editable[] = $column;
            }
        }

        return array_values(array_unique($editable));
    }

    /**
     * Fields whose value is translated content, excluding the independent
     * publication fields. These are the fields provenance is derived for.
     *
     * @param list<string> $translationColumns
     *
     * @return list<string>
     */
    public function translatableFields(?string $contentType, array $translationColumns): array
    {
        $fields = [];

        foreach ($translationColumns as $column) {
            if (ContentFieldRole::Translatable === $this->role($column, $contentType, $translationColumns)) {
                $fields[] = $column;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Removes everything a translated submission may not own.
     *
     * This is the single write boundary: whatever a form, a browser or a
     * crafted request submits, only approved columns survive.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $translationColumns
     *
     * @return array<string, mixed>
     */
    public function filterSubmission(array $values, ?string $contentType, array $translationColumns): array
    {
        $allowed = array_fill_keys($this->editableFields($contentType, $translationColumns), true);
        $filtered = [];

        foreach ($values as $field => $value) {
            if (is_string($field) && isset($allowed[$field])) {
                $filtered[$field] = $value;
            }
        }

        return $filtered;
    }
}
