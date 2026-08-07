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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentFieldRole;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationFieldPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * The canonical write boundary of a translated content element.
 *
 * The additional-language form is the native tl_content form, so the browser
 * renders far more fields than a translation may own. These assertions prove
 * that the policy - not the rendered form - decides what is stored.
 */
class ContentTranslationFieldPolicyTest extends TestCase
{
    private ContentTranslationFieldPolicy $policy;

    /** @var list<string> */
    private array $columns;

    protected function setUp(): void
    {
        $this->policy = new ContentTranslationFieldPolicy(new TranslationFieldRegistry());
        $this->columns = $this->policy->persistedColumns();
    }

    /** The persisted set never depends on a live database connection. */
    public function testThePersistedColumnSetIsDeterministic(): void
    {
        $again = (new ContentTranslationFieldPolicy(new TranslationFieldRegistry()))->persistedColumns();

        $this->assertSame($this->columns, $again);
        $this->assertNotSame([], $this->columns);
        $this->assertSame(array_values(array_unique($this->columns)), $this->columns);

        // The columns the standard element types translate.
        foreach (['headline', 'text', 'html', 'code', 'listitems', 'tableitems', 'linkTitle', 'caption', 'alt', 'invisible', 'start', 'stop', 'type'] as $expected) {
            $this->assertContains($expected, $this->columns, $expected);
        }
    }

    /**
     * @dataProvider roles
     */
    public function testFieldRoles(string $field, ?string $contentType, ContentFieldRole $expected): void
    {
        $this->assertSame($expected, $this->policy->role($field, $contentType, $this->columns));
    }

    /**
     * @return iterable<string, array{string, string|null, ContentFieldRole}>
     */
    public static function roles(): iterable
    {
        yield 'headline is translated' => ['headline', 'text', ContentFieldRole::Translatable];
        yield 'text is translated' => ['text', 'text', ContentFieldRole::Translatable];
        yield 'html is translated' => ['html', 'html', ContentFieldRole::Translatable];
        yield 'link title is translated' => ['linkTitle', 'hyperlink', ContentFieldRole::Translatable];

        yield 'visibility is language specific' => ['invisible', 'text', ContentFieldRole::Independent];
        yield 'start is language specific' => ['start', 'text', ContentFieldRole::Independent];
        yield 'stop is language specific' => ['stop', 'text', ContentFieldRole::Independent];

        // The palette selector is owned by the source but *materialised*, so
        // Contao can read it back and select the native palette.
        yield 'element type is a mirrored palette selector' => ['type', 'text', ContentFieldRole::Structural];
        yield 'identity stays technical' => ['id', 'text', ContentFieldRole::Technical];
        yield 'relation stays technical' => ['pid', 'text', ContentFieldRole::Technical];
        yield 'parent table stays technical' => ['ptable', 'text', ContentFieldRole::Technical];
        yield 'ordering stays technical' => ['sorting', 'text', ContentFieldRole::Technical];
        yield 'timestamp stays technical' => ['tstamp', 'text', ContentFieldRole::Technical];
        yield 'provenance stays technical' => ['fieldStates', 'text', ContentFieldRole::Technical];
        yield 'ownership stays technical' => ['cmpLanguage', 'text', ContentFieldRole::Technical];
        yield 'root ownership stays technical' => ['cmpLanguageRoot', 'text', ContentFieldRole::Technical];

        yield 'structure is inherited' => ['cssID', 'text', ContentFieldRole::Inherited];
        yield 'column is inherited' => ['colPos', 'text', ContentFieldRole::Inherited];
        yield 'image source is inherited' => ['singleSRC', 'image', ContentFieldRole::Inherited];
        yield 'image size is inherited' => ['size', 'image', ContentFieldRole::Inherited];
        yield 'a field of another type is inherited' => ['listitems', 'text', ContentFieldRole::Inherited];
        yield 'an unknown field is inherited' => ['someThirdPartyField', 'text', ContentFieldRole::Inherited];
    }

    /** A submission only ever persists approved columns. */
    public function testASubmissionIsReducedToApprovedColumns(): void
    {
        $submitted = [
            'headline' => 'Englische Überschrift',
            'text' => '<p>English body</p>',
            'invisible' => '',
            // Everything below must be discarded.
            'id' => 99,
            'pid' => 7,
            'ptable' => 'tl_article',
            'sorting' => 512,
            'tstamp' => 123,
            'type' => 'html',
            'language' => 'fr',
            'fieldStates' => '{"text":"custom"}',
            'cmpLanguage' => 'fr',
            'cmpLanguageRoot' => 3,
            'cssID' => 'a:2:{i:0;s:3:"evil";i:1;s:0:"";}',
            'colPos' => 'main',
            'DROP TABLE tl_content' => '1',
        ];

        $filtered = $this->policy->filterSubmission($submitted, 'text', $this->columns);

        $this->assertSame(
            ['headline' => 'Englische Überschrift', 'text' => '<p>English body</p>', 'invisible' => ''],
            $filtered,
        );
    }

    /** A field the schema cannot hold degrades to inherited, never to an error. */
    public function testAnApprovedFieldWithoutAColumnIsInherited(): void
    {
        $withoutText = array_values(array_filter($this->columns, static fn (string $c): bool => 'text' !== $c));

        $this->assertSame(ContentFieldRole::Inherited, $this->policy->role('text', 'text', $withoutText));
        $this->assertNotContains('text', $this->policy->editableFields('text', $withoutText));
        $this->assertSame([], $this->policy->filterSubmission(['text' => 'x'], 'text', $withoutText));
    }

    /** Translatable fields exclude the independent publication fields. */
    public function testTranslatableFieldsExcludePublication(): void
    {
        $translatable = $this->policy->translatableFields('text', $this->columns);
        $editable = $this->policy->editableFields('text', $this->columns);

        $this->assertContains('text', $translatable);
        $this->assertNotContains('invisible', $translatable);
        $this->assertContains('invisible', $editable);
        $this->assertNotContains('type', $editable);
    }

    /** Every role a field can take is either invisible or non-editable safely. */
    public function testTechnicalFieldsAreNeverVisibleOrEditable(): void
    {
        foreach (['id', 'pid', 'ptable', 'sorting', 'tstamp', 'language', 'fieldStates'] as $field) {
            $role = $this->policy->role($field, 'text', $this->columns);

            $this->assertSame(ContentFieldRole::Technical, $role, $field);
            $this->assertFalse($role->isEditable(), $field);
            $this->assertFalse($role->isVisible(), $field);
            $this->assertFalse($role->isPersisted(), $field);
        }
    }

    /**
     * The element type is never writable by a translation.
     *
     * The palette itself is resolved from the real content element now, so the
     * mirrored column is only a leftover of the storage schema - what matters
     * is that a translation can never change the structure.
     */
    public function testThePaletteSelectorIsMirroredButNeverWritable(): void
    {
        $role = $this->policy->role('type', 'text', $this->columns);

        $this->assertSame(ContentFieldRole::Structural, $role);
        $this->assertTrue($role->isVisible(), 'The editor must see the element type.');
        $this->assertTrue($role->isPersisted(), 'Contao reads the selector from the table to pick the palette.');
        $this->assertFalse($role->isEditable(), 'A connected translation may not change its structure.');

        $this->assertContains('type', $this->policy->persistedColumns());
        $this->assertSame(['type'], $this->policy->structuralColumns($this->columns));
        $this->assertNotContains('type', $this->policy->editableFields('text', $this->columns));
        $this->assertNotContains('type', $this->policy->translatableFields('text', $this->columns));
    }

    /** A manipulated submission can never change the connected element type. */
    public function testAPostedTypeIsAlwaysDiscarded(): void
    {
        foreach (['html', 'module', '', 'text'] as $posted) {
            $this->assertSame(
                [],
                $this->policy->filterSubmission(['type' => $posted], 'text', $this->columns),
                'posted type "'.$posted.'"',
            );
        }
    }
}
