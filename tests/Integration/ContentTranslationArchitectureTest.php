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
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationFieldPolicy;

/**
 * Additional-language content is edited on the native content form.
 *
 * The store `tl_content_translation` hangs below `tl_content` through `ptable`,
 * which would make it a third level under the article module - Contao has no
 * edit operation for that, which is what produced "Not implemented for
 * tl_content_translation". These assertions hold the repaired architecture in
 * place: the store is storage, the backend edits the real element, and no URL
 * targets the store any more.
 */
class ContentTranslationArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const STORE = 'tl_content_translation';

    private function read(string $file): string
    {
        $path = self::ROOT.'/'.$file;

        $this->assertFileExists($path, $file.' is part of the release.');

        return (string) file_get_contents($path);
    }

    /** No backend URL may target the storage table any more. */
    public function testNoBackendUrlTargetsTheStorageTable(): void
    {
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString(
            'if (ContentTranslationFieldPolicy::SOURCE_TABLE === $scope->sourceTable) {',
            $tabs,
            'A content element must be translated on its own native form.',
        );

        // The article/news/event/FAQ stores still redirect; only content does
        // not - so the generic redirect must be unreachable for tl_content.
        $position = strpos($tabs, 'ContentTranslationFieldPolicy::SOURCE_TABLE === $scope->sourceTable');
        $redirect = strpos($tabs, '$translationTable = $scope->translationTable();');

        $this->assertIsInt($position);
        $this->assertIsInt($redirect);
        $this->assertLessThan($redirect, $position, 'Content must return before the store redirect.');
    }

    /** The store is not registered as a backend module table. */
    public function testTheStoreIsNotAModuleTable(): void
    {
        $config = $this->read('contao/config/config.php');

        $this->assertStringNotContainsString(
            "['tables'][] = '".self::STORE."'",
            $config,
            'The storage table must not be openable in any backend module.',
        );

        // The model stays registered: it is still the storage entity.
        $this->assertStringContainsString("\$GLOBALS['TL_MODELS']['".self::STORE."']", $config);
    }

    /** The store carries columns only: no data container, no palettes. */
    public function testTheStoreIsStorageOnly(): void
    {
        $dca = $this->read('contao/dca/'.self::STORE.'.php');

        $this->assertStringNotContainsString('dataContainer', $dca);
        $this->assertStringNotContainsString("'palettes'", $dca);
        $this->assertStringNotContainsString("'list'", $dca);
        $this->assertStringNotContainsString('language_tabs', $dca);
        $this->assertStringNotContainsString('handleTranslationRedirection', $dca);

        foreach (['notEditable', 'notCreatable', 'notDeletable', 'closed'] as $guard) {
            $this->assertStringContainsString("'".$guard."' => true", $dca, $guard);
        }

        // The columns are still declared, so no schema update can drop them.
        $this->assertStringContainsString('ContentTranslationSchema::configure', $dca);
        $this->assertStringContainsString("'fieldStates'", $dca);
    }

    /** The adapter is registered on the native content table. */
    public function testTheAdapterIsRegisteredOnTheNativeTable(): void
    {
        $dca = $this->read('contao/dca/tl_content.php');

        $this->assertStringContainsString("ContentTranslationAdapter::configure('tl_content')", $dca);

        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');
        $this->assertStringContainsString("\$GLOBALS['TL_DCA'][\$table]['config']['onload_callback'][] = [self::class, 'prepareTranslationForm'];", $adapter);
        $this->assertStringContainsString("\$GLOBALS['TL_DCA'][\$table]['config']['onbeforesubmit_callback'][] = [self::class, 'persistTranslation'];", $adapter);
    }

    /**
     * The palette needs no help at all now.
     *
     * Contao loads the real content element, so the element type, the palette
     * and every subpalette are natively correct. Nothing rebuilds a form and
     * nothing mirrors a selector.
     */
    public function testTheNativePaletteIsUsedWithoutReconstruction(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringNotContainsString("['palettes']", $adapter);
        $this->assertStringNotContainsString("['subpalettes']", $adapter);
        $this->assertStringNotContainsString('synchroniseStructure', $adapter);
        $this->assertStringNotContainsString('translation_legend', $adapter);
        $this->assertStringNotContainsString('fieldState_', $adapter);

        $this->assertFileDoesNotExist(self::ROOT.'/src/Backend/ContentTranslationDca.php');
        $this->assertFileDoesNotExist(self::ROOT.'/src/Migration/ContentTranslationStructureMigration.php');

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('ContentTranslationDca', $contents, basename($file));
            $this->assertStringNotContainsString('ContentTranslationStructureMigration', $contents, basename($file));
        }
    }

    /** The source element is never overwritten by a translated save. */
    public function testTheSourceRecordIsNeverOverwritten(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString('$this->repository->save(', $adapter);
        $this->assertStringContainsString('$unchanged[$field] = $source[$field];', $adapter);
        $this->assertStringContainsString('return $unchanged;', $adapter);

        // The adapter never writes to the content table itself.
        $this->assertStringNotContainsString('UPDATE tl_content', $adapter);
        $this->assertStringNotContainsString('INSERT INTO', $adapter);
    }

    /** Render, load and save all consult the one canonical field policy. */
    public function testOneFieldPolicyGovernsEverything(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString('$this->policy->role(', $adapter);
        $this->assertStringContainsString('$this->policy->filterSubmission(', $adapter);
        $this->assertStringContainsString('$this->policy->translatableFields(', $adapter);
        $this->assertStringContainsString('$this->policy->persistedColumns()', $adapter);
    }

    /** Two languages of one element can never share a row. */
    public function testLanguageStorageIsIsolated(): void
    {
        $repository = $this->read('src/Content/ContentTranslationRepository.php');

        $this->assertStringContainsString('WHERE pid = :pid AND language = :language', $repository);
        $this->assertStringContainsString("unset(\$values['pid'], \$values['language'], \$values['id']);", $repository);
        $this->assertStringContainsString("'pid,language' => 'unique'", $this->read('contao/dca/'.self::STORE.'.php'));
    }

    /** Opening a tab must not create a translation row. */
    public function testOpeningAFormCreatesNothing(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');
        $prepare = $this->methodBody($adapter, 'prepareTranslationForm', 'loadTranslatedValue');
        $load = $this->methodBody($adapter, 'loadTranslatedValue', 'persistTranslation');

        foreach ([$prepare, $load] as $body) {
            $this->assertStringNotContainsString('$this->repository->save(', $body);
        }
    }

    /** Free mode keeps its own native records and its own type. */
    public function testFreeModeIsUnaffected(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');
        $tabs = $this->read('src/Backend/LanguageTabs.php');

        $this->assertStringContainsString('$scope->contentMode->isFree() ? null : $scope;', $adapter);
        $this->assertStringContainsString('freeContentTarget(', $tabs);
    }

    /** Root, language, permission and licence come from the central resolver. */
    public function testAuthorisationIsNotReimplemented(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString('$this->context->scope(', $adapter);
        $this->assertStringNotContainsString('BackendUser', $adapter);
        $this->assertStringNotContainsString('CapabilityPolicy', $adapter);
        $this->assertStringNotContainsString('RootPagePermission', $adapter);
    }

    /** No content-level translation-state UI was reintroduced. */
    public function testNoSyntheticTranslationUiExists(): void
    {
        foreach ($this->sourceFiles(self::ROOT.'/src/Content') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('fieldState_', $contents, basename($file));
            $this->assertStringNotContainsString('translation_legend', $contents, basename($file));
        }

        $this->assertStringContainsString(
            "TranslationStateDca::configure('tl_page_translation')",
            $this->read('contao/dca/tl_page_translation.php'),
            'Page field states are deliberately unchanged.',
        );
    }

    /** The frontend path is unchanged and still registered. */
    public function testTheFrontendPathRemainsIntact(): void
    {
        $listener = $this->read('src/EventListener/ContentTranslationListener.php');

        $this->assertStringContainsString("#[AsHook('isVisibleElement')]", $listener);
        $this->assertStringContainsString('$this->scopedOverlay->apply(', $listener);
        $this->assertStringContainsString('$languageMode = $this->languageMode($element, $language);', $listener);
        $this->assertStringContainsString('self::TRANSLATION_TABLE', $listener);
    }

    /** Unrelated systems stay untouched. */
    public function testUnrelatedSystemsAreUnchanged(): void
    {
        $this->assertStringContainsString(
            'sodium_crypto_sign_verify_detached',
            $this->read('src/Support/DetachedSignature.php'),
        );
        $this->assertStringContainsString('EntryPointNormalizer', $this->read('src/Url/LanguageUrlResolver.php'));
        $this->assertStringContainsString('IncomingLanguageResolver', $this->read('src/Helper/LanguageHelper.php'));
        $this->assertStringContainsString('urlEntryPoint', $this->read('contao/dca/tl_inline_language.php'));
    }

    /** Every new class is a registered service. */
    public function testServicesAreRegistered(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');

        foreach ([
            'Backend\ContentTranslationAdapter',
            'Backend\ContentTranslationSchema',
            'Content\ContentTranslationRepository',
            'Content\ContentTranslationFieldPolicy',
            'Content\ContentValueProvenance',
        ] as $service) {
            $this->assertStringContainsString(
                'Vtinnovations\ContaoMultilingualPagetree\\'.$service.':',
                $services,
                $service,
            );
            $this->assertFileExists(self::ROOT.'/src/'.str_replace('\\', '/', $service).'.php', $service);
        }
    }

    /**
     * Persistence uses only the two callbacks Contao has always had.
     *
     * Rendering worked while saving did not, because rendering used
     * `onload_callback` and a field `load_callback` - both long-standing - while
     * persistence hung on `onbeforesubmit_callback`. Capturing in a field
     * `save_callback` and storing in `onsubmit_callback` removes that
     * dependency entirely.
     */
    public function testPersistenceUsesEstablishedCallbacksOnly(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString(
            "\$GLOBALS['TL_DCA'][\$table]['config']['onsubmit_callback'][] = [self::class, 'flushTranslation'];",
            $adapter,
        );
        $this->assertStringContainsString(
            "\$GLOBALS['TL_DCA'][\$table]['fields'][\$field]['save_callback'][] = [self::class, 'captureTranslatedValue'];",
            $adapter,
        );
        $this->assertStringNotContainsString(
            'onbeforesubmit_callback',
            $adapter,
            'The content save path must not depend on onbeforesubmit_callback.',
        );

        $this->assertStringContainsString('public function captureTranslatedValue(', $adapter);
        $this->assertStringContainsString('public function flushTranslation(', $adapter);
        $this->assertStringNotContainsString('public function persistTranslation(', $adapter);
    }

    /**
     * Every native save button reaches the same path.
     *
     * `onsubmit_callback` fires after all fields and before Contao evaluates the
     * submit button, so no button-specific handling - and no JavaScript - is
     * needed for Save, Save and close, Save and new or Save and go back.
     */
    public function testEverySaveButtonReachesTheSamePath(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        foreach (['saveNclose', 'saveNcreate', 'saveNback', 'saveNduplicate'] as $button) {
            $this->assertStringNotContainsString(
                $button,
                $adapter,
                'Save buttons must be handled by the native lifecycle, not by name.',
            );
        }

        foreach (glob(self::ROOT.'/public/js/*.js') ?: [] as $script) {
            $this->assertStringNotContainsString(
                'contao_multilingual_pagetree_lang',
                (string) file_get_contents($script),
                basename($script).' must not participate in saving.',
            );
        }
    }

    /** The source column is always handed back, so the element is unchanged. */
    public function testTheCaptureCallbackReturnsTheSourceValue(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');
        $capture = $this->methodBody($adapter, 'captureTranslatedValue', 'flushTranslation');

        $this->assertStringContainsString('$this->buffer->capture(', $capture);
        $this->assertSame(
            2,
            substr_count($capture, 'return array_key_exists($field, $source) ? $source[$field] : $value;'),
            'Both the approved and the rejected path must hand the source value back.',
        );

        // Saving a translation must not version the source element.
        $this->assertStringContainsString(
            "\$GLOBALS['TL_DCA'][\$table]['config']['enableVersioning'] = false;",
            $adapter,
        );
    }

    /** A field that still equals the source is never written to the store. */
    public function testSourceCopiesAreNeverMaterialised(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString(
            'FieldStateMap::INHERIT !== ($states[$field] ?? FieldStateMap::INHERIT)',
            $adapter,
        );
        $this->assertStringContainsString(
            'if ([] === $values && null === $this->repository->find($sourceId, $language)) {',
            $adapter,
        );
    }

    /** A failed save is reported, never swallowed. */
    public function testAFailedSaveIsReported(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString('if (!$stored) {', $adapter);
        $this->assertStringContainsString('Message::addError($this->storageFailureMessage());', $adapter);

        foreach (['en', 'de'] as $language) {
            $this->assertStringContainsString(
                'contaoMultilingualPagetreeContentSaveFailed',
                $this->read('contao/languages/'.$language.'/default.php'),
                $language,
            );
        }
    }

    /** A successful save invalidates the affected root only. */
    public function testASuccessfulSaveInvalidatesTheRoot(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');

        $this->assertStringContainsString('$this->cache?->invalidateRoot($scope->rootId);', $adapter);
        $this->assertStringNotContainsString('invalidateAll', $adapter);
    }

    /** Load and save use the same policy, the same keys and the same store. */
    public function testLoadAndSaveAreSymmetric(): void
    {
        $adapter = $this->read('src/Backend/ContentTranslationAdapter.php');
        $load = $this->methodBody($adapter, 'loadTranslatedValue', 'captureTranslatedValue');
        $flush = $this->methodBody($adapter, 'flushTranslation', 'storageFailureMessage');

        // Both address the store by source id and active language.
        $this->assertStringContainsString('$this->sourceId($dc), (string) $scope->activeLanguage', $load);
        $this->assertStringContainsString('$language = (string) $scope->activeLanguage;', $flush);

        // Both go through the one repository, never through raw SQL.
        $this->assertStringContainsString('$this->repository->find(', $load);
        $this->assertStringContainsString('$this->repository->save(', $flush);

        // The adapter reads the source element directly, but never touches the
        // translation store with SQL of its own - that is the repository's job.
        // Only the class docblock, which explains the history, may name it.
        $code = substr($adapter, (int) strpos($adapter, 'final class ContentTranslationAdapter'));

        $this->assertStringNotContainsString(
            ContentTranslationFieldPolicy::TRANSLATION_TABLE,
            $code,
            'Only the repository may address the translation store.',
        );
    }

    /** The buffer is request scoped and released between worker cycles. */
    public function testTheBufferIsRequestScoped(): void
    {
        $services = $this->read('src/Resources/config/services.yaml');

        $this->assertMatchesRegularExpression(
            '/Content\\ContentTranslationBuffer:\s*\n\s*public: true\s*\n\s*tags:.*?kernel\.reset/s',
            $services,
        );
        $this->assertStringContainsString(
            'implements ResetInterface',
            $this->read('src/Content/ContentTranslationBuffer.php'),
        );
    }

    private function methodBody(string $source, string $from, string $to): string
    {
        $start = strpos($source, 'function '.$from.'(');
        $end = strpos($source, 'function '.$to.'(');

        return false === $start || false === $end ? '' : substr($source, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(?string $directory = null): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory ?? self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = (string) $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
