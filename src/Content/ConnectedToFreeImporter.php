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
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationRecordLocatorInterface;

/**
 * Explicit, one-time copy of the connected structure into free-language records.
 *
 * This is never triggered by saving a configuration: an editor must ask for it.
 * It creates normal free records with new ids, preserves ordering and nesting,
 * resolves inherit/custom/empty field values through point 2 and leaves source
 * records and connected translations untouched. It is a copy, not a live link -
 * no ongoing synchronisation exists.
 */
final class ConnectedToFreeImporter
{
    private const SKIPPED_FIELDS = [
        'id', 'tstamp', 'fieldStates', 'reviewStatus', 'reviewedSourceRevision',
        'reviewedSourceSnapshot', 'reviewedAt', 'reviewedBy',
    ];

    public function __construct(
        private readonly FreeContentStorageInterface $storage,
        private readonly TranslationRecordLocatorInterface $translationLocator,
        private readonly TranslationOverlayResolver $overlayResolver,
        private readonly CapabilityPolicy $capabilities,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Reports what an import would create without writing anything.
     */
    public function dryRun(int $pageId, string $language, int $rootPageId): ImportSummary
    {
        try {
            if ($this->storage->countFreeArticles($rootPageId, $language) > 0) {
                return ImportSummary::alreadyImported();
            }

            $articles = $this->storage->findSourceArticles($pageId);
            $contentCount = 0;

            foreach ($articles as $article) {
                $contentCount += $this->countContent('tl_article', (int) ($article['id'] ?? 0));
            }

            return ImportSummary::planned(count($articles), $contentCount);
        } catch (\Throwable $exception) {
            $this->logger?->error('Contao Multilingual Pagetree: content import dry run failed: '.$exception->getMessage());

            return ImportSummary::failed();
        }
    }

    /**
     * @param bool $confirmed The editor explicitly asked for the import
     */
    public function import(int $pageId, string $language, int $rootPageId, bool $confirmed): ImportSummary
    {
        // The import materialises a free content tree, so it needs the same
        // capability as the mode itself. Checked here, at the write boundary.
        if (!$this->capabilities->allows(Capability::FreeContentMode)) {
            return ImportSummary::denied();
        }

        if (!$confirmed) {
            return ImportSummary::unconfirmed();
        }

        if ($pageId <= 0 || '' === trim($language) || $rootPageId <= 0) {
            return ImportSummary::failed();
        }

        // Repeated execution is prevented instead of duplicating content.
        if ($this->storage->countFreeArticles($rootPageId, $language) > 0) {
            return ImportSummary::alreadyImported();
        }

        $ownership = ContentOwnership::free($language, $rootPageId);
        $articles = 0;
        $elements = 0;

        $this->storage->beginTransaction();

        try {
            foreach ($this->storage->findSourceArticles($pageId) as $sourceArticle) {
                $sourceId = (int) ($sourceArticle['id'] ?? 0);

                if ($sourceId <= 0) {
                    continue;
                }

                $row = $this->translatedRow($sourceArticle, 'tl_article_translation', $sourceId, $language);
                $row = array_merge($row, $ownership->toRow());
                $row['pid'] = $pageId;

                $freeArticleId = $this->storage->insertRecord('tl_article', $this->filterColumns('tl_article', $row));

                if ($freeArticleId <= 0) {
                    throw new \RuntimeException('The free article could not be created.');
                }

                ++$articles;
                $elements += $this->copyContent('tl_article', $sourceId, 'tl_article', $freeArticleId, $ownership, $language);
            }

            $this->storage->commit();

            return ImportSummary::imported($articles, $elements);
        } catch (\Throwable $exception) {
            // A partial import is rolled back so no half structure remains.
            $this->storage->rollBack();
            $this->logger?->error('Contao Multilingual Pagetree: content import failed and was rolled back: '.$exception->getMessage());

            return ImportSummary::failed();
        }
    }

    /**
     * Copies one level of content and recurses into nested elements, remapping
     * parent ids to the newly created records.
     */
    private function copyContent(
        string $sourceParentTable,
        int $sourceParentId,
        string $targetParentTable,
        int $targetParentId,
        ContentOwnership $ownership,
        string $language,
    ): int {
        $created = 0;

        foreach ($this->storage->findChildContent($sourceParentTable, $sourceParentId) as $sourceElement) {
            $sourceId = (int) ($sourceElement['id'] ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $row = $this->translatedRow($sourceElement, 'tl_content_translation', $sourceId, $language);
            $row = array_merge($row, $ownership->toRow());
            $row['pid'] = $targetParentId;
            $row['ptable'] = $targetParentTable;

            $freeId = $this->storage->insertRecord('tl_content', $this->filterColumns('tl_content', $row));

            if ($freeId <= 0) {
                throw new \RuntimeException('The free content element could not be created.');
            }

            ++$created;
            $created += $this->copyContent('tl_content', $sourceId, 'tl_content', $freeId, $ownership, $language);
        }

        return $created;
    }

    private function countContent(string $parentTable, int $parentId): int
    {
        $count = 0;

        foreach ($this->storage->findChildContent($parentTable, $parentId) as $element) {
            ++$count;
            $count += $this->countContent('tl_content', (int) ($element['id'] ?? 0));
        }

        return $count;
    }

    /**
     * The source row with the supported translated values resolved through the
     * point 2 field states. Field-state and review metadata is never copied:
     * free records are independent content, not overlays.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function translatedRow(array $source, string $translationTable, int $sourceId, string $language): array
    {
        $row = $source;

        foreach (self::SKIPPED_FIELDS as $field) {
            unset($row[$field]);
        }

        $translation = null;

        try {
            $translation = $this->translationLocator->find($translationTable, $sourceId, $language);
        } catch (\Throwable) {
            $translation = null;
        }

        if (null === $translation) {
            return $row;
        }

        foreach (array_keys($row) as $field) {
            if (!is_string($field)) {
                continue;
            }

            try {
                $resolved = $this->overlayResolver->resolveField($source, $translation, $field, $translationTable);
            } catch (\Throwable) {
                continue;
            }

            // resolveField returns the source value for inherited fields, the
            // stored value for custom fields and a typed empty value for fields
            // deliberately left empty.
            $row[$field] = $resolved;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $row): array
    {
        $columns = $this->storage->columns($table);

        if ([] === $columns) {
            return $row;
        }

        $filtered = array_intersect_key($row, array_flip($columns));
        $filtered['tstamp'] = time();

        return array_intersect_key($filtered, array_flip($columns));
    }
}
