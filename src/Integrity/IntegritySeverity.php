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

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

/**
 * Severity of an integrity issue.
 *
 * Severity describes how the invalid state affects the installation. It never
 * decides on its own whether a record is deleted: repairability and the
 * destructive flag of an action do that.
 */
enum IntegritySeverity: string
{
    /** Valid but noteworthy, e.g. inactive connected data while free mode is active. */
    case Info = 'info';

    /** Possibly unintended, but the frontend still renders correctly. */
    case Warning = 'warning';

    /** Invalid data that is ignored or makes one language variant unavailable. */
    case Error = 'error';

    /** Cross-site leakage, destructive ambiguity or route collisions. */
    case Critical = 'critical';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return self::Info;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Info;
    }

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
            self::Critical => 3,
        };
    }

    public function isAtLeast(self $minimum): bool
    {
        return $this->weight() >= $minimum->weight();
    }

    public function blocksExitCode(): bool
    {
        return self::Error === $this || self::Critical === $this;
    }
}
