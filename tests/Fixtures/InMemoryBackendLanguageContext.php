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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageContext;
use Vtinnovations\ContaoMultilingualPagetree\Backend\BackendTranslationScope;

/**
 * The production backend language context, backed by arrays instead of Contao.
 *
 * Only the seams that touch the database, the request and the licence gate are
 * replaced. Every rule under test - root validation, publication, permission,
 * the licence decision, normalisation and the fallback reason - is the
 * production rule.
 */
final class InMemoryBackendLanguageContext extends BackendLanguageContext
{
    /**
     * @param array<int, list<array{id:int,language:string,published:bool}>> $languagesByRoot
     * @param array<string, int>                                            $rootsByRecord   "table#id" => rootId
     * @param array<string, array{pid:int,language:string}>                 $translations    "table#id" => row
     */
    public function __construct(
        private readonly array $languagesByRoot,
        private readonly array $rootsByRecord,
        private readonly array $translations = [],
        private ?string $requested = null,
        private readonly bool $permitted = true,
        private readonly bool $licensed = true,
        private readonly ?string $rootDomain = 'www.example.com',
        FakeSiteLanguageRegistry|null $registry = null,
    ) {
        parent::__construct(
            $registry ?? new FakeSiteLanguageRegistry(),
            new \Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission(),
        );
    }

    /** Simulates the canonical (or legacy) query parameter of one request. */
    public function request(?string $language): self
    {
        $this->requested = $language;
        $this->reset();

        return $this;
    }

    public function requestedLanguage(): ?string
    {
        if (null === $this->requested) {
            return null;
        }

        if (1 !== preg_match('/^[A-Za-z]{2}(?:[_-][A-Za-z]{2})?$/', trim($this->requested))) {
            return '';
        }

        return BackendTranslationScope::normalize($this->requested);
    }

    public function rootId(string $table, int $id): int
    {
        return $this->rootsByRecord[str_replace('_translation', '', $table).'#'.$id] ?? 0;
    }

    public function mayEditTranslations(): bool
    {
        return $this->licensed;
    }

    public function selectLicenceScope(int $rootId): \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback
    {
        if ($rootId <= 0) {
            return \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback::UnknownRoot;
        }

        return null === $this->rootDomain
            ? \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback::RootDomainMissing
            : \Vtinnovations\ContaoMultilingualPagetree\Backend\BackendLanguageFallback::None;
    }

    protected function translationRecord(string $table, int $id): ?array
    {
        return $this->translations[$table.'#'.$id] ?? null;
    }

    protected function languageRecord(int $rootId, string $language, bool $includeUnpublished = false): ?array
    {
        foreach ($this->languagesByRoot[$rootId] ?? [] as $record) {
            if (BackendTranslationScope::normalize($record['language']) !== $language) {
                continue;
            }

            if (!$includeUnpublished && !$record['published']) {
                continue;
            }

            return $record;
        }

        return null;
    }

    protected function languageExistsElsewhere(string $language, int $rootId): bool
    {
        foreach ($this->languagesByRoot as $otherRoot => $records) {
            if ($otherRoot === $rootId) {
                continue;
            }

            foreach ($records as $record) {
                if (BackendTranslationScope::normalize($record['language']) === $language) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isPermitted(int $rootId): bool
    {
        return $this->permitted && $rootId > 0;
    }
}
