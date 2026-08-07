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

namespace Vtinnovations\ContaoMultilingualPagetree\Availability;

/**
 * Contao compatible publication semantics for source and translation records.
 *
 * Legacy values are tolerated: only the explicit "unpublished" markers Contao
 * writes are treated as unpublished, and only a start/stop value that is
 * actually set is evaluated.
 */
final class PublicationChecker
{
    /**
     * @param bool $previewMode True when Contao's authorised preview context is active
     */
    public function isPublished(object|array|null $record, bool $previewMode = false): bool
    {
        if (null === $record) {
            return true;
        }

        if ($previewMode) {
            return true;
        }

        $row = $this->row($record);

        return PageAvailabilityReason::Available === $this->reason($row);
    }

    /**
     * The publication reason of a record, used for diagnostics.
     */
    public function publicationReason(object|array|null $record, bool $previewMode = false): PageAvailabilityReason
    {
        if (null === $record || $previewMode) {
            return PageAvailabilityReason::Available;
        }

        return $this->reason($this->row($record));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reason(array $row): PageAvailabilityReason
    {
        if (array_key_exists('published', $row) && in_array($row['published'], ['', '0', 0, false, null], true)) {
            return PageAvailabilityReason::Unpublished;
        }

        if (array_key_exists('invisible', $row) && in_array($row['invisible'], ['1', 1, true], true)) {
            return PageAvailabilityReason::Unpublished;
        }

        $time = time();

        if (!empty($row['start']) && (int) $row['start'] > $time) {
            return PageAvailabilityReason::NotStarted;
        }

        if (!empty($row['stop']) && (int) $row['stop'] < $time) {
            return PageAvailabilityReason::Expired;
        }

        return PageAvailabilityReason::Available;
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
