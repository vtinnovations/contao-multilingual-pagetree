<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


// Compatibility: this DCA and class retain the persisted tl_inline_language table name.

use Contao\DC_Table;
use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFallbackMode;
use Vtinnovations\ContaoMultilingualPagetree\Url\ProtocolMode;

$GLOBALS['TL_DCA']['tl_inline_language'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_page',
        'enableVersioning' => true,
        'onload_callback'  => [
            [\Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca::class, 'guardEditScope'],
        ],
        'ondelete_callback' => [
            [\Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca::class, 'guardDelete'],
        ],
        'sql'              => [
            'keys' => [
                'id'  => 'primary',
                'pid' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 4, // MODE_PARENT
            'fields'      => ['sorting'],
            'headerFields'=> ['title', 'type', 'language'],
            'panelLayout' => 'filter;search,limit',
            'child_record_callback' => [\Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca::class, 'listLanguage'],
        ],
        'label' => [
            'fields' => ['label', 'language'],
            'format' => '%s [%s]',
        ],
        'global_operations' => [
            'all' => [
                'label'      => &$GLOBALS['TL_LANG']['MSC']['all'],
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit' => [
                'label' => &$GLOBALS['TL_LANG']['tl_inline_language']['edit'],
                'href'  => 'act=edit',
                'icon'  => 'edit.svg',
            ],
            'delete' => [
                'label'      => &$GLOBALS['TL_LANG']['tl_inline_language']['delete'],
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . '\'))return false;Backend.getScrollOffset()"',
            ],
            'toggle' => [
                'label'           => &$GLOBALS['TL_LANG']['tl_inline_language']['toggle'],
                'icon'            => 'visible.svg',
                'attributes'      => 'onclick="Backend.getScrollOffset();return AjaxRequest.toggleVisibility(this,%s)"',
                'button_callback' => ['tl_inline_language', 'toggleIcon'],
            ],
        ],
    ],
    'palettes' => [
        'default' => '{language_legend},language,label,flag,language_selector_config;{url_legend},urlProtocol,urlDomain,urlEntryPoint;{availability_legend},pageAvailabilityMode,contentFallbackMode,contentTranslationMode;{publish_legend},published',
    ],
    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment",
        ],
        'pid' => [
            'foreignKey' => 'tl_page.title',
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'sorting' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'language' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_inline_language']['language'],
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'select',
            'options_callback' => [\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider::class, 'languageOptions'],
            'eval'      => ['mandatory' => true, 'maxlength' => 7, 'tl_class' => 'w50', 'chosen' => true, 'includeBlankOption' => true],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca::class, 'validateLanguage']],
            'sql'       => "varchar(7) NOT NULL default ''",
        ],
        'label' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_inline_language']['label'],
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 255, 'tl_class' => 'w50'],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider::class, 'fillLanguageLabel']],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'flag' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_inline_language']['flag'],
            'exclude'   => true,
            'inputType' => 'select',
            'options_callback' => [\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider::class, 'flagOptions'],
            'eval'      => ['maxlength' => 10, 'tl_class' => 'w50', 'includeBlankOption' => true],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider::class, 'validateFlag']],
            'sql'       => "varchar(10) NOT NULL default ''",
        ],
        'language_selector_config' => [
            'input_field_callback' => [\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageAndFlagChoiceProvider::class, 'renderAutomationConfig'],
            'sql' => null,
        ],
        // Language URL mapping. All three fields are optional; while they are
        // empty the record keeps exactly the URL behaviour it had before the
        // fields existed. Normalisation and collision validation happen in the
        // central services, never in this file.
        'urlProtocol' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocol'],
            'exclude'       => true,
            'inputType'     => 'select',
            'options'       => [ProtocolMode::Inherit->value, ProtocolMode::Https->value, ProtocolMode::Http->value],
            'reference'     => &$GLOBALS['TL_LANG']['tl_inline_language']['urlProtocols'],
            'eval'          => ['tl_class' => 'w50', 'includeBlankOption' => false, 'helpwizard' => false],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageUrlDca::class, 'validateProtocol']],
            'sql'           => "varchar(8) NOT NULL default ''",
        ],
        'urlDomain' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['urlDomain'],
            'exclude'       => true,
            'search'        => true,
            'inputType'     => 'text',
            'eval'          => ['maxlength' => 255, 'decodeEntities' => true, 'tl_class' => 'w50'],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageUrlDca::class, 'validateDomain']],
            'sql'           => "varchar(255) NOT NULL default ''",
        ],
        'urlEntryPoint' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['urlEntryPoint'],
            'exclude'       => true,
            'search'        => true,
            'inputType'     => 'text',
            'eval'          => ['maxlength' => 191, 'decodeEntities' => true, 'tl_class' => 'w50 clr'],
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageUrlDca::class, 'validateEntryPoint']],
            'sql'           => "varchar(191) NOT NULL default ''",
        ],
        'fallback' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_inline_language']['fallback'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50', 'submitOnChange' => true],
            'sql'       => ['type' => 'boolean', 'default' => false],
        ],
        // Only meaningful for non-default languages; the default language always
        // uses the source page tree.
        'pageAvailabilityMode' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityMode'],
            'exclude'       => true,
            'inputType'     => 'select',
            'options'       => [PageAvailabilityMode::Strict->value, PageAvailabilityMode::Fallback->value],
            'reference'     => &$GLOBALS['TL_LANG']['tl_inline_language']['pageAvailabilityModes'],
            'eval'          => ['tl_class' => 'w50', 'includeBlankOption' => false, 'helpwizard' => false],
            'save_callback' => [['tl_inline_language', 'normaliseAvailabilityMode']],
            'sql'           => "varchar(16) NOT NULL default '".PageAvailabilityMode::Fallback->value."'",
        ],
        // Content localisation strategy; only meaningful for non-default languages.
        'contentTranslationMode' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationMode'],
            'exclude'       => true,
            'inputType'     => 'select',
            'options'       => [ContentTranslationMode::Connected->value, ContentTranslationMode::Free->value],
            'reference'     => &$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModes'],
            'eval'          => ['tl_class' => 'w50 clr', 'includeBlankOption' => false, 'helpwizard' => false, 'submitOnChange' => true],
            'save_callback' => [['tl_inline_language', 'normaliseContentTranslationMode']],
            'sql'           => "varchar(16) NOT NULL default '".ContentTranslationMode::Connected->value."'",
        ],
        'contentFallbackMode' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackMode'],
            'exclude'       => true,
            'inputType'     => 'select',
            'options'       => [ContentFallbackMode::Strict->value, ContentFallbackMode::Fallback->value],
            'reference'     => &$GLOBALS['TL_LANG']['tl_inline_language']['contentFallbackModes'],
            'eval'          => ['tl_class' => 'w50', 'includeBlankOption' => false, 'helpwizard' => false],
            'save_callback' => [static fn ($value): string => ContentFallbackMode::fromValue($value)->value],
            'sql'           => "varchar(16) NOT NULL default '".ContentFallbackMode::Fallback->value."'",
        ],
        // Not persisted: an explicit, server side confirmation of a mode change.
        'contentTranslationModeConfirm' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_inline_language']['contentTranslationModeConfirm'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50', 'doNotCopy' => true],
            'sql'       => null,
        ],
        'published' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_inline_language']['published'],
            'exclude'       => true,
            'filter'        => true,
            'inputType'     => 'checkbox',
            'eval'          => ['doNotCopy' => true, 'submitOnChange' => true],
            // Publishing is what makes a mapping claim a URL, so the same
            // collision rules apply here as to the URL fields themselves.
            'save_callback' => [[\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageUrlDca::class, 'validatePublished']],
            'sql'           => ['type' => 'boolean', 'default' => false],
        ],
    ],
];

$GLOBALS['TL_JAVASCRIPT']['contao_multilingual_pagetree_language_flag_selector'] = 'bundles/vtinnovationscontaomultilingualpagetree/js/language-flag-selector.js';

class tl_inline_language extends \Contao\Backend
{
    /**
     * The page-availability mode is shown only where it is meaningful, i.e. for
     * languages that are not the default language of the site.
     */
    public function hideAvailabilityModeForDefaultLanguage(?\Contao\DataContainer $dc = null): void
    {
        if ($dc === null || !$dc->id || \Contao\Input::get('act') !== 'edit') {
            return;
        }

        if (!$this->isDefaultLanguageRecord((int) $dc->id)) {
            return;
        }

        $palettes = &$GLOBALS['TL_DCA']['tl_inline_language']['palettes'];
        foreach ($palettes as $key => $palette) {
            if (is_string($palette)) {
                $palettes[$key] = str_replace('{availability_legend},pageAvailabilityMode,contentFallbackMode,contentTranslationMode,contentTranslationModeConfirm;', '', $palette);
            }
        }
    }

    /**
     * Invalid submitted values normalise to "fallback"; the default language
     * never persists a mode.
     */
    /**
     * Rejects an unconfirmed or unauthorised mode change and normalises the
     * value. Switching modes never deletes data; it only changes which content
     * tree renders.
     */
    public function normaliseContentTranslationMode($varValue, \Contao\DataContainer $dc)
    {
        if ($this->isDefaultLanguageRecord((int) $dc->id)) {
            return \Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode::Connected->value;
        }

        $mode = \Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode::fromValue($varValue);

        try {
            $guard = \Contao\System::getContainer()->get(
                \Vtinnovations\ContaoMultilingualPagetree\Content\ModeSwitchGuard::class,
            );

            return $guard->confirmSwitch((int) $dc->id, $mode, $this->currentValue((int) $dc->id))->value;
        } catch (\Vtinnovations\ContaoMultilingualPagetree\Content\ModeSwitchDeniedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // Without the guard service the value is still normalised safely.
            return $mode->value;
        }
    }

    private function currentValue(int $id): ?string
    {
        try {
            $record = $this->Database->prepare('SELECT contentTranslationMode FROM tl_inline_language WHERE id=?')->execute($id);

            return $record->numRows ? (string) $record->contentTranslationMode : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function normaliseAvailabilityMode($varValue, \Contao\DataContainer $dc)
    {
        if ($this->isDefaultLanguageRecord((int) $dc->id)) {
            return '';
        }

        return \Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode::fromValue($varValue)->value;
    }

    private function isDefaultLanguageRecord(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $record = $this->Database->prepare('SELECT fallback FROM tl_inline_language WHERE id=?')->execute($id);

            return (bool) $record->numRows && (bool) $record->fallback;
        } catch (\Throwable) {
            return false;
        }
    }

    public function listLanguages(array $row): string
    {
        $flag = $row['flag'] ? '<img src="bundles/vtinnovationscontaomultilingualpagetree/flags/' . $row['flag'] . '.svg" width="16" height="11" alt=""> ' : '';
        return '<div class="tl_content_left">' . $flag . $row['label'] . ' <span style="color:#999;padding-left:3px">[' . $row['language'] . ']</span>' . ($row['fallback'] ? ' <strong>(' . ($GLOBALS['TL_LANG']['tl_inline_language']['fallback'][0] ?? 'Fallback') . ')</strong>' : '') . '</div>';
    }

    public function toggleIcon(array $row, ?string $href, string $label, string $title, string $icon, string $attributes): string
    {
        $href = (string)$href;
        if (strlen(\Contao\Input::get('tid'))) {
            try {
                $this->toggleVisibility((int)\Contao\Input::get('tid'), (\Contao\Input::get('state') == 1));
            } catch (\InvalidArgumentException $exception) {
                \Contao\Message::addError($exception->getMessage());
            }
            $this->redirect($this->getReferer());
        }
        $href .= '&amp;tid='.$row['id'].'&amp;state='.($row['published'] ? '' : 1);
        if (!$row['published']) {
            $icon = 'invisible.svg';
        }
        return '<a href="'.$this->addToUrl($href).'" title="'.\Contao\StringUtil::specialchars($title).'"'.$attributes.'>'.\Contao\Image::getHtml($icon, $label).'</a> ';
    }

    public function toggleVisibility(int $intId, bool $blnVisible, ?\Contao\DataContainer $dc=null): void
    {
        $blnVisible = \Contao\System::getContainer()
            ->get(\Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageUrlDca::class)
            ->validatePublishedState($intId, $blnVisible);
        $objVersions = new \Contao\Versions('tl_inline_language', $intId);
        $objVersions->initialize();
        $time = time();
        $this->Database->prepare("UPDATE tl_inline_language SET tstamp=$time, published='" . ($blnVisible ? '1' : '') . "' WHERE id=?")->execute($intId);
        $objVersions->create();
    }
}
