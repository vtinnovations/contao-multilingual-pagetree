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

        self::assertSame(9, substr_count($workflow, '          - name: Contao '));
        self::assertStringContainsString('strategy: lowest', $workflow);
        self::assertStringContainsString('strategy: highest', $workflow);
    }

    public function testBoundaryStacksAndPackageSuitesRemainConfigured(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/compatibility.yml');

        self::assertMatchesRegularExpression("/php: '8\.1'\s+contao: '5\.0\.\*'\s+strategy: lowest/", $workflow);
        self::assertDoesNotMatchRegularExpression("/php: '8\.5'\s+contao: '5\.0\.\*'/", $workflow);
        self::assertMatchesRegularExpression("/php: '8\.3'\s+contao: '5\.7\.\*'\s+strategy: stable/", $workflow);
        self::assertMatchesRegularExpression("/php: '8\.5'\s+contao: '5\.7\.\*'\s+strategy: highest/", $workflow);
        self::assertStringContainsString('run: composer test:all', $workflow);
        self::assertStringNotContainsString('contao/managed-edition', $workflow);
        self::assertStringNotContainsString('run-application-smoke.sh', $workflow);
    }

    public function testPublicIdentityIsAssertedInAutomation(): void
    {
        $identityCheck = (string) file_get_contents(dirname(__DIR__, 2).'/tools/validate-identity.php');

        self::assertStringContainsString('vtinnovations/contao-multilingual-pagetree', $identityCheck);
        self::assertStringContainsString('Vtinnovations\\\\ContaoMultilingualPagetree', $identityCheck);
        self::assertStringContainsString('VtinnovationsContaoMultilingualPagetreeBundle', $identityCheck);
        self::assertStringContainsString('contao_multilingual_pagetree', $identityCheck);
    }

    public function testEveryCiEntrypointIsPartOfTheSourceTree(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'phpunit.xml.dist',
            'tools/verify-installed-stack.php',
            'tools/validate-identity.php',
            'tools/validate-config.php',
            'tools/validate-release-keys.php',
            'tools/check-release-material.php',
            'tools/build-release.php',
            'tools/verify-release-artefact.php',
            'tools/ci/compile-artefact-container.php',
        ] as $path) {
            self::assertFileExists($root.'/'.$path, sprintf('CI entrypoint "%s" must be published with the source repository.', $path));
        }

        self::assertDirectoryExists($root.'/tests');
    }

    public function testWorkflowUsesNode24ActionReleases(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/compatibility.yml');

        self::assertStringContainsString('actions/checkout@v6', $workflow);
        self::assertStringContainsString('actions/cache@v5', $workflow);
        self::assertStringNotContainsString('actions/checkout@v4', $workflow);
        self::assertStringNotContainsString('actions/cache@v4', $workflow);
    }
}
