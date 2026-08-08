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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;

final class RootPagePermissionTest extends TestCase
{
    public function testAdministratorAlwaysHasAccess(): void
    {
        $permission = new RootPagePermission();
        $admin = new PermissionUser(true, false, []);

        self::assertTrue($permission->authorise($admin, 42));
        self::assertTrue($permission->authorise($admin, 0));
    }

    public function testNativeModuleAndPageMountAccessAreSufficient(): void
    {
        $permission = new RootPagePermission();
        $user = new PermissionUser(false, true, [42]);

        self::assertTrue($permission->authorise($user, 42));
    }

    public function testMissingSiteStructureOrPageMountAlwaysDenies(): void
    {
        $permission = new RootPagePermission();

        self::assertFalse($permission->authorise(new PermissionUser(false, false, [42]), 42));
        self::assertFalse($permission->authorise(new PermissionUser(false, true, []), 42));
        self::assertFalse($permission->authorise(new PermissionUser(false, true, [42]), 0));
    }

    public function testNormalContaoFieldAccessControlsTheLicencePanel(): void
    {
        $permission = new RootPagePermission();

        self::assertTrue($permission->canEditField('tl_page', 'contaoMultilingualPagetreeLicencePanel', new PermissionUser(false, true, [42], ['tl_page::contaoMultilingualPagetreeLicencePanel'])));
        self::assertFalse($permission->canEditField('tl_page', 'contaoMultilingualPagetreeLicencePanel', new PermissionUser(false, true, [42])));
        self::assertTrue($permission->canEditField('tl_page', 'contaoMultilingualPagetreeLicencePanel', new PermissionUser(true, false, [])));
    }
}
