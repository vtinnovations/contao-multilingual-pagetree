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

use Vtinnovations\ContaoMultilingualPagetree\EventListener\ContentTranslationListener;

/**
 * Reproduces the control flow of Contao\Controller::getContentElement().
 *
 *  1. the visibility of the record is checked ("isVisibleElement" hook)
 *  2. the element is rendered exactly once, either by a legacy content element
 *     class or by a fragment controller - the pipeline decides, not the bundle
 *  3. the rendered buffer is passed through the "getContentElement" hook
 *
 * The renderer itself is injected, which lets a single test suite cover legacy
 * elements, fragment controllers, service based elements and nested elements.
 */
class ContentElementRenderPipeline
{
    public int $renderCount = 0;

    /** @var list<int> */
    public array $renderedIds = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $renderedRows = [];

    private \Closure $renderer;

    public function __construct(private ContentTranslationListener $listener, ?callable $renderer = null)
    {
        $this->renderer = null !== $renderer
            ? \Closure::fromCallable($renderer)
            : static fn (object $model): string => '<div class="ce_text">'.(string) $model->text.'</div>';
    }

    public function render(object $model): string
    {
        if (!$this->listener->onIsVisibleElement($model, true)) {
            return '';
        }

        ++$this->renderCount;
        $id = (int) $model->id;
        $this->renderedIds[] = $id;
        $this->renderedRows[$id][] = $model->row();

        $buffer = ($this->renderer)($model, $this);

        return $this->listener->onGetContentElement($model, $buffer, null);
    }

    public function renderCountFor(int $id): int
    {
        return \count($this->renderedRows[$id] ?? []);
    }

    /**
     * The row the renderer was handed for the given element.
     *
     * @return array<string, mixed>
     */
    public function rowFor(int $id): array
    {
        return $this->renderedRows[$id][0] ?? [];
    }
}
