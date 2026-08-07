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
use Contao\DataContainer;
use Contao\Database;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\RouterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;

/** Root-scoped backend workflow for the legacy tl_inline_language table. */
final class SiteLanguageDca
{
    /**
     * Actions whose `id` identifies one language record rather than its parent.
     *
     * @var list<string>
     */
    private const RECORD_ACTIONS = ['edit', 'delete', 'toggle', 'show', 'copy', 'cut'];


    public function __construct(
        private readonly RouterInterface $router,
        private readonly BackendActionGuard $guard,
        private readonly CapabilityPolicy $capabilities,
        private readonly RootScope $licenceContext,
        private readonly RootDomainRegistry $rootDomains,
        private readonly RootPageContext $pages,
        private readonly LanguageAndFlagChoiceProvider $languageChoices,
    ) {
    }

    /**
     * The language summary inside Contao's own language section.
     *
     * It shows the source language, how many additional languages exist and, for
     * an unlicensed installation, a short notice with an optional link to the
     * licence section. Licence status, domain, term, key and every activation
     * control belong to the dedicated section and are deliberately absent here.
     *
     * The record is resolved through {@see RootPageContext} instead of through
     * the deprecated `DataContainer::$activeRecord`.
     */
    public function renderRootManager(DataContainer $dc): string
    {
        $rootId = $this->pages->currentId($dc);

        if ($rootId <= 0 || !$this->pages->isRootPage($rootId)) {
            return '';
        }

        $this->selectLicenceScope($rootId);
        $source = $this->pages->rootLanguage($rootId);
        $count = $this->additionalLanguageCount($rootId, $source);
        $labels = $this->labels();
        $html = '<div class="cmp-root-languages">';
        $html .= '<p><strong>'.StringUtil::specialchars($labels['source']).':</strong> '.StringUtil::specialchars(strtoupper($source)).'</p>';
        $html .= '<p>'.StringUtil::specialchars(0 === $count ? $labels['empty'] : sprintf($labels['count'], $count)).'</p>';

        $licensed = $this->capabilities->allows(Capability::TranslationEditing);
        $canManageLanguages = $this->canManageRoot($rootId);
        $languageUrl = $this->router->generate('contao_backend', ['do' => 'page', 'table' => 'tl_inline_language', 'id' => $rootId]);

        $html .= self::renderContextActions($labels, $rootId, $licensed, $canManageLanguages, $languageUrl);

        return $html.'</div>';
    }

    /**
     * @param array<string, string> $labels
     */
    public static function renderContextActions(array $labels, int $rootId, bool $licensed, bool $canManageLanguages, string $languageUrl): string
    {
        $escape = static fn (string $value): string => StringUtil::specialchars($value);

        if ($licensed) {
            return $canManageLanguages
                ? '<p><a class="tl_submit" href="'.$escape($languageUrl).'">'.$escape($labels['manage'] ?? '').'</a></p>'
                : '';
        }

        // Without a valid licence the section states the requirement and stops.
        // It deliberately offers no navigation control of its own: the licence
        // section of this same form is where the controls live, and a second
        // entry point would only duplicate them.
        return '<p class="tl_info">'.$escape($labels['licenceRequired'] ?? '').'</p>';
    }

    public function guardEditScope(?DataContainer $dc = null): void
    {
        $rootId = $this->rootId($dc);
        $this->selectLicenceScope($rootId);
        if (!$this->canManageRoot($rootId)) {
            throw new AccessDeniedHttpException($this->labels()['denied']);
        }

        $action = (string) Input::get('act');
        $licensed = $this->capabilities->allows(Capability::TranslationEditing);

        // Parent-mode DC_Table creates the one canonical "new" operation. The
        // table is closed only for this current parent when creation is not
        // licensed; no per-root operation is ever generated here.
        self::applyCreateAvailability($GLOBALS['TL_DCA']['tl_inline_language']['config'], $licensed);

        if (in_array($action, ['create', 'edit', 'copy'], true) && !$licensed) {
            throw new AccessDeniedHttpException($this->labels()['licenceRequired']);
        }
    }

    public function guardDelete(DataContainer $dc): void
    {
        $this->assertRecordWrite((int) $dc->id);
    }

    public function assertRecordWrite(int $recordId): void
    {
        try {
            $record = Database::getInstance()->prepare('SELECT pid FROM tl_inline_language WHERE id=?')->execute($recordId);
            $rootId = $record->numRows ? (int) $record->pid : 0;
        } catch (\Throwable) {
            $rootId = 0;
        }
        $this->selectLicenceScope($rootId);
        if (!$this->canManageRoot($rootId) || !$this->capabilities->allows(Capability::TranslationEditing)) {
            throw new AccessDeniedHttpException($this->labels()['denied']);
        }
    }

    public function validateLanguage(mixed $value, DataContainer $dc): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->labels()['invalid']);
        }
        $language = LanguageAndFlagChoiceProvider::normalizeLanguage($value);
        $existing = is_string($dc->activeRecord->language ?? null) ? (string) $dc->activeRecord->language : null;
        if (1 !== preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language)
            || !$this->languageChoices->isKnownLanguage($language, $existing)) {
            throw new \InvalidArgumentException($this->labels()['invalid']);
        }

        $rootId = $this->rootId($dc);
        $this->selectLicenceScope($rootId);
        if (!$this->canManageRoot($rootId) || !$this->capabilities->allows(Capability::TranslationEditing)) {
            throw new AccessDeniedHttpException($this->labels()['denied']);
        }

        $database = Database::getInstance();
        $root = $database->prepare("SELECT type, language FROM tl_page WHERE id=?")->execute($rootId);
        if (!$root->numRows || 'root' !== (string) $root->type) {
            throw new AccessDeniedHttpException($this->labels()['rootRequired']);
        }
        if (0 === strcasecmp(str_replace('_', '-', (string) $root->language), $language)) {
            throw new \InvalidArgumentException($this->labels()['sourceDuplicate']);
        }

        $duplicate = $database->prepare('SELECT id FROM tl_inline_language WHERE pid=? AND language=? AND id!=?')
            ->execute($rootId, $language, (int) ($dc->id ?? 0));
        if ($duplicate->numRows) {
            throw new \InvalidArgumentException($this->labels()['duplicate']);
        }

        return $language;
    }

    public function listLanguage(array $row): string
    {
        System::loadLanguageFile('tl_inline_language');
        $labels = $this->labels();
        $active = !empty($row['published']) ? $labels['active'] : $labels['inactive'];
        $mode = (string) ($row['pageAvailabilityMode'] ?? 'fallback');
        $content = (string) ($row['contentTranslationMode'] ?? 'connected');
        $mode = (string) ($GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModes'][$mode] ?? $mode);
        $content = (string) ($GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModes'][$content] ?? $content);

        return sprintf(
            '<div class="tl_content_left"><strong>%s</strong> [%s] <span class="tl_gray">%s · %s · %s</span></div>',
            StringUtil::specialchars((string) ($row['label'] ?? '')),
            StringUtil::specialchars(strtoupper((string) ($row['language'] ?? ''))),
            StringUtil::specialchars($labels['target'].' · '.$active),
            StringUtil::specialchars($mode),
            StringUtil::specialchars($content),
        );
    }

    public function canManageRoot(int $rootId): bool
    {
        if ($rootId <= 0 || !$this->rootDomains->isRoot($rootId) || !$this->guard->mayRenderControl('tl_inline_language')) {
            return false;
        }
        try {
            $user = BackendUser::getInstance();
            if (true === ($user->isAdmin ?? false)) {
                return true;
            }

            return (bool) $user->hasAccess((string) $rootId, 'pagemounts');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $config */
    public static function applyCreateAvailability(array &$config, bool $licensed): void
    {
        $marker = '_cmp_closed_by_root_licence';
        $original = '_cmp_original_closed';
        $hadOriginal = '_cmp_had_original_closed';

        if (!$licensed) {
            if (true !== ($config[$marker] ?? false)) {
                $config[$hadOriginal] = array_key_exists('closed', $config);
                $config[$original] = $config['closed'] ?? null;
            }
            $config[$marker] = true;
            $config['closed'] = true;

            return;
        }

        if (true === ($config[$marker] ?? false)) {
            if (true === ($config[$hadOriginal] ?? false)) {
                $config['closed'] = $config[$original];
            } else {
                unset($config['closed']);
            }
            unset($config[$marker], $config[$original], $config[$hadOriginal]);
        }
    }

    /**
     * The site root a request works on.
     *
     * `activeRecord` is used when it is populated - inside save and edit
     * callbacks - but it is empty while `onload_callback` runs, so the relation
     * is read directly for the actions whose id identifies one language record.
     * Without that fallback an onload guard evaluates the language record's own
     * id as if it were a site root and denies access to a legitimate editor.
     */
    private function rootId(?DataContainer $dc): int
    {
        if (null === $dc) {
            return 0;
        }
        $pid = (int) ($dc?->activeRecord->pid ?? 0);
        if ($pid > 0) {
            return $pid;
        }
        $contextId = is_numeric($dc->id ?? null) ? (int) $dc->id : 0;

        if ($contextId > 0 && in_array((string) Input::get('act'), self::RECORD_ACTIONS, true)) {
            $parent = $this->languageRecordParent($contextId);

            if ($parent > 0) {
                return $parent;
            }
        }

        $requestedId = Input::get('id');
        if (is_numeric($requestedId) && $contextId > 0 && (int) $requestedId !== $contextId) {
            return 0;
        }

        return $contextId;
    }

    private function languageRecordParent(int $recordId): int
    {
        try {
            $record = Database::getInstance()->prepare('SELECT pid FROM tl_inline_language WHERE id=?')->execute($recordId);

            return $record->numRows ? (int) $record->pid : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function additionalLanguageCount(int $rootId, string $source): int
    {
        try {
            return (int) Database::getInstance()
                ->prepare('SELECT COUNT(*) FROM tl_inline_language WHERE pid=? AND language!=?')
                ->execute($rootId, $source)
                ->fetchField();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function selectLicenceScope(int $rootId): void
    {
        $domain = $this->rootDomains->domain($rootId);
        if (null !== $domain) {
            $this->licenceContext->select($rootId, $domain);
        } else {
            $this->licenceContext->clear();
        }
        $this->capabilities->reset();
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        System::loadLanguageFile('default');

        return $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeBackend'] ?? [];
    }
}
