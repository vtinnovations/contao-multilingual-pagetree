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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Backend;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Backend\LanguageTabs;

final class TranslationSaveLifecycleTest extends TestCase
{
    public function testNativePageTranslationPostRemainsARecordAction(): void
    {
        self::assertTrue(LanguageTabs::isSubmittedRecordAction('', 'tl_page_translation', 'tl_page_translation'));
        self::assertTrue(LanguageTabs::isSubmittedRecordAction('edit', null, 'tl_page_translation'));
    }

    public function testNativeContentTranslationPostRemainsARecordAction(): void
    {
        self::assertTrue(LanguageTabs::isSubmittedRecordAction('', 'tl_content_translation', 'tl_content_translation'));
    }

    public function testForeignOrUnrelatedFormsCannotSelectTheRecordLifecycle(): void
    {
        self::assertFalse(LanguageTabs::isSubmittedRecordAction('', 'tl_page', 'tl_page_translation'));
        self::assertFalse(LanguageTabs::isSubmittedRecordAction('create', 'tl_page_translation', 'tl_content_translation'));
    }
}
