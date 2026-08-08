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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Content;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationBuffer;

/**
 * The buffer carries one submission from the per-field callbacks to the record
 * callback. Its whole job is to keep two languages of one element apart.
 */
class ContentTranslationBufferTest extends TestCase
{
    private ContentTranslationBuffer $buffer;

    protected function setUp(): void
    {
        $this->buffer = new ContentTranslationBuffer();
    }

    public function testValuesAreKeyedBySourceAndLanguage(): void
    {
        $this->buffer->capture(5, 'en', 'headline', 'English headline');
        $this->buffer->capture(5, 'ru', 'headline', 'Русский заголовок');
        $this->buffer->capture(9, 'en', 'headline', 'Another element');

        $this->assertSame(['headline' => 'English headline'], $this->buffer->values(5, 'en'));
        $this->assertSame(['headline' => 'Русский заголовок'], $this->buffer->values(5, 'ru'));
        $this->assertSame(['headline' => 'Another element'], $this->buffer->values(9, 'en'));
    }

    public function testSeveralFieldsOfOneSubmissionAccumulate(): void
    {
        $this->buffer->capture(5, 'en', 'headline', 'English headline');
        $this->buffer->capture(5, 'en', 'text', '<p>English body</p>');

        $this->assertSame(
            ['headline' => 'English headline', 'text' => '<p>English body</p>'],
            $this->buffer->values(5, 'en'),
        );
        $this->assertTrue($this->buffer->has(5, 'en'));
    }

    public function testLanguageComparisonIsCaseInsensitive(): void
    {
        $this->buffer->capture(5, 'EN', 'headline', 'English headline');

        $this->assertTrue($this->buffer->has(5, 'en'));
        $this->assertSame(['headline' => 'English headline'], $this->buffer->values(5, 'en'));
    }

    public function testAnEmptySubmissionIsNotBuffered(): void
    {
        $this->assertFalse($this->buffer->has(5, 'en'));
        $this->assertSame([], $this->buffer->values(5, 'en'));
    }

    /** A blank translated value is a real value and must be carried through. */
    public function testABlankValueIsStillCaptured(): void
    {
        $this->buffer->capture(5, 'en', 'text', '');

        $this->assertTrue($this->buffer->has(5, 'en'));
        $this->assertSame(['text' => ''], $this->buffer->values(5, 'en'));
    }

    /** Nonsense keys are ignored rather than creating a bogus entry. */
    public function testInvalidKeysAreIgnored(): void
    {
        $this->buffer->capture(0, 'en', 'headline', 'x');
        $this->buffer->capture(5, '', 'headline', 'x');
        $this->buffer->capture(5, 'en', '', 'x');

        $this->assertFalse($this->buffer->has(0, 'en'));
        $this->assertFalse($this->buffer->has(5, ''));
        $this->assertSame([], $this->buffer->values(5, 'en'));
    }

    public function testReleaseClearsOnlyThatSubmission(): void
    {
        $this->buffer->capture(5, 'en', 'headline', 'English');
        $this->buffer->capture(5, 'ru', 'headline', 'Russian');

        $this->buffer->release(5, 'en');

        $this->assertFalse($this->buffer->has(5, 'en'));
        $this->assertTrue($this->buffer->has(5, 'ru'));
    }

    /** Nothing may survive into the next request of a long-running worker. */
    public function testResetClearsEverything(): void
    {
        $this->buffer->capture(5, 'en', 'headline', 'English');
        $this->buffer->capture(9, 'ru', 'text', 'Russian');

        $this->buffer->reset();

        $this->assertFalse($this->buffer->has(5, 'en'));
        $this->assertFalse($this->buffer->has(9, 'ru'));
    }
}
