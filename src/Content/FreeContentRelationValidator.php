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
 * Validates the owner relation of free-language records.
 *
 * A free content element inherits target language and root site from its owner,
 * so a record may never be attached to an owner of another language or another
 * root site, and a source record may never own free content.
 */
final class FreeContentRelationValidator
{
    public const REASON_OK = 'ok';
    public const REASON_MISSING_OWNER = 'missing_owner';
    public const REASON_CROSS_LANGUAGE = 'cross_language';
    public const REASON_CROSS_SITE = 'cross_site';
    public const REASON_SOURCE_OWNER = 'source_owner';
    public const REASON_FREE_OWNER = 'free_owner';

    /**
     * @param array<string, mixed>|object|null $owner The parent article or content record
     */
    public function validate(ContentOwnership $record, array|object|null $owner): string
    {
        if (null === $owner) {
            return self::REASON_MISSING_OWNER;
        }

        $ownerOwnership = ContentOwnership::fromRecord($owner);

        if ($record->isSource()) {
            // A source record may only live below the source structure.
            return $ownerOwnership->isSource() ? self::REASON_OK : self::REASON_FREE_OWNER;
        }

        if ($ownerOwnership->isSource()) {
            return self::REASON_SOURCE_OWNER;
        }

        if (!$ownerOwnership->belongsTo($record->language)) {
            return self::REASON_CROSS_LANGUAGE;
        }

        if (0 !== $record->rootPageId && 0 !== $ownerOwnership->rootPageId && $record->rootPageId !== $ownerOwnership->rootPageId) {
            return self::REASON_CROSS_SITE;
        }

        return self::REASON_OK;
    }

    /**
     * @param array<string, mixed>|object|null $owner
     */
    public function isValid(ContentOwnership $record, array|object|null $owner): bool
    {
        return self::REASON_OK === $this->validate($record, $owner);
    }

    /**
     * The ownership a child must receive from its owner.
     *
     * @param array<string, mixed>|object $owner
     */
    public function inheritedOwnership(array|object $owner): ContentOwnership
    {
        return ContentOwnership::fromRecord($owner);
    }
}
