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

use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationModeResolver;
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentRelationValidator;
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentStorageInterface;
use Vtinnovations\ContaoMultilingualPagetree\Content\StructuralChangeGuard;

/**
 * Backend integration of the connected/free content modes.
 *
 * On the source tables it adds the language-ownership fields, keeps the record
 * lists of the source structure and of each free language separated, validates
 * owner relations server side and labels free records with their language.
 *
 * On the connected translation tables it rejects structural actions, so a
 * connected translation can never be moved, copied as structure, re-typed or
 * detached from its source.
 */
final class ContentModeDca
{
    /** Query parameter selecting the free-language content tree in the backend. */
    public const LANGUAGE_PARAMETER = BackendLanguageContext::LANGUAGE_PARAMETER;

    public function __construct(
        private readonly ContentTranslationModeResolver $modeResolver,
        private readonly FreeContentRelationValidator $relations,
        private readonly StructuralChangeGuard $structuralGuard,
        private readonly FreeContentStorageInterface $storage,
        private readonly BackendLanguageContext $languageContext,
    ) {
    }

    /**
     * Registers ownership handling on tl_article or tl_content.
     */
    public static function configureSource(string $table): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA'][$table];

        $dca['fields'][ContentOwnership::FIELD_LANGUAGE] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => false],
            'sql' => "varchar(7) NOT NULL default ''",
        ];
        $dca['fields'][ContentOwnership::FIELD_ROOT] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => false],
            'sql' => "int(10) unsigned NOT NULL default 0",
        ];

        // Language and root indexes keep the scoped lookups fast.
        $dca['config']['sql']['keys'][ContentOwnership::FIELD_LANGUAGE.','.ContentOwnership::FIELD_ROOT] = 'index';

        $dca['config']['onload_callback'][] = [self::class, 'applyLanguageScope'];
        $dca['config']['onsubmit_callback'][] = [self::class, 'validateOwnership'];

        // The existing record renderer keeps working and only gains a badge.
        $existing = $dca['list']['sorting']['child_record_callback'] ?? null;

        if (null !== $existing) {
            $dca['list']['sorting']['contao_multilingual_pagetree_previous_child_record'] = $existing;
            $dca['list']['sorting']['child_record_callback'] = [self::class, 'listChildRecord'];
        }
    }

    /**
     * child_record_callback wrapper adding the language badge to free records.
     *
     * @param array<string, mixed> $row
     */
    public function listChildRecord(array $row): string
    {
        $table = (string) Input::get('table');
        $label = '';
        $previous = $GLOBALS['TL_DCA'][$table]['list']['sorting']['contao_multilingual_pagetree_previous_child_record'] ?? null;

        if (is_array($previous) && isset($previous[0], $previous[1])) {
            try {
                $label = (string) System::importStatic($previous[0])->{$previous[1]}($row);
            } catch (\Throwable) {
                $label = '';
            }
        }

        return $label.$this->languageBadge($row);
    }

    /**
     * Registers structural protection on a connected translation table.
     */
    public static function configureTranslation(string $table): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        $GLOBALS['TL_DCA'][$table]['config']['onload_callback'][] = [self::class, 'rejectStructuralActions'];

        // Structural operations are not offered for overlay records.
        foreach (['cut', 'copy', 'copyAll', 'cutAll'] as $operation) {
            unset($GLOBALS['TL_DCA'][$table]['list']['operations'][$operation]);
        }

        $GLOBALS['TL_DCA'][$table]['config']['notCopyable'] = true;
        $GLOBALS['TL_DCA'][$table]['config']['notSortable'] = true;
    }

    /**
     * Restricts the record list to exactly one content tree: the source
     * structure, or the free records of the selected language. Connected
     * translation records live in their own tables and never appear here.
     */
    public function applyLanguageScope(?DataContainer $dc = null): void
    {
        $table = $dc?->table ?? (string) Input::get('table');

        if (!isset($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        $language = $this->selectedLanguage();

        $GLOBALS['TL_DCA'][$table]['list']['sorting']['filter'][] = [
            ContentOwnership::FIELD_LANGUAGE.'=?',
            $language ?? '',
        ];

        if (null !== $language) {
            $GLOBALS['TL_DCA'][$table]['list']['sorting']['filter'][] = [
                ContentOwnership::FIELD_ROOT.'=?',
                $this->selectedRootPageId(),
            ];
            // New records created in this view belong to the selected language.
            $GLOBALS['TL_DCA'][$table]['fields'][ContentOwnership::FIELD_LANGUAGE]['default'] = $language;
            $GLOBALS['TL_DCA'][$table]['fields'][ContentOwnership::FIELD_ROOT]['default'] = $this->selectedRootPageId();
        }
    }

    /**
     * Server-side validation of the owner relation: a free record may never be
     * attached to a source owner, to another language or to another root site.
     */
    public function validateOwnership(DataContainer $dc): void
    {
        $table = (string) $dc->table;
        $id = (int) $dc->id;

        if ($id <= 0) {
            return;
        }

        $record = $this->storage->findRecord($table, $id);

        if (null === $record) {
            return;
        }

        $ownership = ContentOwnership::fromRecord($record);
        $owner = $this->ownerOf($table, $record);

        if (null === $owner && 'tl_article' === $table) {
            // An article is owned by a page, which carries no ownership itself.
            return;
        }

        if ($this->relations->isValid($ownership, $owner)) {
            return;
        }

        throw new AccessDeniedException(sprintf(
            'Contao Multilingual Pagetree: invalid owner relation for %s record %d (%s).',
            $table,
            $id,
            $this->relations->validate($ownership, $owner),
        ));
    }

    /**
     * Rejects structural actions on connected translation records.
     */
    public function rejectStructuralActions(?DataContainer $dc = null): void
    {
        $table = $dc?->table ?? (string) Input::get('table');

        if (!$this->structuralGuard->isConnectedTranslationTable($table)) {
            return;
        }

        $action = Input::get('act');

        if ($this->structuralGuard->isProtectedAction(is_string($action) ? $action : null)) {
            throw new AccessDeniedException(sprintf(
                'Contao Multilingual Pagetree: connected translations of %s inherit their structure and cannot be "%s".',
                $table,
                (string) $action,
            ));
        }
    }

    /**
     * Adds a language badge to free records so an editor always sees which
     * language a record belongs to.
     *
     * @param array<string, mixed> $row
     */
    public function languageBadge(array $row): string
    {
        $ownership = ContentOwnership::fromRecord($row);

        if ($ownership->isSource()) {
            return '';
        }

        return sprintf(
            '<span class="contao-multilingual-pagetree-free" style="background:#ede9fe;color:#5b21b6;font-size:10px;'
            .'padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:600;vertical-align:middle;" title="%s">%s</span>',
            StringUtil::specialchars($this->label('contaoMultilingualPagetreeFreeRecord')),
            StringUtil::specialchars(strtoupper($ownership->language)),
        );
    }

    /**
     * The content mode of the language currently selected in the backend.
     */
    public function selectedMode(): ContentTranslationMode
    {
        $language = $this->selectedLanguage();

        if (null === $language) {
            return ContentTranslationMode::Connected;
        }

        return $this->modeResolver->getModeForRoot($this->selectedRootPageId(), $language);
    }

    /**
     * The requested free language, or null for the source structure. Only a
     * syntactically valid code of a language configured in free mode is
     * accepted, so the parameter can never widen access.
     */
    public function selectedLanguage(): ?string
    {
        $rootPageId = $this->selectedRootPageId();
        if ($rootPageId <= 0) {
            return null;
        }

        $language = $this->languageContext->languageForRoot($rootPageId);
        if (null === $language) return null;

        return $this->modeResolver->getModeForRoot($rootPageId, $language)->isFree() ? $language : null;
    }

    public function canEditLanguageConfiguration(): bool
    {
        try {
            $user = BackendUser::getInstance();
        } catch (\Throwable) {
            return false;
        }

        if (($user->isAdmin ?? false) === true) {
            return true;
        }

        try {
            return (bool) $user->hasAccess('tl_inline_language', 'tables');
        } catch (\Throwable) {
            return false;
        }
    }

    private function selectedRootPageId(): int
    {
        try {
            $rootPageId = Input::get(BackendLanguageContext::ROOT_PARAMETER);

            return is_numeric($rootPageId) ? max(0, (int) $rootPageId) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|null
     */
    private function ownerOf(string $table, array $record): ?array
    {
        if ('tl_content' !== $table) {
            return null;
        }

        $parentTable = (string) ($record['ptable'] ?? 'tl_article');
        $parentId = (int) ($record['pid'] ?? 0);

        if ('' === $parentTable) {
            $parentTable = 'tl_article';
        }

        if (!in_array($parentTable, ['tl_article', 'tl_content'], true) || $parentId <= 0) {
            return null;
        }

        return $this->storage->findRecord($parentTable, $parentId);
    }

    private function label(string $key): string
    {
        try {
            System::loadLanguageFile('default');
        } catch (\Throwable) {
            // Fall back to the key.
        }

        $label = $GLOBALS['TL_LANG']['MSC'][$key] ?? null;

        return is_string($label) && '' !== $label ? $label : $key;
    }
}
