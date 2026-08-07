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
use Contao\Input;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vtinnovations\ContaoMultilingualPagetree\Availability\SiteLanguageRegistryInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationModeResolver;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;

/**
 * The one backend translation-context resolver.
 *
 * It answers, once per request and per record, which language the backend is
 * editing - and, when that is the default language, exactly why. Root
 * resolution, language validation, permission and the licence gate all happen
 * here so that no consumer can reach a different conclusion.
 *
 * Selection is explicit URL state, never session state, so two browser tabs can
 * edit two languages of the same record at the same time.
 *
 * Trust rules:
 *
 *  - the requested code is only ever *compared* against the published language
 *    records of the root that owns the edited record; an id or code from the
 *    URL never selects a record by itself;
 *  - a language configured for another root is refused, so a manipulated
 *    parameter cannot expose another root's translation values;
 *  - permission uses the existing central resolver, and the licence gate the
 *    existing capability policy. Neither is re-implemented here.
 */
class BackendLanguageContext implements ResetInterface
{
    /** The one canonical query parameter for the selected backend language. */
    public const LANGUAGE_PARAMETER = 'contao_multilingual_pagetree_lang';

    /** The owning root, carried alongside so child views stay root-scoped. */
    public const ROOT_PARAMETER = 'contao_multilingual_pagetree_root';

    /**
     * Parameters accepted only as *input*, for links that predate the canonical
     * one. They are normalised immediately and never generated again.
     *
     * @var list<string>
     */
    public const LEGACY_PARAMETERS = ['create_translation'];

    /** Retained for callers that compare against "no additional language". */
    public const DEFAULT = 'default';

    /** @var array<string, BackendTranslationScope> */
    private array $scopes = [];

    public function __construct(
        private readonly SiteLanguageRegistryInterface $siteLanguages,
        private readonly RootPagePermission $permissions,
        private readonly ?CapabilityPolicy $capabilities = null,
        private readonly ?RootScope $licenceScope = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
        private readonly ?ContentTranslationModeResolver $contentModes = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The scope of one edited record. Repeated calls in the same request return
     * the identical object, so the tab renderer, the palette assembly and the
     * save callbacks cannot drift apart.
     */
    public function scope(string $table, int $id): BackendTranslationScope
    {
        $key = $table.'|'.$id;

        return $this->scopes[$key] ??= $this->resolveScope($table, $id);
    }

    /**
     * Backwards compatible array shape used by existing callers.
     *
     * @return array{table:string,id:int,rootId:int,defaultLanguage:string,activeLanguage:string}
     */
    public function resolve(string $table, int $id): array
    {
        $scope = $this->scope($table, $id);

        return [
            'table' => $scope->sourceTable,
            'id' => $scope->sourceId,
            'rootId' => $scope->rootId,
            'defaultLanguage' => $scope->defaultLanguage,
            'activeLanguage' => $scope->activeLanguage ?? self::DEFAULT,
        ];
    }

    /**
     * The raw requested language of this request, canonical parameter first and
     * the retained legacy parameters afterwards. Returns null when nothing was
     * requested or the value is not a language code.
     */
    public function requestedLanguage(): ?string
    {
        foreach ([self::LANGUAGE_PARAMETER, ...self::LEGACY_PARAMETERS] as $parameter) {
            try {
                $value = Input::get($parameter);
            } catch (\Throwable) {
                continue;
            }

            if (!is_string($value) || '' === trim($value)) {
                continue;
            }

            if (1 !== preg_match('/^[A-Za-z]{2}(?:[_-][A-Za-z]{2})?$/', trim($value))) {
                // A present but malformed value is a refusal, not an absence.
                return '';
            }

            return BackendTranslationScope::normalize($value);
        }

        return null;
    }

    /**
     * The additional language selected for one root, or null. Used by the
     * free-content view, which is root-scoped rather than record-scoped.
     */
    public function languageForRoot(int $rootId): ?string
    {
        $requested = $this->requestedLanguage();

        if (null === $requested || '' === $requested || $rootId <= 0) {
            return null;
        }

        return null !== $this->languageRecord($rootId, $requested) ? $requested : null;
    }

    /**
     * The owning Contao website root of any supported backend record. This is
     * the only root resolution in the backend; nothing else walks these
     * relations.
     */
    public function rootId(string $table, int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $table = str_replace('_translation', '', $table);

        try {
            $db = Database::getInstance();

            while ('tl_content' === $table && $id > 0) {
                $record = $db->prepare('SELECT pid, ptable FROM tl_content WHERE id=?')->execute($id);

                if (!$record->numRows) {
                    return 0;
                }

                $table = (string) ($record->ptable ?: 'tl_article');
                $id = (int) $record->pid;
            }

            if ('tl_article' === $table && $id > 0) {
                $record = $db->prepare('SELECT pid FROM tl_article WHERE id=?')->execute($id);

                if (!$record->numRows) {
                    return 0;
                }

                $table = 'tl_page';
                $id = (int) $record->pid;
            }

            foreach ([
                'tl_faq' => ['tl_faq_category', 'SELECT pid FROM tl_faq WHERE id=?'],
                'tl_news' => ['tl_news_archive', 'SELECT pid FROM tl_news WHERE id=?'],
                'tl_calendar_events' => ['tl_calendar', 'SELECT pid FROM tl_calendar_events WHERE id=?'],
            ] as $source => [$parentTable, $query]) {
                if ($source !== $table || !$db->tableExists($parentTable)) {
                    continue;
                }

                $record = $db->prepare($query)->execute($id);

                if (!$record->numRows) {
                    return 0;
                }

                $parent = $db->prepare("SELECT jumpTo FROM {$parentTable} WHERE id=?")->execute((int) $record->pid);

                if (!$parent->numRows || !$parent->jumpTo) {
                    return 0;
                }

                $table = 'tl_page';
                $id = (int) $parent->jumpTo;

                break;
            }

            if ('tl_page' === $table && $id > 0) {
                $page = PageModel::findByPk($id);

                if (null !== $page) {
                    $page->loadDetails();

                    return 'root' === (string) $page->type ? (int) $page->id : (int) $page->rootId;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->debug(sprintf(
                'Contao Multilingual Pagetree: could not resolve the website root of %s#%d: %s',
                $table,
                $id,
                $exception->getMessage(),
            ));
        }

        // Deliberately no frontend fallback here. The frontend page model is
        // meaningless in the backend and returning some other root would let a
        // record be validated against a root that does not own it.
        return 0;
    }

    /**
     * Selects the licence scope of a root, exactly as the rest of the backend
     * does. It never invents a domain and never widens a binding.
     */
    public function selectLicenceScope(int $rootId): BackendLanguageFallback
    {
        if ($rootId <= 0) {
            return BackendLanguageFallback::UnknownRoot;
        }

        if (null === $this->licenceScope || null === $this->rootDomains) {
            return BackendLanguageFallback::None;
        }

        $domain = $this->rootDomains->domain($rootId);

        if (null === $domain) {
            // The root has no configured domain, so no licence scope exists for
            // it. This is reported rather than silently treated as a denial.
            $this->licenceScope->clear();
            $this->capabilities?->reset();

            return BackendLanguageFallback::RootDomainMissing;
        }

        $this->licenceScope->select($rootId, $domain);
        $this->capabilities?->reset();

        return BackendLanguageFallback::None;
    }

    /**
     * Whether the current backend user may manage this root.
     *
     * It delegates to the existing central permission resolver; the rule is not
     * duplicated here and not repeated in the tab renderer or the callbacks.
     */
    protected function isPermitted(int $rootId): bool
    {
        return $this->permissions->canManage($rootId);
    }

    /** Whether this installation may create or change translation records. */
    public function mayEditTranslations(): bool
    {
        return $this->capabilities?->allows(Capability::TranslationEditing) ?? false;
    }

    public function reset(): void
    {
        $this->scopes = [];
    }

    private function resolveScope(string $table, int $id): BackendTranslationScope
    {
        $sourceTable = str_replace('_translation', '', $table);
        $sourceId = $id;
        $recordLanguage = null;

        // A translation record carries its own language; that is the selection,
        // and it outranks the URL because the record itself is the context.
        if (str_ends_with($table, '_translation') && $id > 0) {
            $record = $this->translationRecord($table, $id);

            if (null === $record) {
                $sourceId = 0;
            } else {
                $sourceId = $record['pid'];
                $recordLanguage = BackendTranslationScope::normalize($record['language']);
            }
        }

        $rootId = $this->rootId($sourceTable, $sourceId);
        $defaultLanguage = $rootId > 0 ? $this->siteLanguages->defaultLanguage($rootId) : '';
        $requested = $recordLanguage ?? $this->requestedLanguage();

        $scope = $this->evaluate($table, $id, $sourceTable, $sourceId, $rootId, $defaultLanguage, $requested);

        $this->logger?->debug('Contao Multilingual Pagetree: backend language scope resolved.', $scope->toDiagnosticArray());

        return $scope;
    }

    private function evaluate(
        string $table,
        int $id,
        string $sourceTable,
        int $sourceId,
        int $rootId,
        string $defaultLanguage,
        ?string $requested,
    ): BackendTranslationScope {
        $fallback = static fn (BackendLanguageFallback $reason): BackendTranslationScope => BackendTranslationScope::defaultLanguageScope(
            $table,
            $id,
            $sourceTable,
            $sourceId,
            $rootId,
            $defaultLanguage,
            $reason,
        );

        if (null === $requested) {
            return $fallback(BackendLanguageFallback::NotRequested);
        }

        if ('' === $requested) {
            return $fallback(BackendLanguageFallback::InvalidParameter);
        }

        if ($rootId <= 0) {
            return $fallback(BackendLanguageFallback::UnknownRoot);
        }

        if (BackendTranslationScope::normalize($defaultLanguage) === $requested) {
            return $fallback(BackendLanguageFallback::IsDefaultLanguage);
        }

        // The language must be one this exact root persists. A code that only
        // exists under another root is refused, never borrowed.
        $record = $this->languageRecord($rootId, $requested, true);

        if (null === $record) {
            return $fallback(
                $this->languageExistsElsewhere($requested, $rootId)
                    ? BackendLanguageFallback::ForeignRoot
                    : BackendLanguageFallback::NotConfigured,
            );
        }

        if (!(bool) ($record['published'] ?? false)) {
            return $fallback(BackendLanguageFallback::NotPublished);
        }

        if (!$this->isPermitted($rootId)) {
            return $fallback(BackendLanguageFallback::PermissionDenied);
        }

        // The licence scope is selected exactly as the rest of the backend does
        // it. A root without a domain has no scope, but that only becomes a
        // reported reason when it actually blocks the capability below.
        $scopeReason = $this->selectLicenceScope($rootId);

        if (!$this->mayEditTranslations()) {
            return $fallback(
                BackendLanguageFallback::RootDomainMissing === $scopeReason
                    ? BackendLanguageFallback::RootDomainMissing
                    : BackendLanguageFallback::LicenceDenied,
            );
        }

        return new BackendTranslationScope(
            $table,
            $id,
            $sourceTable,
            $sourceId,
            $rootId,
            $defaultLanguage,
            $requested,
            (int) ($record['id'] ?? 0),
            BackendLanguageFallback::None,
            $this->contentModes?->getModeForRoot($rootId, $requested) ?? ContentTranslationMode::Connected,
        );
    }

    /**
     * The owning record and language of a translation row.
     *
     * A seam rather than a private detail, so the resolution rules can be
     * exercised without a database - exactly as the site language registry
     * does for its own records.
     *
     * @return array{pid:int,language:string}|null
     */
    protected function translationRecord(string $table, int $id): ?array
    {
        try {
            $record = Database::getInstance()->prepare("SELECT pid, language FROM {$table} WHERE id=?")->execute($id);

            return $record->numRows ? ['pid' => (int) $record->pid, 'language' => (string) $record->language] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One non-default language record of one exact root.
     *
     * @return array{id:int,language:string,published:bool}|null
     */
    protected function languageRecord(int $rootId, string $language, bool $includeUnpublished = false): ?array
    {
        if ($rootId <= 0) {
            return null;
        }

        try {
            $models = $includeUnpublished
                ? MultilingualPagetreeModel::findByPid($rootId)
                : MultilingualPagetreeModel::findPublishedByPid($rootId);
        } catch (\Throwable) {
            return null;
        }

        foreach ($models ?? [] as $model) {
            if ((bool) ($model->fallback ?? false)) {
                continue;
            }

            if (BackendTranslationScope::normalize((string) $model->language) !== $language) {
                continue;
            }

            return [
                'id' => (int) $model->id,
                'language' => (string) $model->language,
                'published' => (bool) ($model->published ?? false),
            ];
        }

        return null;
    }

    /**
     * Whether the refused code is configured under some *other* root. It only
     * distinguishes two diagnostic reasons; it never authorises anything.
     */
    protected function languageExistsElsewhere(string $language, int $rootId): bool
    {
        try {
            $models = MultilingualPagetreeModel::findAllPublished();
        } catch (\Throwable) {
            return false;
        }

        foreach ($models ?? [] as $model) {
            if ((int) $model->pid !== $rootId
                && BackendTranslationScope::normalize((string) $model->language) === $language
            ) {
                return true;
            }
        }

        return false;
    }
}
