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

namespace Vtinnovations\ContaoMultilingualPagetree\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\LanguageSwitcherBuilder;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\UnavailableLanguageDisplay;
use Vtinnovations\ContaoMultilingualPagetree\Switcher\SwitcherStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(category: 'miscellaneous', type: 'language_switcher', template: 'mod_language_switcher')]
class LanguageSwitcherController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly LanguageSwitcherBuilder $switcherBuilder,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $GLOBALS['TL_CSS'][] = 'bundles/vtinnovationscontaomultilingualpagetree/css/language-switcher.css';

        $pageModel = $this->languageHelper->getCurrentPageModel();
        if ($pageModel === null) {
            return new Response('');
        }

        $languages = $this->switcherBuilder->buildTemplateData(
            $request,
            $pageModel,
            UnavailableLanguageDisplay::fromValue($model->unavailableLanguageDisplay ?? null),
            (bool) $model->inlineLangHideActive,
        );

        if ($languages === []) {
            return new Response('');
        }

        $template->set('languages', $languages);
        $template->set('activeLanguage', $this->languageHelper->getActiveLanguage());
        $style = SwitcherStyle::fromValue($model->inlineLangStyle ?? null);
        $template->set('style', $style->value);
        $template->set('orientation', $style->orientation());
        $template->set('presentation', $style->presentation());
        $template->set('hideActive', (bool) $model->inlineLangHideActive);
        $template->set('labels', $this->accessibleLabels());

        return $template->getResponse();
    }

    /**
     * Translated accessible labels; nothing user facing is hardcoded here.
     *
     * @return array<string, string>
     */
    private function accessibleLabels(): array
    {
        try {
            System::loadLanguageFile('default');
        } catch (\Throwable) {
            // Fall back to the neutral defaults below.
        }

        $labels = $GLOBALS['TL_LANG']['MSC'] ?? [];

        return [
            'switcher' => $this->label($labels, 'contaoMultilingualPagetreeSwitcherLabel', 'Language switcher'),
            'current' => $this->label($labels, 'contaoMultilingualPagetreeCurrentLanguage', 'Current language'),
            'unavailable' => $this->label($labels, 'contaoMultilingualPagetreeUnavailableLanguage', 'Not available in this language'),
        ];
    }

    /**
     * @param array<string, mixed> $labels
     */
    private function label(array $labels, string $key, string $default): string
    {
        $value = $labels[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
