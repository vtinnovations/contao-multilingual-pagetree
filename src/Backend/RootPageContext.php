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

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Input;
use Contao\PageModel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves which page record a backend request is working on, at any point of
 * the DCA lifecycle.
 *
 * This exists because `DataContainer::$activeRecord` is **not** available while
 * `config.onload_callback` runs: Contao executes those callbacks in the data
 * container's constructor and only loads the row later, inside `edit()`. Code
 * that decides anything from `$dc->activeRecord` during onload therefore always
 * sees an empty record, silently behaves as if the page were not a site root and
 * leaves the palette untouched. `activeRecord` is deprecated in Contao 5.3 on
 * top of that.
 *
 * The resolution order here is therefore: the data container's own id, then the
 * request, and the record itself is read through `PageModel` - never through
 * `activeRecord`. Lookups are memoised per request, so repeated DCA loading and
 * several callbacks in one request cost one query per page id.
 *
 * Nothing happens in the constructor: no request access, no query, no I/O.
 */
final class RootPageContext implements ResetInterface
{
    /**
     * Actions that render a form for a record which does not exist yet, so no
     * persisted id can be resolved for them.
     *
     * @var list<string>
     */
    private const CREATE_ACTIONS = ['create', 'copy', 'copyAll', 'cut', 'cutAll', 'paste'];

    /** @var array<int, array<string, mixed>|null> */
    private array $records = [];

    public function __construct(private readonly ?ContaoFramework $framework = null)
    {
    }

    /**
     * The id of the record this request edits, or 0 when there is none yet.
     */
    public function currentId(?DataContainer $dc = null): int
    {
        return self::resolveId($this->containerId($dc), $this->requestId(), $this->action());
    }

    /**
     * Whether the request renders a form for a record that is not persisted.
     */
    public function isCreating(?DataContainer $dc = null): bool
    {
        return self::resolveCreating($this->containerId($dc), $this->requestId(), $this->action());
    }

    /**
     * Pure id resolution, so the lifecycle rules can be asserted without a
     * running Contao installation.
     */
    public static function resolveId(int $containerId, mixed $requestId, string $action): int
    {
        if (in_array($action, self::CREATE_ACTIONS, true)) {
            // The id of these actions points at a parent or a source record,
            // never at the record the form belongs to.
            return 0;
        }

        if ($containerId > 0) {
            return $containerId;
        }

        return is_numeric($requestId) && (int) $requestId > 0 ? (int) $requestId : 0;
    }

    public static function resolveCreating(int $containerId, mixed $requestId, string $action): bool
    {
        return 0 === self::resolveId($containerId, $requestId, $action);
    }

    /** Whether the id belongs to a genuine Contao website root page. */
    public function isRootPage(int $id): bool
    {
        return 'root' === (string) ($this->record($id)['type'] ?? '');
    }

    /** The source language configured on a website root, or an empty string. */
    public function rootLanguage(int $id): string
    {
        return $this->isRootPage($id) ? (string) ($this->record($id)['language'] ?? '') : '';
    }

    /**
     * The current record as an array, without touching the deprecated
     * `activeRecord` property.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->records)) {
            return $this->records[$id];
        }

        return $this->records[$id] = $this->loadRecord($id);
    }

    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRecord(int $id): ?array
    {
        if (null === $this->framework) {
            return null;
        }

        try {
            $this->framework->initialize();
            $page = $this->framework->getAdapter(PageModel::class)->findByPk($id);

            return null === $page ? null : $page->row();
        } catch (\Throwable) {
            // A missing database, an unavailable framework or a deleted record
            // must never break the form that asked.
            return null;
        }
    }

    private function containerId(?DataContainer $dc = null): int
    {
        $id = $dc?->id ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : 0;
    }

    private function requestId(): mixed
    {
        try {
            return Input::get('id');
        } catch (\Throwable) {
            return null;
        }
    }

    private function action(): string
    {
        try {
            return (string) Input::get('act');
        } catch (\Throwable) {
            return '';
        }
    }
}
