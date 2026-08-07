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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CompatibilityMatrixTest extends TestCase
{
    public function testEveryDeclaredContaoMinorLineHasAMandatoryMatrixEntry(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/compatibility.yml');

        for ($minor = 0; $minor <= 7; ++$minor) {
            self::assertStringContainsString("contao: '5.$minor.*'", $workflow);
        }

        self::assertSame(10, substr_count($workflow, 'allow_failure: false'));
        self::assertStringNotContainsString('allow_failure: true', $workflow);
        self::assertStringContainsString('strategy: lowest', $workflow);
        self::assertStringContainsString('strategy: highest', $workflow);
    }

    public function testBoundaryStacksAndDatabaseSmokeLayerRemainConfigured(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/compatibility.yml');

        self::assertMatchesRegularExpression("/php: '8\.1'\s+contao: '5\.0\.\*'\s+strategy: lowest/", $workflow);
        self::assertMatchesRegularExpression("/php: '8\.5'\s+contao: '5\.0\.\*'\s+strategy: stable/", $workflow);
        self::assertMatchesRegularExpression("/php: '8\.3'\s+contao: '5\.7\.\*'\s+strategy: stable/", $workflow);
        self::assertMatchesRegularExpression("/php: '8\.5'\s+contao: '5\.7\.\*'\s+strategy: highest/", $workflow);
        self::assertStringContainsString('image: mariadb:10.6', $workflow);
        self::assertStringContainsString('run-application-smoke.sh', $workflow);
    }

    public function testPublicIdentityIsAssertedInAutomation(): void
    {
        $identityCheck = (string) file_get_contents(dirname(__DIR__, 2).'/tools/validate-identity.php');

        self::assertStringContainsString('vtinnovations/contao-multilingual-pagetree', $identityCheck);
        self::assertStringContainsString('Vtinnovations\\\\ContaoMultilingualPagetree', $identityCheck);
        self::assertStringContainsString('VtinnovationsContaoMultilingualPagetreeBundle', $identityCheck);
        self::assertStringContainsString('contao_multilingual_pagetree', $identityCheck);
    }
}
