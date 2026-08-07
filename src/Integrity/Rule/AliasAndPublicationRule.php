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

use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Validates translated aliases and translated publication ranges.
 *
 * Aliases are never rewritten from titles: a colliding or unusable alias only
 * makes that one route unavailable, which the availability services already
 * handle. Publication values are only reported, never silently corrected.
 */
final class AliasAndPublicationRule implements IntegrityRuleInterface
{
    public function __construct(private readonly TranslationFieldRegistry $fields)
    {
    }

    public function getName(): string
    {
        return 'alias_and_publication';
    }

    public function getPriority(): int
    {
        return 60;
    }

    public function getSupportedEntities(): array
    {
        return ['page', 'news', 'event', 'faq'];
    }

    public function isRepairable(): bool
    {
        return true;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        $issues = [];

        foreach ($this->fields->policies() as $policy) {
            if ('' === $policy->translationTable || !$scope->coversEntity($policy->entityType)) {
                continue;
            }

            if (!$data->tableExists($policy->translationTable)) {
                continue;
            }

            $requiresAlias = in_array('alias', $policy->aliasFields, true);
            $seenAliases = [];

            foreach ($data->translations($policy->translationTable, $scope) as $record) {
                $language = (string) ($record['language'] ?? '');

                if (!$scope->coversLanguage($language)) {
                    continue;
                }

                $id = (int) ($record['id'] ?? 0);
                $issue = $this->checkAlias($record, $requiresAlias, $seenAliases, $policy->entityType, $policy->translationTable, $id, $scope->rootPageId, $language);

                if (null !== $issue) {
                    $issues[] = $issue;
                }

                $publicationIssue = $this->checkPublication($record, $policy->entityType, $policy->translationTable, $id, $scope->rootPageId, $language);

                if (null !== $publicationIssue) {
                    $issues[] = $publicationIssue;
                }
            }
        }

        return new IntegrityIssueCollection($issues);
    }

    /**
     * @param array<string, mixed>  $record
     * @param array<string, int>    $seenAliases
     */
    private function checkAlias(
        array $record,
        bool $requiresAlias,
        array &$seenAliases,
        string $entityType,
        string $table,
        int $id,
        int $rootPageId,
        string $language,
    ): ?IntegrityIssue {
        if (!$requiresAlias || !array_key_exists('alias', $record)) {
            return null;
        }

        $alias = trim((string) ($record['alias'] ?? ''));
        $states = $this->decodeStates($record['fieldStates'] ?? null);
        $aliasState = $states['alias'] ?? 'inherit';

        // A deliberately empty alias is an editorial choice; the availability
        // services already treat that variant as unavailable.
        if ('' === $alias) {
            if ('custom' !== $aliasState) {
                return null;
            }

            return new IntegrityIssue(
                IntegrityIssueCode::INVALID_ALIAS,
                IntegritySeverity::Warning,
                $entityType,
                $table,
                $id,
                $rootPageId,
                $language,
                IntegrityIssue::REPAIR_MANUAL,
                false,
                null,
                null,
                ['reason' => 'empty_custom_alias'],
            );
        }

        if (1 !== preg_match('#^[\p{L}\p{N}._~/-]+$#u', $alias)) {
            return new IntegrityIssue(
                IntegrityIssueCode::INVALID_ALIAS,
                IntegritySeverity::Error,
                $entityType,
                $table,
                $id,
                $rootPageId,
                $language,
                IntegrityIssue::REPAIR_MANUAL,
                false,
                null,
                null,
                ['reason' => 'invalid_characters'],
            );
        }

        // Uniqueness is checked per root site and per language only: the same
        // alias may validly exist in another root or another language.
        $key = strtolower(str_replace('-', '_', $language)).'|'.mb_strtolower($alias);

        if (isset($seenAliases[$key])) {
            return new IntegrityIssue(
                IntegrityIssueCode::DUPLICATE_ALIAS,
                IntegritySeverity::Error,
                $entityType,
                $table,
                $id,
                $rootPageId,
                $language,
                IntegrityIssue::REPAIR_MANUAL,
                false,
                null,
                null,
                ['conflictsWith' => $seenAliases[$key]],
                [$seenAliases[$key]],
            );
        }

        $seenAliases[$key] = $id;

        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function checkPublication(
        array $record,
        string $entityType,
        string $table,
        int $id,
        int $rootPageId,
        string $language,
    ): ?IntegrityIssue {
        $start = $record['start'] ?? null;
        $stop = $record['stop'] ?? null;

        if (!is_numeric($start) || !is_numeric($stop)) {
            return null;
        }

        $start = (int) $start;
        $stop = (int) $stop;

        if ($start <= 0 || $stop <= 0 || $stop >= $start) {
            return null;
        }

        // A reversed range is reported; correcting editorial scheduling always
        // needs an explicit decision.
        return new IntegrityIssue(
            IntegrityIssueCode::INVALID_PUBLICATION_RANGE,
            IntegritySeverity::Warning,
            $entityType,
            $table,
            $id,
            $rootPageId,
            $language,
            IntegrityIssue::REPAIR_MANUAL,
            false,
            null,
            null,
            ['start' => $start, 'stop' => $stop],
        );
    }

    /**
     * @return array<string, string>
     */
    private function decodeStates(mixed $raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $states = [];

        foreach ($decoded as $field => $state) {
            if (is_string($field) && is_string($state)) {
                $states[$field] = $state;
            }
        }

        return $states;
    }
}
