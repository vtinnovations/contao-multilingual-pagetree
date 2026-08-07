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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

/**
 * Where a language's effective entry point came from.
 *
 * An empty entry-point field does not mean one thing - it means "derive it",
 * and what is derived depends on whether the language also has a domain of its
 * own. Recording which rule produced the value keeps that decision in one
 * place: routing, URL generation, redirects and validation read the origin
 * instead of re-deriving it and disagreeing.
 */
enum EntryPointOrigin: string
{
    /** The editor typed a path, including an explicit `/`. */
    case Explicit = 'explicit';

    /**
     * The language has a domain of its own and no entry point, so it is served
     * from that domain's root. Appending its language code here would put the
     * site at `https://example.ru/ru` when the editor asked for
     * `https://example.ru`.
     */
    case DomainRoot = 'domain_root';

    /**
     * Neither a domain nor an entry point is configured, so the record keeps
     * the strategy it had before these fields existed: the source language at
     * the root, every other language below its own language code.
     */
    case Legacy = 'legacy';

    /** True when the value was derived rather than typed by an editor. */
    public function isDerived(): bool
    {
        return self::Explicit !== $this;
    }
}
