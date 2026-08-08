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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentRelationValidator;
use Vtinnovations\ContaoMultilingualPagetree\Content\ModeSwitchAnalyzer;
use Vtinnovations\ContaoMultilingualPagetree\Content\StructuralChangeGuard;
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\InMemoryFreeContentStorage;

class ModeSwitchAndStructureTest extends TestCase
{
    /**
     * Requirements 71, 73, 74, 75 and 76: a mode change reports what becomes
     * inactive and never deletes anything.
     */
    public function testAModeSwitchReportsInactiveRecordsWithoutDeletingThem(): void
    {
        $storage = $this->storageWithBothTrees();
        $analyzer = new ModeSwitchAnalyzer($storage);

        $toFree = $analyzer->analyse(1, 'de', ContentTranslationMode::Connected, ContentTranslationMode::Free);

        $this->assertTrue($toFree->isChange());
        $this->assertSame(7, $toFree->connectedRecords(), 'Connected translation records are counted.');
        $this->assertSame(3, $toFree->freeRecords(), 'Free records are counted.');
        $this->assertSame(7, $toFree->recordsBecomingInactive(), 'Connected records stop rendering.');
        $this->assertTrue($toFree->requiresConfirmation());

        $toConnected = $analyzer->analyse(1, 'de', ContentTranslationMode::Free, ContentTranslationMode::Connected);

        $this->assertSame(3, $toConnected->recordsBecomingInactive(), 'Free records stop rendering.');

        // Nothing was removed by analysing or switching.
        $this->assertCount(1, $storage->freeRecords('tl_article', 'de'));
        $this->assertCount(2, $storage->freeRecords('tl_content', 'de'));
    }

    /** Requirement 74: without inactive data no confirmation is demanded. */
    public function testNoConfirmationIsRequiredWithoutInactiveData(): void
    {
        $analyzer = new ModeSwitchAnalyzer(new InMemoryFreeContentStorage());
        $summary = $analyzer->analyse(1, 'de', ContentTranslationMode::Connected, ContentTranslationMode::Free);

        $this->assertTrue($summary->isChange());
        $this->assertFalse($summary->requiresConfirmation());
        $this->assertSame(0, $summary->recordsBecomingInactive());
    }

    public function testAnUnchangedModeIsNeverTreatedAsASwitch(): void
    {
        $summary = (new ModeSwitchAnalyzer($this->storageWithBothTrees()))
            ->analyse(1, 'de', ContentTranslationMode::Connected, ContentTranslationMode::Connected);

        $this->assertFalse($summary->isChange());
        $this->assertFalse($summary->requiresConfirmation());
        $this->assertSame(0, $summary->recordsBecomingInactive());
    }

    /**
     * Requirements 30, 31, 32 and 33: connected translations never own
     * structure, enforced server side.
     *
     * @dataProvider structuralFields
     */
    public function testConnectedTranslationsRejectStructuralChanges(string $field, mixed $value): void
    {
        $guard = new StructuralChangeGuard();
        $current = ['pid' => 5, 'ptable' => 'tl_article', 'sorting' => 128, 'type' => 'text', 'colPos' => 'main'];

        $this->assertTrue($guard->isStructuralChange('tl_content_translation', [$field => $value], $current));
        $this->assertSame([$field], $guard->rejectedFields('tl_content_translation', [$field => $value], $current));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function structuralFields(): iterable
    {
        yield 'parent relation' => ['pid', 99];
        yield 'parent table' => ['ptable', 'tl_content'];
        yield 'sorting' => ['sorting', 999];
        yield 'content type' => ['type', 'image'];
        yield 'column' => ['colPos', 'left'];
        yield 'article column' => ['inColumn', 'right'];
        yield 'template' => ['customTpl', 'ce_custom'];
    }

    public function testWritingUnchangedInheritedValuesIsNotAStructuralChange(): void
    {
        $guard = new StructuralChangeGuard();
        $current = ['pid' => 5, 'type' => 'text'];

        $this->assertFalse($guard->isStructuralChange('tl_content_translation', ['pid' => 5, 'type' => 'text'], $current));
        $this->assertSame([], $guard->rejectedFields('tl_content_translation', ['text' => 'Übersetzt'], $current));
    }

    /** Free records are normal Contao records and keep full structural freedom. */
    public function testFreeRecordsAreNotStructurallyRestricted(): void
    {
        $guard = new StructuralChangeGuard();

        $this->assertFalse($guard->isConnectedTranslationTable('tl_content'));
        $this->assertSame([], $guard->rejectedFields('tl_content', ['pid' => 9, 'type' => 'image', 'sorting' => 5]));
    }

    /** Requirement 31: structural backend actions are rejected outright. */
    public function testStructuralActionsAreRejectedForConnectedTranslations(): void
    {
        $guard = new StructuralChangeGuard();

        foreach (['cut', 'copy', 'copyAll', 'cutAll', 'paste', 'move'] as $action) {
            $this->assertTrue($guard->isProtectedAction($action), $action);
        }

        $this->assertFalse($guard->isProtectedAction('edit'));
        $this->assertFalse($guard->isProtectedAction(null));
    }

    /**
     * Requirements 55, 56 and 120: owner relations may never cross a language or
     * a root site.
     */
    public function testOwnerRelationsAreValidatedAcrossLanguagesAndSites(): void
    {
        $validator = new FreeContentRelationValidator();
        $german = ContentOwnership::free('de', 1);

        $this->assertTrue($validator->isValid($german, [ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1]));
        $this->assertSame(
            FreeContentRelationValidator::REASON_CROSS_LANGUAGE,
            $validator->validate($german, [ContentOwnership::FIELD_LANGUAGE => 'fr', ContentOwnership::FIELD_ROOT => 1]),
        );
        $this->assertSame(
            FreeContentRelationValidator::REASON_CROSS_SITE,
            $validator->validate($german, [ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 2]),
        );
        $this->assertSame(
            FreeContentRelationValidator::REASON_SOURCE_OWNER,
            $validator->validate($german, ['id' => 1]),
        );
        $this->assertSame(
            FreeContentRelationValidator::REASON_MISSING_OWNER,
            $validator->validate($german, null),
        );
    }

    /** A source record may never be attached below a free owner. */
    public function testSourceRecordsRejectFreeOwners(): void
    {
        $validator = new FreeContentRelationValidator();

        $this->assertTrue($validator->isValid(ContentOwnership::source(), ['id' => 1]));
        $this->assertSame(
            FreeContentRelationValidator::REASON_FREE_OWNER,
            $validator->validate(ContentOwnership::source(), [ContentOwnership::FIELD_LANGUAGE => 'de']),
        );
    }

    public function testChildrenInheritOwnershipFromTheirOwner(): void
    {
        $validator = new FreeContentRelationValidator();
        $inherited = $validator->inheritedOwnership([ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 3]);

        $this->assertTrue($inherited->belongsTo('de'));
        $this->assertSame(3, $inherited->rootPageId);
    }

    private function storageWithBothTrees(): InMemoryFreeContentStorage
    {
        $storage = new InMemoryFreeContentStorage();
        $storage->translationCounts = [
            'tl_article_translation|de' => 2,
            'tl_content_translation|de' => 5,
        ];

        return $storage
            ->put('tl_article', ['id' => 1, 'pid' => 10, ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 11, 'pid' => 1, 'ptable' => 'tl_article', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1])
            ->put('tl_content', ['id' => 12, 'pid' => 1, 'ptable' => 'tl_article', ContentOwnership::FIELD_LANGUAGE => 'de', ContentOwnership::FIELD_ROOT => 1]);
    }
}
