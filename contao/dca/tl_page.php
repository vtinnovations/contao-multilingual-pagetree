<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */



$GLOBALS['TL_DCA']['tl_page']['list']['operations']['languages'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_page']['languages'],
    'href'  => 'table=tl_inline_language',
    'icon'  => 'bundles/vtinnovationscontaomultilingualpagetree/globe.png',
    'button_callback' => ['tl_page_contao_multilingual_pagetree', 'languagesButton'],
];

// Decorate the label_callback to add flag/language badges in the backend page tree
if (isset($GLOBALS['TL_DCA']['tl_page']['list']['label']['label_callback'])) {
    $GLOBALS['TL_DCA']['tl_page']['list']['label']['previous_label_callback'] = $GLOBALS['TL_DCA']['tl_page']['list']['label']['label_callback'];
}
$GLOBALS['TL_DCA']['tl_page']['list']['label']['label_callback'] = ['tl_page_contao_multilingual_pagetree', 'addTranslationBadges'];

class tl_page_contao_multilingual_pagetree extends \Contao\Backend
{
    public function addTranslationBadges(array $row, string $label, \Contao\DataContainer $dc=null, string $imageAttribute='', bool $blnReturnImage=false, bool $blnProtected=false): string
    {
        $previousCallback = $GLOBALS['TL_DCA']['tl_page']['list']['label']['previous_label_callback'] ?? null;
        if (is_array($previousCallback) && class_exists($previousCallback[0])) {
            $instance = new $previousCallback[0]();
            $label = $instance->{$previousCallback[1]}($row, $label, $dc, $imageAttribute, $blnReturnImage, $blnProtected);
        }

        if ($blnReturnImage) {
            return $label;
        }

        $badges = '';

        if (($row['type'] ?? '') === 'root') {
            $languages = \Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel::findPublishedByPid((int) $row['id']);
            if ($languages !== null) {
                foreach ($languages as $lang) {
                    $bg = $lang->fallback ? '#0284c7' : '#e0f2fe';
                    $color = $lang->fallback ? '#ffffff' : '#0369a1';
                    $title = $lang->label . ($lang->fallback ? ' (Fallback)' : '');
                    $badges .= '<span style="background:' . $bg . ';color:' . $color . ';font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:600;vertical-align:middle;" title="' . \Contao\StringUtil::specialchars($title) . '">' . strtoupper($lang->language) . '</span>';
                }
            }
        } else {
            $pageModel = \Contao\PageModel::findByPk((int) $row['id']);
            if ($pageModel) {
                try {
                    $pageModel->loadDetails();
                    $rootId = $pageModel->type === 'root' ? (int) $pageModel->id : (int) $pageModel->rootId;
                    if ($rootId) {
                        $publishedModels = \Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel::findPublishedByPid($rootId);
                        $publishedLangs = [];
                        if ($publishedModels !== null) {
                            foreach ($publishedModels as $pm) {
                                $publishedLangs[] = $pm->language;
                            }
                        }

                        $fallbackLangModel = \Vtinnovations\ContaoMultilingualPagetree\Model\MultilingualPagetreeModel::findFallbackByPid($rootId);
                        $defaultLangCode = $fallbackLangModel ? $fallbackLangModel->language : ($pageModel->rootLanguage ?: ($pageModel->language ?: 'de'));

                        $aliasInfo = !empty($row['alias']) ? ' [/' . $row['alias'] . ']' : '';
                        $title = 'Default (' . ($row['title'] ?? '') . $aliasInfo . ')';
                        $badges .= '<span style="background:#e0f2fe;color:#0369a1;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:600;vertical-align:middle;" title="' . \Contao\StringUtil::specialchars($title) . '">' . strtoupper($defaultLangCode) . '</span>';

                        $translations = \Vtinnovations\ContaoMultilingualPagetree\Model\PageTranslationModel::findByPid((int) $row['id']);
                        if ($translations !== null) {
                            foreach ($translations as $trans) {
                                // Only show badges for languages currently published under this root page
                                if (!in_array($trans->language, $publishedLangs, true)) {
                                    continue;
                                }
                                $isPublished = ($trans->published !== '' && $trans->published !== '0' && $trans->published !== 0 && $trans->published !== false && $trans->published !== null);
                                $bg = $isPublished ? '#dcfce7' : '#fef2f2';
                                $color = $isPublished ? '#15803d' : '#991b1b';
                                $pubStatus = $isPublished ? '' : ' (Unpublished)';
                                $aliasInfo = $trans->alias ? ' [/' . $trans->language . '/' . $trans->alias . ']' : '';
                                $title = $trans->title . $aliasInfo . $pubStatus;
                                $badges .= '<span style="background:' . $bg . ';color:' . $color . ';font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:600;vertical-align:middle;" title="' . \Contao\StringUtil::specialchars($title) . '">' . strtoupper($trans->language) . ($isPublished ? '' : ' (Off)') . '</span>';
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore if details cannot be loaded
                }
            }
        }

        return $label . $badges;
    }

    public function languagesButton(array $row, ?string $href, string $label, string $title, string $icon, string $attributes): string
    {
        $href = (string)$href;
        if ($row['type'] !== 'root') {
            return '';
        }
        try {
            $manager = \Contao\System::getContainer()->get(\Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca::class);
            if (!$manager->canManageRoot((int) $row['id'])) {
                return '';
            }
        } catch (\Throwable) {
            return '';
        }
        return '<a href="'.$this->addToUrl($href.'&amp;id='.$row['id']).'" title="'.\Contao\StringUtil::specialchars($title).'"'.$attributes.'>'.\Contao\Image::getHtml($icon, $label, 'style="width:16px;height:16px;"').'</a> ';
    }
}

$GLOBALS['TL_DCA']['tl_page']['config']['onload_callback'][] = ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'handleTranslationRedirection'];
$GLOBALS['TL_DCA']['tl_page']['fields']['language_tabs'] = [
    'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs', 'renderTabs']
];
$GLOBALS['TL_DCA']['tl_page']['fields']['additional_languages'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_page']['additional_languages'],
    'input_field_callback' => ['Vtinnovations\ContaoMultilingualPagetree\Backend\SiteLanguageDca', 'renderRootManager'],
    'sql' => null,
];
$GLOBALS['TL_DCA']['tl_page']['fields'][\Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette::LICENCE_FIELD] = [
    'label' => &$GLOBALS['TL_LANG']['tl_page']['contaoMultilingualPagetreeLicencePanel'],
    'input_field_callback' => [\Vtinnovations\ContaoMultilingualPagetree\Backend\RootLicenseDca::class, 'render'],
    'sql' => null,
];

// Assemble root controls at onload time, when the backend user and record
// context are both available. The callback is idempotent and preserves later
// third-party palette additions.
$rootPaletteCallback = [\Vtinnovations\ContaoMultilingualPagetree\Backend\PageRootPalette::class, 'register'];
$rootPaletteCallbacks = &$GLOBALS['TL_DCA']['tl_page']['config']['onload_callback'];
if (!is_array($rootPaletteCallbacks)) {
    $rootPaletteCallbacks = [];
}
if (!in_array($rootPaletteCallback, $rootPaletteCallbacks, true)) {
    $rootPaletteCallbacks[] = $rootPaletteCallback;
}
unset($rootPaletteCallback, $rootPaletteCallbacks);

// Source-change tracking for connected translations (review status only).
\Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationReviewDca::configureSource('tl_page');
