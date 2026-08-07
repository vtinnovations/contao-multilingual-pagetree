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

namespace Vtinnovations\ContaoMultilingualPagetree\Content;

use Psr\Log\LoggerInterface;

/**
 * Counts the content a mode change would make inactive.
 */
final class ModeSwitchAnalyzer
{
    public function __construct(
        private readonly FreeContentStorageInterface $storage,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyse(
        int $rootPageId,
        string $language,
        ContentTranslationMode $current,
        ContentTranslationMode $requested,
    ): ModeSwitchSummary {
        $connectedArticles = 0;
        $connectedContent = 0;
        $freeArticles = 0;
        $freeContent = 0;

        try {
            $connectedArticles = $this->storage->countConnectedTranslations('tl_article_translation', $language);
            $connectedContent = $this->storage->countConnectedTranslations('tl_content_translation', $language);
            $freeArticles = $this->storage->countFreeArticles($rootPageId, $language);
            $freeContent = $this->storage->countFreeContentElements($rootPageId, $language);
        } catch (\Throwable $exception) {
            // A failed count must never block the backend; it only means the
            // confirmation cannot state exact numbers.
            $this->logger?->error('Contao Multilingual Pagetree: could not analyse a content mode switch: '.$exception->getMessage());
        }

        return new ModeSwitchSummary(
            $language,
            $rootPageId,
            $current,
            $requested,
            $connectedArticles,
            $connectedContent,
            $freeArticles,
            $freeContent,
        );
    }
}
