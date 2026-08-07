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
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DataContainer;
use Contao\Input;
use Contao\System;
use Symfony\Component\Routing\RouterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewActionResult;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewBadgeRenderer;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewState;
use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewMarker;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewStorageInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Backend integration of the review layer.
 *
 * Adds the persistent review fields, the status panel, the status filter, the
 * list badge and the explicit "mark as reviewed" action to every translation
 * DCA, and refreshes persisted statuses when a source record is saved.
 */
final class TranslationReviewDca
{
    public const ACTION_KEY = 'contao_multilingual_pagetree_review';

    /** @var array<string, array<string, mixed>|null> */
    private array $sourceCache = [];

    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly TranslationReviewResolver $resolver,
        private readonly TranslationReviewMarker $marker,
        private readonly TranslationReviewStorageInterface $storage,
        private readonly ReviewBadgeRenderer $renderer,
        private readonly BackendActionGuard $actionGuard,
        private readonly ?RouterInterface $router = null,
    ) {
    }

    /**
     * Registers the review layer on a translation DCA.
     */
    public static function configure(string $translationTable): void
    {
        if (!isset($GLOBALS['TL_DCA'][$translationTable])) {
            return;
        }

        System::loadLanguageFile('default');

        $dca = &$GLOBALS['TL_DCA'][$translationTable];

        $dca['fields'][TranslationReviewResolver::FIELD_STATUS] = [
            'label' => &$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatus'],
            'filter' => true,
            'options' => ReviewStatus::editorialValues(),
            'reference' => &$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatuses'],
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => "varchar(16) NOT NULL default '".ReviewStatus::Unreviewed->value."'",
        ];
        $dca['fields'][TranslationReviewResolver::FIELD_REVISION] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => "varchar(64) NOT NULL default ''",
        ];
        $dca['fields'][TranslationReviewResolver::FIELD_SNAPSHOT] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => 'text NULL',
        ];
        $dca['fields'][TranslationReviewResolver::FIELD_REVIEWED_AT] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => "int(10) unsigned NOT NULL default 0",
        ];
        $dca['fields'][TranslationReviewResolver::FIELD_REVIEWED_BY] = [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => "int(10) unsigned NOT NULL default 0",
        ];

        // Read-only status panel inside the translation form.
        $dca['fields']['reviewInfo'] = [
            'input_field_callback' => [self::class, 'renderReviewPanel'],
            'eval' => ['doNotCopy' => true],
        ];

        foreach (($dca['palettes'] ?? []) as $name => $palette) {
            if ('__selector__' === $name || !is_string($palette)) {
                continue;
            }

            $dca['palettes'][$name] = str_contains($palette, 'reviewInfo')
                ? $palette
                : preg_replace('/^\{language_legend\},language_tabs/', '{language_legend},language_tabs,reviewInfo', $palette, 1);
        }

        $dca['config']['onload_callback'][] = [self::class, 'handleReviewAction'];

        $dca['list']['operations'][self::ACTION_KEY] = [
            'label' => &$GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeMarkReviewed'],
            'href' => 'key='.self::ACTION_KEY,
            'icon' => 'ok.svg',
            'button_callback' => [self::class, 'reviewOperation'],
        ];

        // The existing child record renderer keeps working and only gains a badge.
        $existing = $dca['list']['sorting']['child_record_callback'] ?? null;
        if (null !== $existing) {
            $dca['list']['sorting']['contao_multilingual_pagetree_previous_child_record'] = $existing;
            $dca['list']['sorting']['child_record_callback'] = [self::class, 'listChildRecord'];
        }
    }

    /**
     * Registers source-change tracking on a source DCA.
     */
    public static function configureSource(string $sourceTable): void
    {
        if (!isset($GLOBALS['TL_DCA'][$sourceTable])) {
            return;
        }

        $GLOBALS['TL_DCA'][$sourceTable]['config']['onsubmit_callback'][] = [self::class, 'refreshSourceStatus'];
    }

    /**
     * onsubmit_callback of every source table: recomputes the persisted status
     * of that record's translations with a bounded number of statements.
     */
    public function refreshSourceStatus(DataContainer $dc): void
    {
        $sourceTable = (string) $dc->table;
        $sourceId = (int) $dc->id;

        if ($sourceId <= 0) {
            return;
        }

        $source = $this->storage->findSource($sourceTable, $sourceId);

        if (null === $source) {
            return;
        }

        $this->marker->refreshForSource($sourceTable, $sourceId, $source);
    }

    /**
     * onload_callback of every translation table: performs the explicit review
     * action when it was requested.
     */
    public function handleReviewAction(DataContainer $dc): void
    {
        if (self::ACTION_KEY !== Input::get('key')) {
            return;
        }

        $table = (string) $dc->table;
        $id = (int) Input::get('id');

        if ($id <= 0) {
            $id = (int) $dc->id;
        }

        // Transport, token and permission are all verified server side. A GET
        // request can therefore never change review state, so the control is a
        // submitted form rather than a link.
        $denied = $this->actionGuard->denyReason($table);

        if (null !== $denied) {
            $this->message($this->actionGuard->messageKey($denied), 'error');

            throw new AccessDeniedException(sprintf('Review action refused for %s: %s.', $table, $denied));
        }

        $result = $this->marker->markReviewed($table, $id, $this->currentUserId());

        // A failed action keeps the previous status and reports an error.
        $this->message(
            $result->successful
                ? 'contaoMultilingualPagetreeReviewDone'
                : (ReviewActionResult::REASON_SOURCE_MISSING === $result->reason
                    ? 'contaoMultilingualPagetreeReviewSourceMissing'
                    : 'contaoMultilingualPagetreeReviewFailed'),
            $result->successful ? 'confirm' : 'error',
        );

        $this->redirectToRecord($table, $id);
    }

    /**
     * input_field_callback rendering the status panel.
     */
    public function renderReviewPanel(DataContainer $dc): string
    {
        $table = (string) $dc->table;
        $id = (int) $dc->id;
        $state = $this->stateFor($table, $id);

        if (null === $state) {
            return '';
        }

        $reviewUrl = $this->canReview($table) ? $this->actionUrl($table, $id) : null;

        return $this->renderer->panel(
            $state,
            $this->labels($table),
            $reviewUrl,
            $this->reviewerName($state->reviewedBy),
            $this->actionGuard->tokenValue(),
        );
    }

    /**
     * button_callback hiding the action for read-only users and broken relations.
     */
    public function reviewOperation(array $row, ?string $href, string $label, string $title, string $icon, string $attributes): string
    {
        $table = $this->tableOfRow($row);

        if (null === $table || !$this->canReview($table)) {
            return '';
        }

        $state = $this->stateFor($table, (int) ($row['id'] ?? 0));

        if (null === $state || !$state->isReviewable()) {
            return '';
        }

        $url = $this->actionUrl($table, (int) ($row['id'] ?? 0));

        if (null === $url) {
            return '';
        }

        // The list operation posts as well: no destructive or state-changing
        // action of this bundle is reachable through a GET link.
        return $this->renderer->actionForm($url, $this->actionGuard->tokenValue(), $label);
    }

    /**
     * child_record_callback wrapper adding the status badge to list rows.
     */
    public function listChildRecord(array $row): string
    {
        $table = $this->tableOfRow($row);
        $label = '';

        if (null !== $table) {
            $previous = $GLOBALS['TL_DCA'][$table]['list']['sorting']['contao_multilingual_pagetree_previous_child_record'] ?? null;

            if (is_array($previous) && isset($previous[0], $previous[1])) {
                try {
                    $label = (string) System::importStatic($previous[0])->{$previous[1]}($row);
                } catch (\Throwable) {
                    $label = '';
                }
            }
        }

        if (null === $table) {
            return $label;
        }

        // The row Contao already loaded is the translation record, and every
        // source record is read once per request, so a list of N translations
        // never causes N queries. The persisted status is only a hint; the live
        // comparison decides.
        $status = ReviewStatus::fromValue($row[TranslationReviewResolver::FIELD_STATUS] ?? null);
        $sourceTable = $this->fields->sourceTable($table);

        if (null !== $sourceTable) {
            $source = $this->cachedSource($sourceTable, (int) ($row['pid'] ?? 0));
            $status = $this->resolver->resolve($table, $row, $source)->status;
        }

        return $label.$this->renderer->badge($status, $this->labels($table));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedSource(string $sourceTable, int $sourceId): ?array
    {
        if ($sourceId <= 0) {
            return null;
        }

        $key = $sourceTable.'|'.$sourceId;

        if (!array_key_exists($key, $this->sourceCache)) {
            $this->sourceCache[$key] = $this->storage->findSource($sourceTable, $sourceId);
        }

        return $this->sourceCache[$key];
    }

    /**
     * The live review state of one translation, or null when it cannot be read.
     */
    private function stateFor(string $table, int $id): ?ReviewState
    {
        if ($id <= 0 || null === $this->fields->sourceTable($table)) {
            return null;
        }

        $translation = $this->storage->findTranslation($table, $id);

        if (null === $translation) {
            return null;
        }

        $sourceTable = (string) $this->fields->sourceTable($table);
        $source = $this->cachedSource($sourceTable, (int) ($translation['pid'] ?? 0));

        return $this->resolver->resolve($table, $translation, $source);
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $table): array
    {
        $statuses = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReviewStatuses'] ?? [];
        $labels = is_array($statuses) ? array_map(static fn ($value): string => is_string($value) ? $value : '', $statuses) : [];

        foreach (['reviewedAt', 'reviewedBy', 'changedFields', 'reviewedValue', 'currentValue', 'markReviewed', 'sourceMissing'] as $key) {
            $value = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeReview'.ucfirst($key)] ?? null;
            $labels[$key] = is_string($value) ? $value : $key;
        }

        // Field labels come from the translation DCA, never from data.
        foreach (array_keys($this->fields->getPolicy($table)->fields()) as $field) {
            $label = $GLOBALS['TL_DCA'][$table]['fields'][$field]['label'][0] ?? null;
            $labels['field_'.$field] = is_string($label) && '' !== $label ? $label : $field;
        }

        return $labels;
    }

    private function reviewerName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        try {
            $user = $this->storage->findSource('tl_user', $userId);
        } catch (\Throwable) {
            $user = null;
        }

        // A deleted reviewer keeps a neutral historical label.
        if (null === $user) {
            return '#'.$userId;
        }

        $name = $user['name'] ?? $user['username'] ?? null;

        return is_string($name) && '' !== $name ? $name : '#'.$userId;
    }

    private function canReview(string $table): bool
    {
        return $this->actionGuard->mayRenderControl($table);
    }

    private function currentUserId(): int
    {
        try {
            return (int) (BackendUser::getInstance()->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }


    /**
     * Builds the action URL from validated parameters only; a redirect target
     * is never taken from request input.
     */
    private function actionUrl(string $table, int $id): ?string
    {
        return $this->backendUrl($table, $id, [
            'key' => self::ACTION_KEY,
            'act' => null,
        ]);
    }

    /**
     * Redirects back to the translation form. The target is generated from
     * validated parameters, never from request input, so the action cannot be
     * turned into an open redirect.
     */
    private function redirectToRecord(string $table, int $id): void
    {
        $url = $this->backendUrl($table, $id, ['act' => 'edit']) ?? $this->backendUrl($table, 0, []);

        if (null === $url) {
            return;
        }

        throw new RedirectResponseException($url);
    }

    /**
     * @param array<string, string|null> $parameters
     */
    private function backendUrl(string $table, int $id, array $parameters): ?string
    {
        if (null === $this->router) {
            return null;
        }

        $do = Input::get('do');
        $query = ['table' => $table];

        if (is_string($do) && 1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $do)) {
            $query['do'] = $do;
        }

        if ($id > 0) {
            $query['id'] = $id;
        }

        foreach ($parameters as $name => $value) {
            if (null !== $value) {
                $query[$name] = $value;
            }
        }

        try {
            $query['rt'] = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

            return $this->router->generate('contao_backend', $query);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tableOfRow(array $row): ?string
    {
        $table = Input::get('table');

        if (is_string($table) && null !== $this->fields->sourceTable($table)) {
            return $table;
        }

        return null;
    }

    private function message(string $key, string $type): void
    {
        try {
            $text = $GLOBALS['TL_LANG']['MSC'][$key] ?? $key;
            \Contao\Message::{'add'.ucfirst($type)}(is_string($text) ? $text : $key);
        } catch (\Throwable) {
            // Messages are best effort only.
        }
    }

}
