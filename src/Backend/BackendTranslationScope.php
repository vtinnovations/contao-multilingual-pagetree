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

use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;

/**
 * The one immutable answer to "which language is this backend request editing?".
 *
 * Everything downstream - the tab renderer, the active-tab decision, the
 * translation redirect, palette assembly, field loading, saving, child URLs and
 * return URLs - reads this object. Nothing recomputes a root, a default
 * language or an active language of its own, because two resolvers that
 * disagree is precisely the defect this type exists to remove.
 */
final class BackendTranslationScope
{
    /**
     * @param string                  $requestTable  the table the request is on (may be a *_translation table)
     * @param int                     $requestId     the record id the request is on
     * @param string                  $sourceTable   the owning source table, never a *_translation table
     * @param int                     $sourceId      the owning source record
     * @param int                     $rootId        owning Contao website root, 0 when unknown
     * @param string                  $defaultLanguage the root's own language
     * @param string|null             $activeLanguage  normalised code, or null while the default language is active
     * @param int                     $activeLanguageId the tl_inline_language row, 0 when none
     * @param BackendLanguageFallback $fallbackReason  why the default language is active, if it is
     */
    public function __construct(
        public readonly string $requestTable,
        public readonly int $requestId,
        public readonly string $sourceTable,
        public readonly int $sourceId,
        public readonly int $rootId,
        public readonly string $defaultLanguage,
        public readonly ?string $activeLanguage,
        public readonly int $activeLanguageId,
        public readonly BackendLanguageFallback $fallbackReason,
        public readonly ContentTranslationMode $contentMode = ContentTranslationMode::Connected,
    ) {
    }

    public static function defaultLanguageScope(
        string $requestTable,
        int $requestId,
        string $sourceTable,
        int $sourceId,
        int $rootId,
        string $defaultLanguage,
        BackendLanguageFallback $reason,
    ): self {
        return new self($requestTable, $requestId, $sourceTable, $sourceId, $rootId, $defaultLanguage, null, 0, $reason);
    }

    /** True while the editor is working on the source/default language. */
    public function isDefaultLanguage(): bool
    {
        return null === $this->activeLanguage;
    }

    /** The language actually being edited, default language included. */
    public function editingLanguage(): string
    {
        return $this->activeLanguage ?? $this->defaultLanguage;
    }

    /** The *_translation table of the owning source table. */
    public function translationTable(): string
    {
        return $this->sourceTable.'_translation';
    }

    public function isOnTranslationTable(): bool
    {
        return str_ends_with($this->requestTable, '_translation');
    }

    /**
     * True when the editor asked for a language and was refused. The interface
     * must say so rather than quietly rendering the source language.
     */
    public function wasRefused(): bool
    {
        return $this->isDefaultLanguage() && $this->fallbackReason->wasRequested();
    }

    /**
     * Compares any language spelling against the active editing language.
     * `de-AT`, `de_at` and `DE_AT` are one language, so a tab can never fail to
     * light up merely because the column stores a different spelling.
     */
    public function isEditing(string $language): bool
    {
        return self::normalize($language) === self::normalize($this->editingLanguage());
    }

    /** The canonical query parameters that carry this scope to another URL. */
    public function urlParameters(): array
    {
        if ($this->isDefaultLanguage()) {
            return [];
        }

        return [
            BackendLanguageContext::LANGUAGE_PARAMETER => $this->activeLanguage,
            BackendLanguageContext::ROOT_PARAMETER => $this->rootId,
        ];
    }

    /**
     * Safe diagnostic payload: categories and identifiers only, never a token,
     * a licence key, a cookie or a session id.
     *
     * @return array<string, mixed>
     */
    public function toDiagnosticArray(): array
    {
        return [
            'table' => $this->requestTable,
            'id' => $this->requestId,
            'sourceTable' => $this->sourceTable,
            'sourceId' => $this->sourceId,
            'rootId' => $this->rootId,
            'defaultLanguage' => $this->defaultLanguage,
            'activeLanguage' => $this->activeLanguage,
            'activeLanguageId' => $this->activeLanguageId,
            'isDefault' => $this->isDefaultLanguage(),
            'fallbackReason' => $this->fallbackReason->value,
            'contentMode' => $this->contentMode->value,
        ];
    }

    public static function normalize(string $language): string
    {
        return strtolower(str_replace('-', '_', trim($language)));
    }
}
