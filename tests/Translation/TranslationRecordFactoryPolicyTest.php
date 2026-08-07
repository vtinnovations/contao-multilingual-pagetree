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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Translation\FieldStateMap;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordFactory;

final class TranslationRecordFactoryPolicyTest extends TestCase
{
    public function testCreationInitialisesOnlyFieldsAllowedForTheContentType(): void
    {
        $factory = new TranslationRecordFactory(new TranslationFieldRegistry(), new FieldStateMap());
        $record = $factory->createInsertSet(
            'tl_content_translation',
            ['id' => 4, 'type' => 'image', 'text' => 'Never copied', 'alt' => 'Source alt', 'singleSRC' => 'files/a.jpg'],
            ['id', 'pid', 'tstamp', 'language', 'fieldStates', 'type', 'headline', 'text', 'alt', 'singleSRC', 'invisible'],
            'de',
            4,
        );
        $states = json_decode($record['fieldStates'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(4, $record['pid']);
        $this->assertSame('de', $record['language']);
        $this->assertSame('image', $record['type']);
        $this->assertSame('0', $record['invisible']);
        $this->assertSame(['alt' => 'inherit', 'headline' => 'inherit'], $states);
        $this->assertArrayNotHasKey('text', $record);
        $this->assertArrayNotHasKey('singleSRC', $record);
        $this->assertArrayNotHasKey('alt', $record);
    }

    public function testPublicationGetsSafeIndependentDefaultWithoutAFieldState(): void
    {
        $factory = new TranslationRecordFactory(new TranslationFieldRegistry(), new FieldStateMap());
        $record = $factory->createInsertSet(
            'tl_news_translation',
            ['id' => 9, 'headline' => 'Source', 'author' => 3],
            ['id', 'pid', 'tstamp', 'language', 'fieldStates', 'headline', 'author', 'published', 'start', 'stop'],
            'de',
            9,
        );
        $states = json_decode($record['fieldStates'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('1', $record['published']);
        $this->assertArrayHasKey('headline', $states);
        $this->assertArrayNotHasKey('published', $states);
        $this->assertArrayNotHasKey('author', $record);
    }
}
