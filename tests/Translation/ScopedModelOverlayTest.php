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
use Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures\FakeModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\ScopedModelOverlay;

class ScopedModelOverlayTest extends TestCase
{
    public function testAppliesAndRestoresTheOriginalValues(): void
    {
        $overlay = new ScopedModelOverlay();
        $model = new FakeModel('tl_content', ['id' => 1, 'text' => 'Source']);

        $this->assertTrue($overlay->apply($model, ['id' => 1, 'text' => 'Übersetzt']));
        $this->assertSame('Übersetzt', $model->text);
        $this->assertTrue($overlay->isActive($model));

        $overlay->restore($model);

        $this->assertSame('Source', $model->text);
        $this->assertFalse($overlay->isActive($model));
    }

    /**
     * Runtime properties Contao adds before rendering (element classes, the type
     * prefix, ...) must survive the overlay. (Requirement 24)
     */
    public function testRuntimePropertiesArePreserved(): void
    {
        $overlay = new ScopedModelOverlay();
        $model = new FakeModel('tl_content', ['id' => 1, 'text' => 'Source']);
        $model->classes = ['first', 'last'];
        $model->typePrefix = 'ce_';

        $row = $model->row();
        $row['text'] = 'Übersetzt';
        $overlay->apply($model, $row);

        $this->assertSame(['first', 'last'], $model->classes);
        $this->assertSame('ce_', $model->typePrefix);

        $overlay->restore($model);

        $this->assertSame(['first', 'last'], $model->classes);
        $this->assertSame('ce_', $model->typePrefix);
    }

    public function testDoesNotOverlayTwiceSoTranslatedValuesAreNeverSnapshotted(): void
    {
        $overlay = new ScopedModelOverlay();
        $model = new FakeModel('tl_content', ['id' => 1, 'text' => 'Source']);

        $this->assertTrue($overlay->apply($model, ['id' => 1, 'text' => 'Erste']));
        $this->assertFalse($overlay->apply($model, ['id' => 1, 'text' => 'Zweite']));
        $this->assertSame('Erste', $model->text);

        $overlay->restore($model);

        $this->assertSame('Source', $model->text);
    }

    public function testUnchangedRowIsNotOverlaid(): void
    {
        $overlay = new ScopedModelOverlay();
        $model = new FakeModel('tl_content', ['id' => 1, 'text' => 'Source']);

        $this->assertFalse($overlay->apply($model, ['id' => 1, 'text' => 'Source']));
        $this->assertFalse($overlay->isActive($model));
    }

    /**
     * An exception during rendering must not leave translated values behind.
     * (Requirement 29)
     */
    public function testRestoreAllRecoversFromAnAbortedRender(): void
    {
        $overlay = new ScopedModelOverlay();
        $model = new FakeModel('tl_content', ['id' => 1, 'text' => 'Source']);

        try {
            $overlay->apply($model, ['id' => 1, 'text' => 'Übersetzt']);

            throw new \RuntimeException('Rendering failed');
        } catch (\RuntimeException) {
            // The request scoped reset listener performs this cleanup.
            $overlay->restoreAll();
        }

        $this->assertSame('Source', $model->text);
        $this->assertFalse($overlay->isActive($model));
    }

    /**
     * Nested rendering: releasing the child overlay must not release the parent
     * overlay that is still being rendered. (Requirement 23)
     */
    public function testNestedOverlaysAreIndependent(): void
    {
        $overlay = new ScopedModelOverlay();
        $parent = new FakeModel('tl_content', ['id' => 1, 'text' => 'Parent source']);
        $child = new FakeModel('tl_content', ['id' => 2, 'text' => 'Child source']);

        $overlay->apply($parent, ['id' => 1, 'text' => 'Parent übersetzt']);
        $overlay->apply($child, ['id' => 2, 'text' => 'Child übersetzt']);
        $overlay->restore($child);

        $this->assertSame('Child source', $child->text);
        $this->assertSame('Parent übersetzt', $parent->text, 'The parent is still being rendered.');

        $overlay->restore($parent);

        $this->assertSame('Parent source', $parent->text);
    }

    public function testTokensReleaseTheMatchingRecord(): void
    {
        $overlay = new ScopedModelOverlay();
        $article = new FakeModel('tl_article', ['id' => 3, 'title' => 'Source title']);

        $overlay->apply($article, ['id' => 3, 'title' => 'Übersetzter Titel'], 'tl_article:3');
        $overlay->restoreToken('tl_article:9');

        $this->assertSame('Übersetzter Titel', $article->title);

        $overlay->restoreToken('tl_article:3');

        $this->assertSame('Source title', $article->title);
    }

    public function testResetReleasesEverything(): void
    {
        $overlay = new ScopedModelOverlay();
        $first = new FakeModel('tl_content', ['id' => 1, 'text' => 'First']);
        $second = new FakeModel('tl_content', ['id' => 2, 'text' => 'Second']);

        $overlay->apply($first, ['id' => 1, 'text' => 'Eins']);
        $overlay->apply($second, ['id' => 2, 'text' => 'Zwei']);
        $overlay->reset();

        $this->assertSame('First', $first->text);
        $this->assertSame('Second', $second->text);
    }

    public function testPlainObjectsAreSupported(): void
    {
        $overlay = new ScopedModelOverlay();
        $record = new \stdClass();
        $record->id = 1;
        $record->text = 'Source';

        $overlay->apply($record, ['id' => 1, 'text' => 'Übersetzt', 'meta' => 'added']);

        $this->assertSame('Übersetzt', $record->text);
        $this->assertSame('added', $record->meta);

        $overlay->restore($record);

        $this->assertSame('Source', $record->text);
        $this->assertObjectNotHasProperty('meta', $record);
    }
}
