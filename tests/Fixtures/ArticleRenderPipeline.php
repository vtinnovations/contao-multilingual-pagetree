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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Vtinnovations\ContaoMultilingualPagetree\EventListener\ArticleTranslationListener;

/**
 * Reproduces the control flow of Contao\Controller::getArticle() together with
 * ModuleArticle.
 *
 *  1. optional visibility check ("isVisibleElement" hook)
 *  2. Contao copies the article title into "headline"
 *  3. the "getArticle" hook runs on the article row
 *  4. the article module copies that row and renders its content elements once,
 *     in the order Contao provides them
 *  5. the "compileArticle" hook runs on the finished template data
 */
class ArticleRenderPipeline
{
    public int $renderCount = 0;

    /** @var array<string, mixed> */
    public array $moduleData = [];

    /** @var array<string, mixed> */
    public array $templateData = [];

    public bool $templateUsed = false;

    public function __construct(
        private ArticleTranslationListener $listener,
        private ContentElementRenderPipeline $elements,
        private bool $checkVisibility = false,
    ) {
    }

    /**
     * @param list<object> $contentElements
     */
    public function render(object $article, array $contentElements = []): string
    {
        if ($this->checkVisibility && !$this->listener->onIsVisibleElement($article, true)) {
            return '';
        }

        $article->headline = $article->title;
        $this->listener->onGetArticle($article);

        // ModuleArticle copies the row before rendering anything.
        $this->moduleData = $article->row();
        ++$this->renderCount;

        $rendered = [];

        foreach ($contentElements as $element) {
            $rendered[] = $this->elements->render($element);
        }

        $this->templateData = [
            'headline' => (string) ($this->moduleData['headline'] ?? ''),
            'elements' => $rendered,
        ];
        $this->templateUsed = true;

        $template = new \stdClass();
        $module = new \stdClass();
        $this->listener->onCompileArticle($template, $this->moduleData, $module);

        $headline = '' !== $this->templateData['headline'] ? '<h1>'.$this->templateData['headline'].'</h1>' : '';

        return '<div class="mod_article">'.$headline.implode('', $rendered).'</div>';
    }
}
