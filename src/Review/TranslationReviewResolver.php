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
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

/**
 * Derives the review state of a translation from the reviewed baseline and the
 * current source state.
 *
 * The comparison is always live: a persisted reviewStatus is only a filtering
 * convenience and never overrides the fingerprint comparison. Resolving a state
 * never writes anything and never touches translated values or field states.
 */
final class TranslationReviewResolver
{
    public const FIELD_STATUS = 'reviewStatus';
    public const FIELD_REVISION = 'reviewedSourceRevision';
    public const FIELD_SNAPSHOT = 'reviewedSourceSnapshot';
    public const FIELD_REVIEWED_AT = 'reviewedAt';
    public const FIELD_REVIEWED_BY = 'reviewedBy';

    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly SourceFingerprintCalculator $fingerprints,
        private readonly SourceValuePreview $preview,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param object|array<string, mixed>      $translationRecord
     * @param object|array<string, mixed>|null $sourceRecord      Null when the relation is broken
     */
    public function resolve(string $translationTable, object|array $translationRecord, object|array|null $sourceRecord): ReviewState
    {
        try {
            $translation = $this->row($translationRecord);
            $reviewedAt = (int) ($translation[self::FIELD_REVIEWED_AT] ?? 0);
            $reviewedBy = (int) ($translation[self::FIELD_REVIEWED_BY] ?? 0);

            if (null === $sourceRecord) {
                return ReviewState::sourceMissing($reviewedAt, $reviewedBy);
            }

            $reviewedRevision = $this->revision($translation[self::FIELD_REVISION] ?? null);
            $current = $this->fingerprints->createFingerprint($translationTable, $sourceRecord);

            // No usable baseline: never guess that a translation is up to date.
            if (null === $reviewedRevision) {
                return ReviewState::create(
                    ReviewStatus::Unreviewed,
                    $reviewedAt,
                    $reviewedBy,
                    null,
                    '' !== $current->hash ? $current->hash : null,
                    [],
                );
            }

            if ($current->equalsHash($reviewedRevision)) {
                return ReviewState::create(
                    ReviewStatus::UpToDate,
                    $reviewedAt,
                    $reviewedBy,
                    $reviewedRevision,
                    $current->hash,
                    [],
                );
            }

            return ReviewState::create(
                ReviewStatus::NeedsReview,
                $reviewedAt,
                $reviewedBy,
                $reviewedRevision,
                '' !== $current->hash ? $current->hash : null,
                $this->changedFields($translationTable, $translation, $current),
            );
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not resolve the review state of a "%s" record: %s',
                $translationTable,
                $exception->getMessage(),
            ));

            return ReviewState::create(ReviewStatus::Unreviewed);
        }
    }

    /**
     * The status value that may be persisted for backend filtering.
     */
    public function persistableStatus(ReviewState $state): string
    {
        return ReviewStatus::SourceMissing === $state->status
            ? ReviewStatus::Unreviewed->value
            : $state->status->value;
    }

    /**
     * Fields that changed between the reviewed snapshot and the current source.
     *
     * A missing or malformed snapshot yields no changed fields at all instead of
     * a fabricated difference.
     *
     * @param array<string, mixed> $translation
     *
     * @return list<ChangedSourceField>
     */
    private function changedFields(string $translationTable, array $translation, SourceFingerprint $current): array
    {
        $snapshot = SourceFingerprint::decodeSnapshot($translation[self::FIELD_SNAPSHOT] ?? null);

        if ([] === $snapshot) {
            return [];
        }

        $changed = [];

        foreach ($current->values as $field => $value) {
            // Only fields the current policy still knows are reported.
            if (!$this->isKnownField($translationTable, $field)) {
                continue;
            }

            $reviewed = $snapshot[$field] ?? null;

            if (is_array($reviewed) && $this->identical($reviewed, $value)) {
                continue;
            }

            $changed[] = new ChangedSourceField(
                $field,
                $this->preview->fromCanonical(is_array($reviewed) ? $reviewed : null),
                $this->preview->fromCanonical($value),
            );
        }

        // A field that disappeared from the source since the review is reported
        // as changed as well, but obsolete policy fields are ignored.
        foreach ($snapshot as $field => $reviewed) {
            if (!is_string($field) || array_key_exists($field, $current->values)) {
                continue;
            }

            if (!$this->isKnownField($translationTable, $field)) {
                continue;
            }

            $changed[] = new ChangedSourceField(
                $field,
                $this->preview->fromCanonical(is_array($reviewed) ? $reviewed : null),
                '',
            );
        }

        usort($changed, static fn (ChangedSourceField $a, ChangedSourceField $b): int => $a->field <=> $b->field);

        return $changed;
    }

    private function isKnownField(string $translationTable, string $field): bool
    {
        return array_key_exists($field, $this->fields->getPolicy($translationTable)->fields());
    }

    /**
     * @param array<string, mixed> $reviewed
     * @param array<string, mixed> $current
     */
    private function identical(array $reviewed, array $current): bool
    {
        return json_encode($reviewed, JSON_INVALID_UTF8_SUBSTITUTE) === json_encode($current, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A revision is only usable when it looks like a SHA-256 digest.
     */
    private function revision(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return 1 === preg_match('/^[a-f0-9]{64}$/i', $value) ? strtolower($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object|array $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (method_exists($record, 'row')) {
            $row = $record->row();

            if (is_array($row)) {
                return $row;
            }
        }

        return get_object_vars($record);
    }
}
