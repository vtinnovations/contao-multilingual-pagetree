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

/**
 * @AsModel(table="tl_content_translation")
 */
#[AsModel(table: 'tl_content_translation')]
class ContentTranslationModel extends Model
{
    protected static $strTable = 'tl_content_translation';

    public static function findByPidAndLanguage(int $pid, string $language)
    {
        return static::findOneBy(['pid=?', 'language=?'], [$pid, $language]);
    }

    public static function findByPid(int $pid)
    {
        return static::findBy('pid', $pid);
    }
}
