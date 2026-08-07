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

#[AsModel(table: 'tl_news_translation')]
class NewsTranslationModel extends Model
{
    protected static $strTable = 'tl_news_translation';

    public static function findByPidAndLanguage(int $pid, string $language)
    {
        return static::findOneBy(['pid=?', 'language=?'], [$pid, $language]);
    }

    public static function findByPid(int $pid)
    {
        return static::findBy('pid', $pid);
    }

    public static function findOneByAlias(string $alias, string $language)
    {
        return static::findOneBy(['alias=?', 'language=?'], [$alias, $language]);
    }
}
