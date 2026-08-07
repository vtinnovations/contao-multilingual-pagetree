<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


// Compatibility: existing inlineLang* fields are retained to preserve module data.

use Vtinnovations\ContaoMultilingualPagetree\Switcher\UnavailableLanguageDisplay;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\SwitcherStyle;

$GLOBALS['TL_DCA']['tl_module']['palettes']['language_switcher'] = '{title_legend},name,headline,type;{config_legend},inlineLangStyle,unavailableLanguageDisplay,inlineLangHideActive;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID';

// Presentation of languages the current resource is not available in.
$GLOBALS['TL_DCA']['tl_module']['fields']['unavailableLanguageDisplay'] = [
    'label'         => &$GLOBALS['TL_LANG']['tl_module']['unavailableLanguageDisplay'],
    'exclude'       => true,
    'inputType'     => 'select',
    'options'       => [UnavailableLanguageDisplay::Hide->value, UnavailableLanguageDisplay::Disabled->value],
    'reference'     => &$GLOBALS['TL_LANG']['tl_module']['unavailableLanguageDisplays'],
    'eval'          => ['tl_class' => 'w50', 'includeBlankOption' => false],
    'save_callback' => [
        static fn ($value) => UnavailableLanguageDisplay::fromValue($value)->value,
    ],
    'sql'           => "varchar(16) NOT NULL default '".UnavailableLanguageDisplay::Hide->value."'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['inlineLangStyle'] = [
    'label'         => &$GLOBALS['TL_LANG']['tl_module']['inlineLangStyle'],
    'exclude'       => true,
    'inputType'     => 'select',
    'options'       => SwitcherStyle::values(),
    'reference'     => &$GLOBALS['TL_LANG']['tl_module']['inlineLangStyles'],
    'eval'          => ['tl_class' => 'w50', 'includeBlankOption' => false],
    'save_callback' => [static fn ($value): string => SwitcherStyle::fromValue($value)->value],
    'sql'           => "varchar(32) NOT NULL default 'horizontal_flags'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['inlineLangHideActive'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['inlineLangHideActive'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
    'sql'       => ['type' => 'boolean', 'default' => false],
];
