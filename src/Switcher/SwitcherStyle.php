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

namespace Vtinnovations\ContaoMultilingualPagetree\Switcher;

/** The six supported presentations of the existing language switcher. */
enum SwitcherStyle: string
{
    case HorizontalFlags = 'horizontal_flags';
    case HorizontalLabels = 'horizontal_labels';
    case HorizontalFlagsLabels = 'horizontal_flags_labels';
    case VerticalFlags = 'vertical_flags';
    case VerticalLabels = 'vertical_labels';
    case VerticalFlagsLabels = 'vertical_flags_labels';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && null !== ($style = self::tryFrom(strtolower(trim($value))))) {
            return $style;
        }

        return match (is_string($value) ? strtolower(trim($value)) : '') {
            'list' => self::HorizontalLabels,
            'dropdown' => self::VerticalFlagsLabels,
            'flags' => self::HorizontalFlags,
            default => self::HorizontalFlags,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $style): string => $style->value, self::cases());
    }

    public function orientation(): string
    {
        return str_starts_with($this->value, 'vertical_') ? 'vertical' : 'horizontal';
    }

    public function presentation(): string
    {
        return str_ends_with($this->value, '_flags_labels') ? 'flags-labels' : (str_ends_with($this->value, '_labels') ? 'labels' : 'flags');
    }
}
