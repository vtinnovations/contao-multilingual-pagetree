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

#[AsModel(table: 'tl_article_translation')]
class ArticleTranslationModel extends Model
{
    protected static $strTable = 'tl_article_translation';

    public static function findByPidAndLanguage(int $pid, string $language)
    {
        return static::findOneBy(['pid=?', 'language=?'], [$pid, $language]);
    }
}
