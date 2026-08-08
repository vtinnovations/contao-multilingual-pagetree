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
use Vtinnovations\ContaoMultilingualPagetree\Backend\TranslationStateDca;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;

final class TranslationStatePersistenceTest extends TestCase
{
    public function testVirtualPageStateFieldsNeverBecomeSqlColumns(): void
    {
        self::assertSame(
            ['title' => 'English', 'fieldStates' => '{"alias":"inherit","title":"custom"}'],
            TranslationStateDca::collapseVirtualStateFields(
                ['title' => 'English', 'fieldState_title' => 'custom'],
                ['title', 'pageTitle', 'description', 'alias'],
                '{"alias":"inherit"}',
                new FieldStateMap(),
            ),
        );
    }

    public function testVirtualContentStateFieldsNeverBecomeSqlColumns(): void
    {
        self::assertSame(
            ['headline' => 'Headline', 'text' => '<p>Body</p>', 'fieldStates' => '{"headline":"custom","text":"custom"}'],
            TranslationStateDca::collapseVirtualStateFields(
                ['headline' => 'Headline', 'fieldState_headline' => 'custom', 'text' => '<p>Body</p>', 'fieldState_text' => 'custom'],
                ['headline', 'text'],
                '{"text":"empty"}',
                new FieldStateMap(),
            ),
        );
    }

    public function testUnknownDynamicStateFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TranslationStateDca::withoutVirtualStateFields(['fieldState_dropTable' => 'custom'], ['title']);
    }
}
