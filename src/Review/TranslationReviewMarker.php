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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

use Psr\Log\LoggerInterface;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Performs the explicit "mark translation as reviewed" action.
 *
 * The action stores the current source fingerprint as the reviewed baseline. It
 * never changes translated values, field states, aliases or publication fields,
 * and it never marks an orphaned translation as reviewed.
 */
final class TranslationReviewMarker
{
    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly SourceFingerprintCalculator $fingerprints,
        private readonly TranslationReviewStorageInterface $storage,
        private readonly CapabilityPolicy $capabilities,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function markReviewed(string $translationTable, int $translationId, int $userId = 0): ReviewActionResult
    {
        // Reviewing is a licensed capability and is checked here, at the write
        // boundary, not only where the button is rendered.
        if (!$this->capabilities->allows(Capability::TranslationReview)) {
            return ReviewActionResult::failure(ReviewActionResult::REASON_DENIED);
        }

        try {
            $sourceTable = $this->fields->sourceTable($translationTable);

            if (null === $sourceTable || $translationId <= 0) {
                return ReviewActionResult::failure(ReviewActionResult::REASON_INVALID_RECORD);
            }

            $translation = $this->storage->findTranslation($translationTable, $translationId);

            if (null === $translation) {
                return ReviewActionResult::failure(ReviewActionResult::REASON_INVALID_RECORD);
            }

            $sourceId = (int) ($translation['pid'] ?? 0);

            if ($sourceId <= 0) {
                return ReviewActionResult::failure(ReviewActionResult::REASON_SOURCE_MISSING);
            }

            // The relation is the only path to the source record, so a
            // translation can never be reviewed against another site's record.
            $source = $this->storage->findSource($sourceTable, $sourceId);

            if (null === $source) {
                return ReviewActionResult::failure(ReviewActionResult::REASON_SOURCE_MISSING);
            }

            $fingerprint = $this->fingerprints->createFingerprint($translationTable, $source);

            if ($fingerprint->isEmpty()) {
                return ReviewActionResult::failure(ReviewActionResult::REASON_SOURCE_MISSING);
            }

            $this->storage->saveReviewData($translationTable, $translationId, [
                TranslationReviewResolver::FIELD_STATUS => ReviewStatus::UpToDate->value,
                TranslationReviewResolver::FIELD_REVISION => $fingerprint->hash,
                TranslationReviewResolver::FIELD_SNAPSHOT => $fingerprint->toJson(),
                TranslationReviewResolver::FIELD_REVIEWED_AT => time(),
                TranslationReviewResolver::FIELD_REVIEWED_BY => max(0, $userId),
            ]);

            return ReviewActionResult::success($fingerprint->hash);
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not mark the "%s" record %d as reviewed: %s',
                $translationTable,
                $translationId,
                $exception->getMessage(),
            ));

            return ReviewActionResult::failure(ReviewActionResult::REASON_FAILED);
        }
    }

    /**
     * Refreshes the persisted status of all translations of a source record
     * after that source record was saved.
     *
     * @param object|array<string, mixed> $source
     */
    public function refreshForSource(string $sourceTable, int $sourceId, object|array $source): void
    {
        try {
            $policy = $this->fields->getPolicy($sourceTable);

            if ('' === $policy->translationTable || $sourceId <= 0) {
                return;
            }

            $fingerprint = $this->fingerprints->createFingerprint($policy->translationTable, $source);

            // Scoped by the source id, so one site's change can never touch
            // another site's translations.
            $this->storage->refreshStatuses($policy->translationTable, $sourceId, $fingerprint->hash);
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not refresh review states for %s %d: %s',
                $sourceTable,
                $sourceId,
                $exception->getMessage(),
            ));
        }
    }
}
