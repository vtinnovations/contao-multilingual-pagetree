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

#[AsModel(table: 'tl_page_translation')]
class PageTranslationModel extends Model
{
    protected static $strTable = 'tl_page_translation';

    public static function findByPidAndLanguage(int $pid, string $language)
    {
        return static::findOneBy(['pid=?', 'language=?'], [$pid, $language]);
    }

    public static function findByAliasAndLanguage(string $alias, string $language)
    {
        return static::findOneBy(['alias=?', 'language=?'], [$alias, $language]);
    }

    public static function findByAlias(string $alias)
    {
        return static::findBy(['alias=?'], [$alias]);
    }
}
