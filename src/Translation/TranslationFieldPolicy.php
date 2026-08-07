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

final class TranslationFieldPolicy
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $sourceTable,
        public readonly string $translationTable,
        public readonly array $translatableFields,
        public readonly array $structuralFields,
        public readonly array $independentFields,
        public readonly array $technicalFields,
        public readonly array $requiredFields = [],
        public readonly array $aliasFields = [],
        public readonly array $contentTypeFields = [],
    ) {
    }

    public static function denied(string $entityType = ''): self
    {
        return new self($entityType, '', '', [], [], [], []);
    }

    public function fields(?string $contentType = null): array
    {
        if ($this->sourceTable !== 'tl_content') {
            return $this->translatableFields;
        }

        if ($contentType === null) {
            $fields = $this->translatableFields;
            foreach ($this->contentTypeFields as $typeFields) {
                $fields += $typeFields;
            }

            return $fields;
        }

        return $this->translatableFields + ($this->contentTypeFields[$contentType] ?? []);
    }
}
