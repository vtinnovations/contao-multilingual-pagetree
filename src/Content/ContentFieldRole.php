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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

/**
 * What one field of a content element is, when an additional language edits it.
 *
 * The role is decided once, by the canonical policy, and never by which widget
 * happened to be rendered in the browser: a submitted value is only ever stored
 * when the policy - not the form - says the field is translatable.
 */
enum ContentFieldRole: string
{
    /** Translated independently and persisted in the translation record. */
    case Translatable = 'translatable';

    /**
     * Shown so the form matches the source form, but owned by the source
     * record: rendered read-only and never accepted from a translated POST.
     */
    case Inherited = 'inherited';

    /**
     * Owned by the source record like {@see self::Inherited}, but *materialised*
     * in the translation row.
     *
     * Contao resolves a palette by reading its selector fields straight from the
     * edited table with SQL, before any callback or in-memory record exists. A
     * selector that only lives in the source row therefore cannot select a
     * palette at all - the form collapses to the generic default one. These
     * columns are consequently copied from the source and kept in sync, while
     * staying read-only and never accepted from a translated POST.
     */
    case Structural = 'structural';

    /**
     * Publication state the translated language owns for itself, exactly as the
     * existing independent-field policy already defines it.
     */
    case Independent = 'independent';

    /** Never rendered and never accepted: identity, relations, bookkeeping. */
    case Technical = 'technical';

    public function isEditable(): bool
    {
        return self::Translatable === $this || self::Independent === $this;
    }

    /** Whether the field may appear in the additional-language palette at all. */
    public function isVisible(): bool
    {
        return self::Technical !== $this;
    }

    /**
     * Whether the translation row carries a column for this field. Editable
     * fields obviously do; a structural selector does too, because Contao reads
     * it from the table to choose the palette.
     */
    public function isPersisted(): bool
    {
        return $this->isEditable() || self::Structural === $this;
    }
}
