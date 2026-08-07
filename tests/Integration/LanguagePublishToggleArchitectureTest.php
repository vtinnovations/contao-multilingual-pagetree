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

final class LanguagePublishToggleArchitectureTest extends TestCase
{
    public function testFormAndEyeToggleReachOneCanonicalValidatorThroughTypedAdapters(): void
    {
        $validator = file_get_contents(__DIR__.'/../../src/Backend/LanguageUrlDca.php');
        $dca = file_get_contents(__DIR__.'/../../contao/dca/tl_inline_language.php');
        self::assertIsString($validator);
        self::assertIsString($dca);

        self::assertStringContainsString('validatePublished(mixed $value, DataContainer $dc)', $validator);
        self::assertStringContainsString('validatePublishedState(int $recordId, bool $published)', $validator);
        self::assertStringContainsString('$this->validatePublishedState($recordId, (bool) $value)', $validator);
        self::assertStringContainsString('->validatePublishedState($intId, $blnVisible)', $dca);
        self::assertStringNotContainsString("fields']['published']['save_callback']", $dca);
    }

    public function testUnpublishSkipsPublishOnlyCollisionValidation(): void
    {
        $validator = file_get_contents(__DIR__.'/../../src/Backend/LanguageUrlDca.php');
        self::assertIsString($validator);
        self::assertMatchesRegularExpression('/if \(\$published\) \{\s*\$this->assertRecordIsResolvable/s', $validator);
        self::assertStringContainsString('$this->scope->assertRecordWrite($recordId)', $validator);
    }

    public function testDefaultFlagIsPartOfTheRootScopedDescriptorAndEmptyFlagsDoNotRenderBrokenImages(): void
    {
        $registry = file_get_contents(__DIR__.'/../../src/Availability/ModelSiteLanguageRegistry.php');
        $template = file_get_contents(__DIR__.'/../../contao/templates/mod_language_switcher.html.twig');
        self::assertIsString($registry);
        self::assertIsString($template);
        self::assertStringContainsString('LanguageFlagMapper', $registry);
        self::assertStringContainsString('defaultFlag($defaultLanguage)', $registry);
        self::assertStringContainsString("and lang.flag", $template);
        self::assertStringContainsString('alt="{{ lang.label }}"', $template);
    }
}
