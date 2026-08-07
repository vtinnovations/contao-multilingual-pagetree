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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity\Rule;

use Vtinnovations\ContaoMultilingualPagetree\Content\ContentOwnership;
use Vtinnovations\ContaoMultilingualPagetree\Content\FreeContentRelationValidator;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;

/**
 * Validates free-language articles and content elements.
 *
 * Ownership, parent relations and nesting are checked within one language and
 * one root site. Cycles are detected with a bounded walk that never recurses
 * infinitely, and no relation is ever repaired by guessing which parent to drop.
 */
final class FreeContentRule implements IntegrityRuleInterface
{
    private const MAX_DEPTH = 64;

    public function __construct(private readonly FreeContentRelationValidator $relations)
    {
    }

    public function getName(): string
    {
        return 'free_content';
    }

    public function getPriority(): int
    {
        return 70;
    }

    public function getSupportedEntities(): array
    {
        return ['article', 'content'];
    }

    public function isRepairable(): bool
    {
        return true;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        if (!$data->tableExists('tl_article') || !$data->tableExists('tl_content')) {
            return new IntegrityIssueCollection();
        }

        $articles = $data->freeRecords('tl_article', $scope);
        $elements = $data->freeRecords('tl_content', $scope);

        $issues = [
            ...$this->scanArticles($articles, $scope, $data),
            ...$this->scanElements($elements, $articles, $scope),
        ];

        return new IntegrityIssueCollection([...$issues, ...$this->detectCycles($elements, $scope)]);
    }

    /**
     * @param list<array<string, mixed>> $articles
     *
     * @return list<IntegrityIssue>
     */
    private function scanArticles(array $articles, IntegrityScope $scope, IntegrityDataSourceInterface $data): array
    {
        $issues = [];

        foreach ($articles as $article) {
            $ownership = ContentOwnership::fromRecord($article);

            if ($ownership->isSource() || !$scope->coversLanguage($ownership->language)) {
                continue;
            }

            $id = (int) ($article['id'] ?? 0);
            $pageId = (int) ($article['pid'] ?? 0);
            $page = $pageId > 0 ? $data->record('tl_page', $pageId) : null;

            if (null === $page) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::ORPHANED_FREE_CONTENT,
                    IntegritySeverity::Error,
                    'article',
                    'tl_article',
                    $id,
                    $ownership,
                    ['reason' => 'missing_page'],
                    IntegrityIssue::REPAIR_CONFIRMATION,
                );

                continue;
            }

            // The article must belong to the root site it claims.
            $pageRoot = $data->rootPageIdOfSource('tl_page', $pageId);

            if ($pageRoot > 0 && $ownership->rootPageId > 0 && $pageRoot !== $ownership->rootPageId) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::CROSS_SITE_RELATION,
                    IntegritySeverity::Critical,
                    'article',
                    'tl_article',
                    $id,
                    $ownership,
                    ['pageRoot' => $pageRoot],
                    IntegrityIssue::REPAIR_CONFIRMATION,
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param list<array<string, mixed>> $articles
     *
     * @return list<IntegrityIssue>
     */
    private function scanElements(array $elements, array $articles, IntegrityScope $scope): array
    {
        $articlesById = [];

        foreach ($articles as $article) {
            $articlesById[(int) ($article['id'] ?? 0)] = $article;
        }

        $elementsById = [];

        foreach ($elements as $element) {
            $elementsById[(int) ($element['id'] ?? 0)] = $element;
        }

        $issues = [];

        foreach ($elements as $element) {
            $ownership = ContentOwnership::fromRecord($element);

            if ($ownership->isSource() || !$scope->coversLanguage($ownership->language)) {
                continue;
            }

            $id = (int) ($element['id'] ?? 0);
            $parentTable = (string) ($element['ptable'] ?? 'tl_article');
            $parentTable = '' === $parentTable ? 'tl_article' : $parentTable;
            $parentId = (int) ($element['pid'] ?? 0);

            $owner = match ($parentTable) {
                'tl_article' => $articlesById[$parentId] ?? null,
                'tl_content' => $elementsById[$parentId] ?? null,
                default => null,
            };

            $reason = $this->relations->validate($ownership, $owner);

            if (FreeContentRelationValidator::REASON_OK === $reason) {
                continue;
            }

            $code = match ($reason) {
                FreeContentRelationValidator::REASON_MISSING_OWNER => IntegrityIssueCode::ORPHANED_FREE_CONTENT,
                FreeContentRelationValidator::REASON_CROSS_LANGUAGE => IntegrityIssueCode::CROSS_LANGUAGE_RELATION,
                FreeContentRelationValidator::REASON_CROSS_SITE => IntegrityIssueCode::CROSS_SITE_RELATION,
                default => IntegrityIssueCode::INVALID_FREE_PARENT,
            };

            $severity = in_array($code, [IntegrityIssueCode::CROSS_LANGUAGE_RELATION, IntegrityIssueCode::CROSS_SITE_RELATION], true)
                ? IntegritySeverity::Critical
                : IntegritySeverity::Error;

            $issues[] = $this->issue(
                $code,
                $severity,
                'content',
                'tl_content',
                $id,
                $ownership,
                ['reason' => $reason, 'parentTable' => $parentTable, 'parentId' => $parentId],
                IntegrityIssue::REPAIR_CONFIRMATION,
            );
        }

        return $issues;
    }

    /**
     * Bounded cycle detection inside one language and root site.
     *
     * @param list<array<string, mixed>> $elements
     *
     * @return list<IntegrityIssue>
     */
    private function detectCycles(array $elements, IntegrityScope $scope): array
    {
        $byId = [];

        foreach ($elements as $element) {
            $byId[(int) ($element['id'] ?? 0)] = $element;
        }

        $issues = [];
        $reported = [];

        foreach ($byId as $id => $element) {
            $ownership = ContentOwnership::fromRecord($element);

            if ($ownership->isSource() || !$scope->coversLanguage($ownership->language) || isset($reported[$id])) {
                continue;
            }

            $path = [];
            $current = $id;
            $depth = 0;

            while ($depth++ < self::MAX_DEPTH) {
                $record = $byId[$current] ?? null;

                if (null === $record || 'tl_content' !== (string) ($record['ptable'] ?? '')) {
                    break;
                }

                $parentId = (int) ($record['pid'] ?? 0);

                if ($parentId <= 0) {
                    break;
                }

                if (isset($path[$parentId]) || $parentId === $current) {
                    $cycle = array_map('intval', array_keys($path));
                    $cycle[] = $parentId;
                    sort($cycle);

                    foreach ($cycle as $member) {
                        $reported[$member] = true;
                    }

                    $issues[] = $this->issue(
                        IntegrityIssueCode::FREE_CONTENT_CYCLE,
                        IntegritySeverity::Critical,
                        'content',
                        'tl_content',
                        $id,
                        $ownership,
                        ['length' => count($cycle)],
                        // Which relation to break is never guessed.
                        IntegrityIssue::REPAIR_MANUAL,
                        $cycle,
                    );

                    break;
                }

                $path[$current] = true;
                $current = $parentId;
            }
        }

        return $issues;
    }

    /**
     * @param array<string, scalar|null> $context
     * @param list<int>                  $related
     */
    private function issue(
        string $code,
        IntegritySeverity $severity,
        string $entityType,
        string $table,
        int $id,
        ContentOwnership $ownership,
        array $context = [],
        string $repairability = IntegrityIssue::REPAIR_NONE,
        array $related = [],
    ): IntegrityIssue {
        return new IntegrityIssue(
            $code,
            $severity,
            $entityType,
            $table,
            $id,
            $ownership->rootPageId,
            $ownership->language,
            $repairability,
            false,
            null,
            null,
            $context,
            $related,
        );
    }
}
