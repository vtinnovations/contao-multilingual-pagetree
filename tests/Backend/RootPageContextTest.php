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
use Vtinnovations\ContaoMultilingualPagetree\Backend\RootPageContext;

/**
 * Which record a backend request works on, resolved without `activeRecord`.
 */
final class RootPageContextTest extends TestCase
{
    /**
     * @dataProvider requests
     */
    public function testTheEditedRecordIsResolvedFromTheRequestLifecycle(int $containerId, mixed $requestId, string $action, int $expectedId): void
    {
        self::assertSame($expectedId, RootPageContext::resolveId($containerId, $requestId, $action));
        self::assertSame(0 === $expectedId, RootPageContext::resolveCreating($containerId, $requestId, $action));
    }

    /**
     * @return iterable<string, array{int, mixed, string, int}>
     */
    public static function requests(): iterable
    {
        // The data container knows the id in the edit form.
        yield 'edit with container id' => [12, '12', 'edit', 12];

        // During onload the container may not have picked the id up yet.
        yield 'edit with request id only' => [0, '12', 'edit', 12];

        // The page listing has no action at all.
        yield 'listing' => [0, null, '', 0];
        yield 'listing with id' => [0, '7', '', 7];

        // These actions carry a parent or source id, never the edited record.
        yield 'create' => [0, '5', 'create', 0];
        yield 'copy' => [5, '5', 'copy', 0];
        yield 'paste' => [0, '5', 'paste', 0];
        yield 'cut' => [5, '5', 'cut', 0];

        // Nothing usable is never guessed.
        yield 'non numeric id' => [0, 'abc', 'edit', 0];
        yield 'negative id' => [0, '-3', 'edit', 0];
        yield 'zero id' => [0, '0', 'edit', 0];
    }

    public function testAnUnknownRecordIsNeitherARootPageNorHasALanguage(): void
    {
        // Without a framework nothing can be resolved, and nothing is assumed.
        $context = new RootPageContext();

        self::assertNull($context->record(0));
        self::assertNull($context->record(12));
        self::assertFalse($context->isRootPage(12));
        self::assertSame('', $context->rootLanguage(12));
    }

    public function testResettingClearsTheMemoisedRecords(): void
    {
        $context = new RootPageContext();
        $context->record(12);
        $context->reset();

        self::assertNull($context->record(12));
    }
}
