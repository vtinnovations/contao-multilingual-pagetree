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

use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;

final class TranslationRecordFactory
{
    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly FieldStateMap $states,
    ) {
    }

    public function createInsertSet(string $translationTable, array $source, array $availableColumns, string $language, int $sourceId): array
    {
        $set = [
            'pid' => $sourceId,
            'language' => $language,
            'tstamp' => time(),
            'fieldStates' => $this->states->encode($this->states->withDefaults($this->fields->fieldNames(
                $translationTable,
                $availableColumns,
                $translationTable === 'tl_content_translation' ? (string) ($source['type'] ?? '') : null,
            ))),
        ];

        // Only values required to select the correct DCA/rendering structure are copied.
        foreach (['type'] as $technicalField) {
            if (array_key_exists($technicalField, $source)) {
                $set[$technicalField] = $source[$technicalField];
            }
        }
        // A new translation is never assumed to be reviewed, not even when every
        // field currently inherits. No reviewed baseline is created here.
        if (in_array('reviewStatus', $availableColumns, true)) {
            $set['reviewStatus'] = ReviewStatus::Unreviewed->value;
        }
        if (in_array('published', $availableColumns, true)) {
            $set['published'] = '1';
        }
        if (in_array('invisible', $availableColumns, true)) {
            $set['invisible'] = '0';
        }

        return array_intersect_key($set, array_flip($availableColumns));
    }
}
