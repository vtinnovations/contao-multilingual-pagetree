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

final class TranslationSchemaCoverageTest extends TestCase
{
    public function testTranslationDcaBuildsPhysicalValuesAndOneJsonStateColumn(): void
    {
        $policy = file_get_contents(__DIR__.'/../../src/Backend/TranslationPolicyDca.php');
        $states = file_get_contents(__DIR__.'/../../src/Backend/TranslationStateDca.php');

        self::assertIsString($policy);
        self::assertIsString($states);
        self::assertStringContainsString('$definition = self::safeField($sourceFields[$field]);', $policy);
        self::assertStringContainsString('array_key_exists(\'sql\', $definition)', $policy);
        self::assertStringContainsString("'sql' => \"text NULL\"", $states);
        self::assertStringContainsString("'fieldStates'", $states);
        self::assertStringContainsString('onbeforesubmit_callback', $states);
        self::assertStringContainsString('withoutVirtualStateFields', $states);
        self::assertStringNotContainsString("'fieldState_title' => [", $states);
    }

    public function testPageAndContentMappingsUseCanonicalNativeNames(): void
    {
        $registry = file_get_contents(__DIR__.'/../../src/Translation/TranslationFieldRegistry.php');

        self::assertIsString($registry);
        foreach (["'title' => 'string'", "'pageTitle' => 'string'", "'description' => 'string'", "'alias' => 'string'", "'headline' => 'headline'", "'text' => 'string'"] as $mapping) {
            self::assertStringContainsString($mapping, $registry);
        }
    }
}
