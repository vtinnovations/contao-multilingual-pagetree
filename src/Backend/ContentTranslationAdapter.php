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

use Contao\Database;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFieldRole;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationFieldPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationBuffer;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationRepository;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentValueProvenance;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityCacheInvalidatorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

/**
 * Makes the *native* tl_content form edit a translation.
 *
 * An additional language used to be edited by opening `tl_content_translation`
 * directly. That table is storage: it hangs below `tl_content` through `ptable`,
 * which would make it a third level under the article module, and Contao has no
 * edit operation for it - hence "Not implemented for tl_content_translation".
 * Worse, editing a storage row meant rebuilding the whole content form by hand.
 *
 * The backend therefore edits the real content element, exactly as the default
 * language does, and this adapter swaps the values:
 *
 *  - Contao loads the source record, so the element type, the palette, every
 *    subpalette, the RTE, the pickers and every third-party field are natively
 *    correct with nothing copied or rebuilt;
 *  - a load callback replaces approved fields with the stored translation, or
 *    leaves the source value in place as the prefill when nothing is stored;
 *  - the submit boundary writes the approved values to the translation store and
 *    hands Contao the *source* values back, so the element itself is written
 *    exactly as it already was and the source language cannot be overwritten.
 *
 * Free-mode content is untouched: it owns real `tl_content` rows of its own and
 * is edited natively, without this adapter.
 */
final class ContentTranslationAdapter
{
    /** Marks a definition that already carries the adapter callbacks. */
    private const CONFIGURED = '_cmp_content_translation_adapter';

    /** Request-scoped snapshot of the source row of the record being saved. */
    private array $sourceSnapshot = [];

    public function __construct(
        private readonly BackendLanguageContext $context,
        private readonly ContentTranslationFieldPolicy $policy,
        private readonly ContentTranslationRepository $repository,
        private readonly ContentValueProvenance $provenance,
        private readonly ContentTranslationBuffer $buffer,
        private readonly ?IntegrityCacheInvalidatorInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Registers the adapter on the native content table.
     */
    public static function configure(string $table = ContentTranslationFieldPolicy::SOURCE_TABLE): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table]) || true === ($GLOBALS['TL_DCA'][$table]['config'][self::CONFIGURED] ?? false)) {
            return;
        }

        $GLOBALS['TL_DCA'][$table]['config'][self::CONFIGURED] = true;

        // Runs before the form is built, so the translated values are in place
        // by the time Contao renders the widgets. The palette itself needs no
        // help: it is resolved from the real content element.
        $GLOBALS['TL_DCA'][$table]['config']['onload_callback'][] = [self::class, 'prepareTranslationForm'];

        // Persistence deliberately uses the two callbacks Contao has always
        // had, and no other:
        //
        //  - each approved field gets a `save_callback`, which fires after the
        //    widget has validated and normalised the value and *before* the
        //    field is written, so the translated value can be captured and the
        //    source value handed back in its place;
        //  - `onsubmit_callback` fires once, after every field, and before the
        //    submit button is evaluated - so "Save and close", "Save and new"
        //    and "Save and go back" all reach it before their redirect.
        //
        // The field callbacks are attached in prepareTranslationForm(), once
        // the element type of the record is known.
        $GLOBALS['TL_DCA'][$table]['config']['onsubmit_callback'][] = [self::class, 'flushTranslation'];
    }

    /**
     * Puts the form into translation mode when an additional language is active.
     */
    public function prepareTranslationForm(DataContainer $dc): void
    {
        $scope = $this->translationScope($dc);

        if (null === $scope) {
            return;
        }

        $table = (string) $dc->table;
        $columns = $this->storageColumns();
        $contentType = $this->contentType($dc);

        // Saving a translation writes the source element's own values back to
        // it, so it must not produce a new version of the source record. This
        // is request scoped and only ever applies to a translated edit.
        $GLOBALS['TL_DCA'][$table]['config']['enableVersioning'] = false;

        foreach (array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []) as $field) {
            $field = (string) $field;
            $role = $this->policy->role($field, $contentType, $columns);

            if ($role->isEditable()) {
                // Approved for translation: show the stored value, or the source
                // value as the prefill while nothing is stored...
                $GLOBALS['TL_DCA'][$table]['fields'][$field]['load_callback'][] = [self::class, 'loadTranslatedValue'];

                // ...and capture what comes back, before it can be written to
                // the source row.
                $GLOBALS['TL_DCA'][$table]['fields'][$field]['save_callback'][] = [self::class, 'captureTranslatedValue'];

                continue;
            }

            if (ContentFieldRole::Technical === $role) {
                continue;
            }

            // Structure belongs to the source language in connected mode: it
            // stays visible so the form matches the default-language form, but
            // it cannot be edited and it is never accepted from a submission.
            $GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['disabled'] = true;
            $GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['mandatory'] = false;
        }
    }

    /**
     * Replaces an approved field with its stored translation.
     *
     * The value handed in is the source value, so leaving it untouched is
     * exactly the prefill an untranslated field needs. Nothing is written here.
     */
    public function loadTranslatedValue(mixed $value, DataContainer $dc): mixed
    {
        $scope = $this->translationScope($dc);
        $field = (string) ($dc->field ?? '');

        if (null === $scope || '' === $field) {
            return $value;
        }

        $translation = $this->repository->find($this->sourceId($dc), (string) $scope->activeLanguage);

        if (null === $translation || !array_key_exists($field, $translation)) {
            return $value;
        }

        $state = $this->provenanceState($translation, $field);

        return match ($state) {
            'custom' => $translation[$field],
            // A deliberate blank stays blank; an untranslated field keeps the
            // source value it was handed.
            'empty' => '',
            default => $value,
        };
    }

    /**
     * Captures one normalised translated value and protects the source field.
     *
     * Contao runs a field's `save_callback` after the widget has validated and
     * normalised the value and *before* it writes the column, so this is the
     * one point where a translated value exists and the source row is still
     * untouched. Returning the source column's own value makes the write a
     * no-op on the element, which is what keeps the source language intact.
     */
    public function captureTranslatedValue(mixed $value, DataContainer $dc): mixed
    {
        $scope = $this->translationScope($dc);
        $field = (string) ($dc->field ?? '');

        if (null === $scope || '' === $field) {
            return $value;
        }

        $sourceId = $this->sourceId($dc);
        $source = $this->sourceRow($sourceId);
        $columns = $this->storageColumns();

        // The policy - never the rendered form - decides what may be stored.
        if (!in_array($field, $this->policy->editableFields($this->contentType($dc), $columns), true)) {
            return array_key_exists($field, $source) ? $source[$field] : $value;
        }

        $this->buffer->capture($sourceId, (string) $scope->activeLanguage, $field, $value);

        // Hand the element its own value back: the column is rewritten exactly
        // as it already was.
        return array_key_exists($field, $source) ? $source[$field] : $value;
    }

    /**
     * Stores everything the field callbacks captured, once, before the submit
     * button is evaluated.
     *
     * `onsubmit_callback` runs after every field and before Contao acts on
     * "Save", "Save and close", "Save and new" or "Save and go back", so all of
     * them persist through exactly this path.
     */
    public function flushTranslation(DataContainer $dc): void
    {
        $scope = $this->translationScope($dc);

        if (null === $scope) {
            return;
        }

        $sourceId = $this->sourceId($dc);
        $language = (string) $scope->activeLanguage;

        if (!$this->buffer->has($sourceId, $language)) {
            // Nothing was changed: a no-op save must not touch the store, and
            // must not be reported as a failure either.
            return;
        }

        $captured = $this->buffer->values($sourceId, $language);
        $source = $this->sourceRow($sourceId);
        $columns = $this->storageColumns();
        $contentType = is_string($source['type'] ?? null) ? (string) $source['type'] : null;

        $approved = $this->policy->filterSubmission($captured, $contentType, $columns);
        $translatable = $this->policy->translatableFields($contentType, $columns);

        $states = $this->provenance->derive(
            $approved,
            $source,
            $this->repository->states($sourceId, $language),
            $translatable,
        );

        // A field that still equals the source is not a translation: storing it
        // would materialise a copy of the source language. Only real
        // translations and deliberate blanks are written.
        $values = [];

        foreach ($approved as $field => $value) {
            if (FieldStateMap::INHERIT !== ($states[$field] ?? FieldStateMap::INHERIT)) {
                $values[$field] = $value;
            }
        }

        $this->buffer->release($sourceId, $language);

        if ([] === $values && null === $this->repository->find($sourceId, $language)) {
            // Nothing was translated and nothing is stored: there is no row to
            // create and nothing to report.
            return;
        }

        $stored = $this->repository->save($sourceId, $language, $values, $states);

        if (!$stored) {
            // A failed save is never reported as success and never silently
            // swallowed: the editor is told, and the source stays untouched.
            // The repository has already recorded the exception itself; this
            // adds the request-level context it cannot see, so the two records
            // together identify which element, root and button produced it.
            $this->logger?->error('Contao Multilingual Pagetree: a content translation was not persisted.', [
                'source_id' => $sourceId,
                'root_id' => $scope->rootId,
                'language' => $language,
                'content_type' => $contentType,
                'approved_fields' => array_keys($approved),
                'written_fields' => array_keys($values),
            ]);

            Message::addError($this->storageFailureMessage());

            return;
        }

        // The rendered output of this root is language scoped, so only that
        // root is invalidated - never a global flush.
        $this->cache?->invalidateRoot($scope->rootId);
    }

    private function storageFailureMessage(): string
    {
        System::loadLanguageFile('default');
        $message = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeContentSaveFailed'] ?? null;

        return is_string($message) && '' !== $message
            ? $message
            : 'The translation could not be saved. The source language was not changed.';
    }

    /**
     * The active connected-translation scope of this record, or null when the
     * default language is being edited or the language is not permitted.
     */
    private function translationScope(DataContainer $dc): ?BackendTranslationScope
    {
        $recordId = (int) $dc->id;

        if ($recordId <= 0 || ContentTranslationFieldPolicy::SOURCE_TABLE !== (string) $dc->table) {
            return null;
        }

        // Root, language, permission and licence are all validated here, by the
        // one central backend context - never by this adapter.
        $scope = $this->context->scope((string) $dc->table, $recordId);

        if ($scope->isDefaultLanguage()) {
            return null;
        }

        // Free-mode content owns its own records and is edited natively.
        return $scope->contentMode->isFree() ? null : $scope;
    }

    /**
     * @param array<string, mixed> $translation
     */
    private function provenanceState(array $translation, string $field): string
    {
        $states = $this->repository->states(
            (int) ($translation['pid'] ?? 0),
            (string) ($translation['language'] ?? ''),
        );

        return $states[$field] ?? 'inherit';
    }

    private function sourceId(DataContainer $dc): int
    {
        return (int) $dc->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceRow(int $sourceId): array
    {
        if ($sourceId <= 0) {
            return [];
        }

        if (isset($this->sourceSnapshot[$sourceId])) {
            return $this->sourceSnapshot[$sourceId];
        }

        try {
            $record = Database::getInstance()
                ->prepare('SELECT * FROM '.ContentTranslationFieldPolicy::SOURCE_TABLE.' WHERE id=?')
                ->execute($sourceId);

            return $this->sourceSnapshot[$sourceId] = $record->numRows ? $record->row() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function contentType(DataContainer $dc): ?string
    {
        $type = $this->sourceRow($this->sourceId($dc))['type'] ?? null;

        return is_string($type) && '' !== $type ? $type : null;
    }

    /**
     * The columns the store physically has, intersected with the columns the
     * policy declares. A value can only be written where both agree.
     *
     * @return list<string>
     */
    private function storageColumns(): array
    {
        $physical = $this->repository->columns();

        if ([] === $physical) {
            return $this->policy->persistedColumns();
        }

        return array_values(array_filter(
            $this->policy->persistedColumns(),
            static fn (string $column): bool => in_array(strtolower($column), $physical, true),
        ));
    }
}
