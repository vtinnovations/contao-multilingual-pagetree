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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Fixtures;

use Contao\PageModel;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds Contao page models backed by a plain row, without a database.
 */
trait PageModelMockTrait
{
    /**
     * @param array<string, mixed> $row
     *
     * @return PageModel&MockObject
     */
    protected function mockPageModel(array $row): PageModel
    {
        $data = $row;
        $page = $this->createMock(PageModel::class);

        // All closures share the same row by reference so writes through __set
        // are visible to row() and __get, exactly like a real model.
        $page->method('row')->willReturnCallback(
            static function () use (&$data): array {
                return $data;
            },
        );
        $page->method('__get')->willReturnCallback(
            static function (string $key) use (&$data): mixed {
                return $data[$key] ?? null;
            },
        );
        $page->method('__isset')->willReturnCallback(
            static function (string $key) use (&$data): bool {
                return isset($data[$key]);
            },
        );
        $page->method('__set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$data): void {
                $data[$key] = $value;
            },
        );

        return $page;
    }

    /**
     * A published regular page of a root site.
     *
     * @param array<string, mixed> $overrides
     *
     * @return PageModel&MockObject
     */
    protected function mockRegularPage(int $id, int $rootId, string $alias, array $overrides = []): PageModel
    {
        return $this->mockPageModel(array_merge([
            'id' => $id,
            'pid' => $rootId,
            'rootId' => $rootId,
            'type' => 'regular',
            'alias' => $alias,
            'title' => ucfirst(str_replace('-', ' ', $alias)),
            'published' => '1',
            'start' => '',
            'stop' => '',
            'urlSuffix' => '',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return PageModel&MockObject
     */
    protected function mockRootPage(int $id, string $language, array $overrides = []): PageModel
    {
        return $this->mockPageModel(array_merge([
            'id' => $id,
            'pid' => 0,
            'rootId' => $id,
            'type' => 'root',
            'alias' => 'index',
            'language' => $language,
            'published' => '1',
            'start' => '',
            'stop' => '',
            'urlSuffix' => '',
        ], $overrides));
    }
}
