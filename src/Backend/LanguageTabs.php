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

use Contao\Backend;
use Contao\Database;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewBadgeRenderer;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewStorageInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordFactory;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationFieldPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationModeResolver;

class LanguageTabs extends Backend
{
    private LanguageHelper $languageHelper;
    private TranslationRecordFactory $translationRecordFactory;
    private ?TranslationReviewResolver $reviewResolver;
    private ?TranslationReviewStorageInterface $reviewStorage;
    private ?ReviewBadgeRenderer $reviewRenderer;
    private BackendLanguageContext $context;
    private ContentTranslationModeResolver $contentModes;
    private BackendUrlBuilder $urls;

    public function __construct(
        ?LanguageHelper $languageHelper = null,
        ?TranslationRecordFactory $translationRecordFactory = null,
        ?TranslationReviewResolver $reviewResolver = null,
        ?TranslationReviewStorageInterface $reviewStorage = null,
        ?ReviewBadgeRenderer $reviewRenderer = null,
        ?BackendLanguageContext $context = null,
        ?ContentTranslationModeResolver $contentModes = null,
        ?BackendUrlBuilder $urls = null,
    ) {
        parent::__construct();
        $this->languageHelper = $languageHelper ?? System::getContainer()->get(LanguageHelper::class);
        $this->translationRecordFactory = $translationRecordFactory ?? System::getContainer()->get(TranslationRecordFactory::class);
        $this->reviewResolver = $reviewResolver ?? $this->optionalService(TranslationReviewResolver::class);
        $this->reviewStorage = $reviewStorage ?? $this->optionalService(TranslationReviewStorageInterface::class);
        $this->reviewRenderer = $reviewRenderer ?? $this->optionalService(ReviewBadgeRenderer::class);
        $this->context = $context ?? System::getContainer()->get(BackendLanguageContext::class);
        $this->contentModes = $contentModes ?? System::getContainer()->get(ContentTranslationModeResolver::class);
        $this->urls = $urls ?? System::getContainer()->get(BackendUrlBuilder::class);
    }

    private function optionalService(string $id): ?object
    {
        try {
            $container = System::getContainer();

            return $container !== null && $container->has($id) ? $container->get($id) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * onLoad callback: resolves the editing context once and, when an
     * additional language is active, moves the request onto that language's
     * translation record.
     *
     * It never silently returns to the source language. A refused language
     * leaves the editor on the source record *and says why*, because a silent
     * bounce back to the default language is indistinguishable from a dead
     * link - which is exactly how this defect stayed invisible.
     */
    public function handleTranslationRedirection(DataContainer $dc): void
    {
        $table = (string) $dc->table;

        if (!$this->isRecordAction($table)) {
            // List, select, create and clipboard actions address a parent, not
            // a record. Resolving a record context from a parent id would
            // produce the wrong root and the wrong language, so the optional
            // field is simply removed and nothing else is touched.
            PaletteHelper::removeFromTable($table, ['language_tabs']);

            return;
        }

        $this->configurePalette($dc);

        $scope = $this->context->scope($table, (int) $dc->id);

        // A translation record *is* the selected context; it is never redirected
        // back onto itself. Direct editing without permission or licence stays
        // rejected, but visibly so rather than by bouncing to the source form.
        if ($scope->isOnTranslationTable()) {
            if ($scope->wasRefused() && $scope->fallbackReason->isDenial()) {
                throw new AccessDeniedHttpException($this->refusalMessage($scope));
            }

            return;
        }

        if ($scope->isDefaultLanguage()) {
            // The editor asked for a language and did not get it: report the
            // category once, on the form they are actually looking at.
            if ($scope->wasRefused()) {
                Message::addError($this->refusalMessage($scope));
            }

            return;
        }

        $language = (string) $scope->activeLanguage;

        if ($scope->sourceId <= 0) {
            return;
        }

        // Free content keeps its own independent structure; connected content
        // is edited through the translation record of the source structure.
        if (in_array($scope->sourceTable, ['tl_article', 'tl_content'], true) && $scope->contentMode->isFree()) {
            $target = $this->freeContentTarget($scope->sourceTable, $scope->sourceId);

            $this->redirect($this->urls->forLanguage($target['table'], $target['id'], $language, $scope->rootId));

            return;
        }

        // A content element is translated on its own native form: the request
        // already is that form, and ContentTranslationAdapter swaps the values
        // for the selected language. There is nothing to redirect to, and no
        // translation row is created merely because a tab was opened.
        if (ContentTranslationFieldPolicy::SOURCE_TABLE === $scope->sourceTable) {
            if (!$this->context->mayEditTranslations()) {
                Message::addError($this->refusalMessage($scope));
            }

            return;
        }

        $translationTable = $scope->translationTable();
        $existing = Database::getInstance()
            ->prepare("SELECT id FROM {$translationTable} WHERE pid=? AND language=?")
            ->execute($scope->sourceId, $language);

        if ($existing->numRows) {
            $this->redirect($this->urls->forLanguage($translationTable, (int) $existing->id, $language, $scope->rootId, ['act' => 'edit']));
        }

        // Creating a translation record is a licensed editorial capability. The
        // context resolver already proved it; this is the write-boundary check.
        if (!$this->context->mayEditTranslations()) {
            Message::addError($this->refusalMessage($scope));

            return;
        }

        $mainRecord = Database::getInstance()
            ->prepare("SELECT * FROM {$scope->sourceTable} WHERE id=?")
            ->execute($scope->sourceId);

        if (!$mainRecord->numRows) {
            return;
        }

        $db = Database::getInstance();
        $availableColumns = [];

        foreach ($db->listFields($translationTable) as $field) {
            $availableColumns[] = $field['name'];
        }

        $insertSet = $this->translationRecordFactory->createInsertSet(
            $translationTable,
            $mainRecord->row(),
            $availableColumns,
            $language,
            $scope->sourceId,
        );

        $insert = $db->prepare("INSERT INTO {$translationTable} %s")->set($insertSet)->execute();

        $this->redirect($this->urls->forLanguage($translationTable, (int) $insert->insertId, $language, $scope->rootId, ['act' => 'edit']));
    }

    /** Actions whose `id` identifies the edited record itself. */
    private function isRecordAction(string $table): bool
    {
        try {
            $action = (string) Input::get('act');
            $formSubmit = Input::post('FORM_SUBMIT');
        } catch (\Throwable) {
            return false;
        }

        return self::isSubmittedRecordAction($action, $formSubmit, $table);
    }

    public static function isSubmittedRecordAction(string $action, mixed $formSubmit, string $table): bool
    {
        return in_array($action, ['edit', 'show'], true)
            || (is_string($formSubmit) && hash_equals($table, $formSubmit));
    }

    /** The translated, category-level explanation of a refused language. */
    private function refusalMessage(BackendTranslationScope $scope): string
    {
        System::loadLanguageFile('default');
        $messages = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeLanguageRefused'] ?? [];
        $message = is_array($messages) ? ($messages[$scope->fallbackReason->value] ?? null) : null;

        if (is_string($message) && '' !== $message) {
            return $message;
        }

        return is_array($messages) && is_string($messages['default'] ?? null) && '' !== $messages['default']
            ? $messages['default']
            : 'This language cannot be edited here.';
    }

    /**
     * input_field_callback to render the actual tabs HTML.
     */
    public function renderTabs(DataContainer $dc): string
    {
        $table = (string) $dc->table;
        $scope = $this->context->scope($table, (int) $dc->id);
        $rootId = $scope->rootId;

        if ($rootId <= 0) {
            return '';
        }

        $languages = MultilingualPagetreeModel::findPublishedByPid($rootId);

        if (!$languages) {
            return ''; // No Contao Multilingual Pagetree languages configured.
        }

        $defaultLanguage = $scope->defaultLanguage;
        $tabs = [];

        // The default tab rebuilds the source-language operation from scratch,
        // so no inherited parameter can carry the additional-language context
        // back in. Selecting a tab is GET-only editing state; it persists
        // nothing.
        $tabs[] = [
            'label' => 'Default ('.strtoupper($defaultLanguage).')',
            'href' => $this->urls->forDefaultLanguage($scope->sourceTable, $scope->sourceId, ['act' => 'edit']),
            'active' => $scope->isDefaultLanguage(),
            'enabled' => true,
            'title' => '',
            'badge' => '',
        ];

        $reviewBadges = $this->reviewBadges($scope->translationTable(), $scope->sourceId);
        $refusal = $scope->wasRefused() ? $this->refusalMessage($scope) : '';

        foreach ($languages as $lang) {
            $code = (string) $lang->language;

            if ((bool) $lang->fallback || BackendTranslationScope::normalize($code) === BackendTranslationScope::normalize($defaultLanguage)) {
                continue;
            }

            // Free content keeps its own structure, so its tab points at that
            // structure instead of at a translation record. Connected content
            // points at its own native form, which the adapter turns into the
            // selected language - the storage table is never a link target.
            $target = in_array($scope->sourceTable, ['tl_article', 'tl_content'], true)
                && $this->contentModes->getModeForRoot($rootId, $code)->isFree()
                ? $this->freeContentTarget($scope->sourceTable, $scope->sourceId)
                : ['table' => $scope->sourceTable, 'id' => $scope->sourceId, 'edit' => true];

            $extra = ($target['edit'] ?? false) ? ['act' => 'edit'] : [];

            // The active state is decided by the server-resolved scope alone,
            // through one normalising comparison, so "de-AT" and "de_at" are
            // the same language and no stylesheet or script is involved.
            $isActive = !$scope->isDefaultLanguage() && $scope->isEditing($code);
            $isRefused = '' !== $refusal && BackendTranslationScope::normalize($code) === (string) $this->context->requestedLanguage();

            $tabs[] = [
                'label' => $lang->label.' ['.strtoupper($code).']',
                'href' => $this->urls->forLanguage($target['table'], (int) $target['id'], $code, $rootId, $extra),
                'active' => $isActive,
                'enabled' => !$isRefused,
                'title' => $isRefused ? $refusal : '',
                'badge' => $reviewBadges[$code] ?? '',
            ];
        }

        return $this->render($tabs);
    }

    /**
     * @param list<array{label:string,href:string,active:bool,enabled:bool,title:string,badge:string}> $tabs
     */
    private function render(array $tabs): string
    {
        $html = '<div class="tl_tabs_container cmp-language-tabs" style="margin-bottom:20px;">';
        $html .= '<ul class="tl_tabs" style="display:flex; list-style:none; padding:0; margin:0; border-bottom:1px solid #ccc;">';

        foreach ($tabs as $tab) {
            $classes = ['cmp-language-tab'];

            if ($tab['active']) {
                $classes[] = 'active';
            }

            if (!$tab['enabled']) {
                $classes[] = 'cmp-language-tab--refused';
            }

            $style = $tab['active']
                ? 'background:#f6f6f6; border:1px solid #ccc; border-bottom:1px solid #f6f6f6; margin-bottom:-1px; font-weight:bold;'
                : 'border:1px solid transparent;';
            $linkStyle = 'display:block; padding:8px 15px; text-decoration:none; color:'.($tab['enabled'] ? '#333' : '#999').';';
            $title = '' !== $tab['title'] ? ' title="'.StringUtil::specialchars($tab['title']).'"' : '';

            $html .= '<li class="'.implode(' ', $classes).'"'
                .($tab['active'] ? ' aria-current="true"' : '')
                .' style="margin-right:5px; '.$style.'">';

            if ($tab['enabled']) {
                $html .= '<a href="'.StringUtil::specialchars($tab['href']).'" style="'.$linkStyle.'" class="tl_tab"'.$title.'>'
                    .StringUtil::specialchars($tab['label']).$tab['badge'].'</a>';
            } else {
                // A refused language is shown, not hidden, and is not a link.
                $html .= '<span style="'.$linkStyle.'" class="tl_tab"'.$title.'>'
                    .StringUtil::specialchars($tab['label']).$tab['badge'].'</span>';
            }

            $html .= '</li>';
        }

        return $html.'</ul></div>';
    }

    /**
     * Adds the language legend to the palette of the table currently being
     * loaded, and only to that table.
     *
     * The mutation is scoped: it touches `$GLOBALS['TL_DCA'][$table]` alone, it
     * always removes before it adds so a repeated DCA load cannot duplicate the
     * field, and it runs before the palette is finalised so the tabs exist by
     * the time the form is assembled.
     */
    private function configurePalette(DataContainer $dc): void
    {
        $table = (string) $dc->table;
        PaletteHelper::removeFromTable($table, ['language_tabs']);

        // `activeRecord` is empty while onload callbacks run, so the page type
        // is resolved through the shared context instead. Root pages keep their
        // own controls and never receive the translation tabs.
        $pages = $this->optionalService(RootPageContext::class);

        if ('tl_page' === $table && $pages instanceof RootPageContext && $pages->isRootPage($pages->currentId($dc))) {
            return;
        }

        $rootId = $this->context->scope($table, (int) $dc->id)->rootId;

        // The palette reflects the root's configured languages. The capability
        // check belongs at the translation read/write boundary, not here: using
        // it as a visibility condition removed this field - and its whole
        // legend - from correctly configured sites.
        if ($rootId <= 0 || !$this->hasPublishedTargetLanguage($rootId)) {
            return;
        }

        PaletteHelper::addToTable($table, 'language_legend', ['language_tabs']);
    }

    private function hasPublishedTargetLanguage(int $rootId): bool
    {
        $default = BackendTranslationScope::normalize($this->languageHelper->getDefaultLanguage($rootId));
        $languages = MultilingualPagetreeModel::findPublishedByPid($rootId);

        if (null === $languages) {
            return false;
        }

        foreach ($languages as $language) {
            if (!(bool) $language->fallback && BackendTranslationScope::normalize((string) $language->language) !== $default) {
                return true;
            }
        }

        return false;
    }

    /**
     * Review badges of every translation of one source record, keyed by
     * language. All translations are read with a single query and the source
     * record is read once, so adding a language never adds a query per row.
     *
     * @return array<string, string>
     */
    private function reviewBadges(string $translationTable, int $mainId): array
    {
        // Only a table the review workflow actually governs gets a badge. A
        // content element is reviewed as part of the page it belongs to and
        // holds no review state of its own, so decorating its tabs with a
        // status would report something that is never maintained - and offer no
        // way to act on it, because content has no "mark as reviewed" action.
        if (!TranslationReviewDca::governs($translationTable)) {
            return [];
        }

        if ($this->reviewResolver === null || $this->reviewStorage === null || $this->reviewRenderer === null || $mainId <= 0) {
            return [];
        }

        try {
            $translations = $this->reviewStorage->findTranslationsOfSource($translationTable, $mainId);
            if ($translations === []) {
                return [];
            }

            $sourceTable = str_replace('_translation', '', $translationTable);
            $source = $this->reviewStorage->findSource($sourceTable, $mainId);
            $labels = $this->reviewLabels();
            $badges = [];

            foreach ($translations as $translation) {
                $language = (string) ($translation['language'] ?? '');
                if ($language === '') {
                    continue;
                }

                $state = $this->reviewResolver->resolve($translationTable, $translation, $source);
                $badges[$language] = $this->reviewRenderer->badge($state->status, $labels);
            }

            return $badges;
        } catch (\Throwable) {
            // Status decoration must never break the editing interface.
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function reviewLabels(): array
    {
        System::loadLanguageFile('default');
        $statuses = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatuses'] ?? [];

        return is_array($statuses)
            ? array_map(static fn ($value): string => is_string($value) ? $value : '', $statuses)
            : [];
    }

    /** @return array{table:string,id:int,edit?:bool} */
    private function freeContentTarget(string $table, int $id): array
    {
        $db = Database::getInstance();
        while ('tl_content' === $table && $id > 0) {
            $record = $db->prepare('SELECT pid, ptable FROM tl_content WHERE id=?')->execute($id);
            if (!$record->numRows) return ['table' => 'tl_article', 'id' => 0];
            $table = (string) ($record->ptable ?: 'tl_article');
            $id = (int) $record->pid;
        }
        if ('tl_article' === $table && $id > 0) {
            $record = $db->prepare('SELECT pid FROM tl_article WHERE id=?')->execute($id);
            return ['table' => 'tl_article', 'id' => $record->numRows ? (int) $record->pid : 0];
        }

        return ['table' => $table, 'id' => $id];
    }
}
