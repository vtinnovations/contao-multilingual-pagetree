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

use Contao\DataContainer;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;

/**
 * Installs the root-only controls in every site-root palette of tl_page.
 *
 * Lifecycle notes, because this is where the integration used to fail:
 *
 *  - Contao runs `config.onload_callback` inside the data container's
 *    constructor, long before `edit()` loads the row. `$dc->activeRecord` is
 *    therefore always empty here. Deciding "is this a root page?" from it made
 *    the callback fall through to "not authorised" on every edit form, so the
 *    licence legend was never added and the panel could not appear anywhere.
 *  - The palette does not need that decision at all: Contao selects the palette
 *    by the record's `type`, so writing into the root palettes already limits
 *    the section to genuine website roots.
 *  - The record id still matters for the permission check of non-administrators
 *    and is resolved through {@see RootPageContext}, not through `activeRecord`.
 *
 * The callback is idempotent, so repeated DCA loading in one request cannot
 * duplicate the section, and it only ever touches its own field, so palette
 * additions made by Contao or by third-party extensions survive unchanged.
 */
final class PageRootPalette
{
    public const LICENCE_LEGEND = 'contao_multilingual_pagetree_licence_legend';
    public const LICENCE_FIELD = 'contaoMultilingualPagetreeLicencePanel';

    /**
     * Palette names Contao uses for website roots.
     *
     * @var list<string>
     */
    public const ROOT_PALETTES = ['root', 'rootfallback'];

    /**
     * Anchors for the position of the section, most specific first. Contao's
     * "Access rights" section is `chmod_legend`; the remaining entries keep the
     * position sensible if an installation reorganises the palette.
     *
     * @var list<string>
     */
    private const ANCHOR_LEGENDS = ['chmod_legend', 'protected_legend', 'access_legend', 'publish_legend', 'expert_legend'];

    public function __construct(
        private readonly RootPagePermission $permission,
        private readonly RootPageContext $context,
    ) {
    }

    /**
     * `config.onload_callback` of tl_page.
     */
    public function register(?DataContainer $dc = null): void
    {
        $recordId = $this->context->currentId($dc);
        $creating = $this->context->isCreating($dc);

        // A record that is not persisted yet cannot be checked against page
        // mounts, so the general permission is evaluated instead. Every action
        // behind the panel is authorised again with the real id server side.
        $this->apply($this->permission->canView($creating ? 0 : $recordId));
    }

    /**
     * Writes the assembled root palettes.
     *
     * Separate from {@see self::register()} so the palette result can be
     * asserted for an authorised and an unauthorised request without a running
     * backend.
     */
    public function apply(bool $authorised): void
    {
        foreach (self::rootPaletteNames() as $paletteName) {
            $palette = $GLOBALS['TL_DCA']['tl_page']['palettes'][$paletteName] ?? null;

            if (!is_string($palette)) {
                continue;
            }

            $GLOBALS['TL_DCA']['tl_page']['palettes'][$paletteName] = self::assemble($palette, $authorised);
        }
    }

    /**
     * The root palettes present in the loaded DCA.
     *
     * Custom page types whose palette name starts with `root` are included, so
     * an installation that adds its own root variant keeps the section.
     *
     * @return list<string>
     */
    public static function rootPaletteNames(): array
    {
        $names = self::ROOT_PALETTES;

        foreach (array_keys($GLOBALS['TL_DCA']['tl_page']['palettes'] ?? []) as $name) {
            $name = (string) $name;

            if ('__selector__' === $name || !str_starts_with($name, 'root') || in_array($name, $names, true)) {
                continue;
            }

            if (is_string($GLOBALS['TL_DCA']['tl_page']['palettes'][$name] ?? null)) {
                $names[] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * Root palette with the language summary in Contao's own language section
     * and the licence section immediately before the access rights.
     */
    public static function assemble(string $palette, bool $showLicence): string
    {
        $palette = PaletteHelper::addToPalette($palette, 'language_legend', ['additional_languages']);

        // Always drop first: an unauthorised user must lose a section a previous
        // pass added, and a second pass must not duplicate it.
        $palette = PaletteHelper::removeFields($palette, [self::LICENCE_FIELD, 'root_licence']);

        if (!$showLicence) {
            return $palette;
        }

        return PaletteHelper::insertLegend($palette, self::LICENCE_LEGEND, [self::LICENCE_FIELD], self::ANCHOR_LEGENDS);
    }
}
