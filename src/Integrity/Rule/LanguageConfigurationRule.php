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

use Vtinnovations\ContaoMultilingualPagetree\Availability\PageAvailabilityMode;
use Vtinnovations\ContaoMultilingualPagetree\Content\ContentTranslationMode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityDataSourceInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssue;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCode;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityIssueCollection;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegritySeverity;

/**
 * Validates the language configuration of every scanned root site.
 *
 * The same language code may validly exist in several roots, so every check is
 * performed per root and never globally.
 */
final class LanguageConfigurationRule implements IntegrityRuleInterface
{
    private const TABLE = 'tl_inline_language';

    public function getName(): string
    {
        return 'language_configuration';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function getSupportedEntities(): array
    {
        return ['language'];
    }

    public function isRepairable(): bool
    {
        return false;
    }

    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection
    {
        if (!$data->tableExists(self::TABLE)) {
            return new IntegrityIssueCollection();
        }

        $issues = [];

        foreach ($data->rootPageIds($scope) as $rootPageId) {
            $issues = [...$issues, ...$this->scanRoot($rootPageId, $scope, $data)];
        }

        return new IntegrityIssueCollection($issues);
    }

    /**
     * @return list<IntegrityIssue>
     */
    private function scanRoot(int $rootPageId, IntegrityScope $scope, IntegrityDataSourceInterface $data): array
    {
        $issues = [];
        $records = $data->languageConfigurations($rootPageId);

        if ([] === $records) {
            return [];
        }

        $seenLanguages = [];
        $fallbacks = [];
        $rootPage = $data->record('tl_page', $rootPageId);

        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            $language = (string) ($record['language'] ?? '');
            $normalised = strtolower(str_replace('-', '_', trim($language)));

            if (!$scope->coversLanguage($normalised)) {
                continue;
            }

            // A configuration must point at a real root page of this site.
            if (null === $rootPage || 'root' !== (string) ($rootPage['type'] ?? '')) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::INVALID_ROOT_RELATION,
                    IntegritySeverity::Critical,
                    $id,
                    $rootPageId,
                    $language,
                    ['reason' => null === $rootPage ? 'missing_root' : 'not_a_root_page'],
                );
            }

            if ('' === $normalised || 1 !== preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $normalised)) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::INVALID_LANGUAGE_CONFIGURATION,
                    IntegritySeverity::Error,
                    $id,
                    $rootPageId,
                    $language,
                    ['reason' => 'invalid_language_code'],
                );

                continue;
            }

            if (isset($seenLanguages[$normalised])) {
                // Both records may contain meaningful data, so the rule reports
                // the conflict instead of choosing a winner.
                $issues[] = $this->issue(
                    IntegrityIssueCode::DUPLICATE_LANGUAGE_CONFIGURATION,
                    IntegritySeverity::Critical,
                    $id,
                    $rootPageId,
                    $language,
                    ['duplicateOf' => $seenLanguages[$normalised]],
                    IntegrityIssue::REPAIR_MANUAL,
                    [$seenLanguages[$normalised]],
                );
            } else {
                $seenLanguages[$normalised] = $id;
            }

            if (!empty($record['fallback'])) {
                $fallbacks[] = $id;
            }

            $availability = $record['pageAvailabilityMode'] ?? null;

            if (is_string($availability) && '' !== $availability && null === PageAvailabilityMode::tryFrom($availability)) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::INVALID_LANGUAGE_CONFIGURATION,
                    IntegritySeverity::Warning,
                    $id,
                    $rootPageId,
                    $language,
                    ['reason' => 'invalid_page_availability_mode'],
                );
            }

            $contentMode = $record['contentTranslationMode'] ?? null;

            if (is_string($contentMode) && '' !== $contentMode && null === ContentTranslationMode::tryFrom($contentMode)) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::INVALID_LANGUAGE_CONFIGURATION,
                    IntegritySeverity::Warning,
                    $id,
                    $rootPageId,
                    $language,
                    ['reason' => 'invalid_content_translation_mode'],
                );
            }
        }

        if (count($fallbacks) > 1) {
            foreach ($fallbacks as $id) {
                $issues[] = $this->issue(
                    IntegrityIssueCode::MULTIPLE_FALLBACK_LANGUAGES,
                    IntegritySeverity::Critical,
                    $id,
                    $rootPageId,
                    '',
                    ['count' => count($fallbacks)],
                    IntegrityIssue::REPAIR_MANUAL,
                    $fallbacks,
                );
            }
        } elseif ([] === $fallbacks) {
            $issues[] = $this->issue(
                IntegrityIssueCode::MISSING_FALLBACK_LANGUAGE,
                IntegritySeverity::Error,
                0,
                $rootPageId,
                '',
                ['configured' => count($records)],
                IntegrityIssue::REPAIR_MANUAL,
            );
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
        int $recordId,
        int $rootPageId,
        string $language,
        array $context = [],
        string $repairability = IntegrityIssue::REPAIR_NONE,
        array $related = [],
    ): IntegrityIssue {
        return new IntegrityIssue(
            $code,
            $severity,
            'language',
            self::TABLE,
            $recordId,
            $rootPageId,
            $language,
            $repairability,
            false,
            null,
            null,
            $context,
            $related,
        );
    }
}
