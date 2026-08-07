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

namespace Vtinnovations\ContaoMultilingualPagetree\Model;

use Contao\Model;
use Contao\CoreBundle\ServiceAnnotation\Model as AsModel;

#[AsModel(table: 'tl_inline_language')]
class MultilingualPagetreeModel extends Model
{
    protected static $strTable = 'tl_inline_language';

    public static function findPublishedByPid(int $pid)
    {
        return static::findBy(['pid=?', 'published=?'], [$pid, 1], ['order' => 'sorting ASC']);
    }

    /**
     * Every language record of one website root, published or not.
     *
     * The language URL resolver needs the unpublished rows too: they must be
     * ignored while resolving a request but still take part in collision
     * validation, so an editor cannot prepare a mapping that breaks the moment
     * it is published.
     */
    public static function findByPid(int $pid)
    {
        return static::findBy(['pid=?'], [$pid], ['order' => 'sorting ASC']);
    }

    public static function findFallbackByPid(int $pid)
    {
        return static::findOneBy(['pid=?', 'fallback=?', 'published=?'], [$pid, 1, 1]);
    }

    public static function findByPidAndLanguage(int $pid, string $language)
    {
        return static::findOneBy(['pid=?', 'language=?'], [$pid, $language]);
    }

    public static function findAllPublished()
    {
        return static::findBy(['published=?'], [1], ['order' => 'sorting ASC']);
    }
}
