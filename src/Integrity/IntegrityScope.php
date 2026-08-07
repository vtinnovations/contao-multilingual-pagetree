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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

/**
 * What a scan covers.
 *
 * Every scan is scoped: the default backend action scans one root site, and
 * scanning the whole installation is an explicit, elevated choice. Rules and
 * data sources must honour the scope so a scan never reads or reports records of
 * another site.
 */
final class IntegrityScope
{
    public const SCOPE_RECORD = 'record';
    public const SCOPE_PAGE = 'page';
    public const SCOPE_ROOT = 'root';
    public const SCOPE_INSTALLATION = 'installation';

    private function __construct(
        public readonly string $type,
        public readonly int $rootPageId,
        public readonly int $pageId,
        public readonly string $table,
        public readonly int $recordId,
        public readonly ?string $language,
        public readonly ?string $entityType,
    ) {
    }

    public static function root(int $rootPageId, ?string $language = null, ?string $entityType = null): self
    {
        return new self(self::SCOPE_ROOT, max(0, $rootPageId), 0, '', 0, self::normaliseLanguage($language), $entityType);
    }

    public static function page(int $rootPageId, int $pageId, ?string $language = null): self
    {
        return new self(self::SCOPE_PAGE, max(0, $rootPageId), max(0, $pageId), '', 0, self::normaliseLanguage($language), null);
    }

    public static function record(int $rootPageId, string $table, int $recordId): self
    {
        return new self(self::SCOPE_RECORD, max(0, $rootPageId), 0, $table, max(0, $recordId), null, null);
    }

    /**
     * The whole installation. Callers must check the elevated permission before
     * building this scope.
     */
    public static function installation(?string $language = null, ?string $entityType = null): self
    {
        return new self(self::SCOPE_INSTALLATION, 0, 0, '', 0, self::normaliseLanguage($language), $entityType);
    }

    public function isInstallationWide(): bool
    {
        return self::SCOPE_INSTALLATION === $this->type;
    }

    public function requiresElevatedPermission(): bool
    {
        return $this->isInstallationWide();
    }

    public function coversEntity(string $entityType): bool
    {
        return null === $this->entityType || $this->entityType === $entityType;
    }

    public function coversLanguage(string $language): bool
    {
        if (null === $this->language) {
            return true;
        }

        return strtolower(str_replace('-', '_', $language)) === $this->language;
    }

    public function coversRoot(int $rootPageId): bool
    {
        if ($this->isInstallationWide()) {
            return true;
        }

        return 0 === $this->rootPageId || 0 === $rootPageId || $rootPageId === $this->rootPageId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'root' => $this->rootPageId,
            'page' => $this->pageId,
            'table' => $this->table,
            'record' => $this->recordId,
            'language' => $this->language,
            'entity' => $this->entityType,
        ];
    }

    private static function normaliseLanguage(?string $language): ?string
    {
        if (!is_string($language) || '' === trim($language)) {
            return null;
        }

        return strtolower(str_replace('-', '_', trim($language)));
    }
}
