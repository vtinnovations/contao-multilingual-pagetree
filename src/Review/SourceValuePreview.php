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

/**
 * Builds safe plain-text previews of canonical source values.
 *
 * Rich text is reduced to text, raw HTML and code are shown as escaped text,
 * arrays are summarised in a readable way and nothing is ever executed or
 * rendered as markup.
 */
final class SourceValuePreview
{
    private const MAX_LENGTH = 160;

    /**
     * @param array<string, mixed>|null $canonicalValue A value produced by CanonicalValueNormalizer
     */
    public function fromCanonical(?array $canonicalValue): string
    {
        if (null === $canonicalValue) {
            return '';
        }

        return $this->truncate($this->describe($canonicalValue, 0));
    }

    /**
     * @param array<string, mixed> $canonicalValue
     */
    private function describe(array $canonicalValue, int $depth): string
    {
        $type = is_string($canonicalValue['t'] ?? null) ? $canonicalValue['t'] : 'unknown';
        $value = $canonicalValue['v'] ?? null;

        return match ($type) {
            'null' => 'NULL',
            'bool' => true === $value ? 'true' : 'false',
            'int', 'float' => (string) (is_scalar($value) ? $value : ''),
            'string' => $this->plainText(is_string($value) ? $value : ''),
            'headline' => $this->describeHeadline(is_array($value) ? $value : [], $depth),
            'array' => $this->describeArray(is_array($value) ? $value : [], $depth),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $value
     */
    private function describeHeadline(array $value, int $depth): string
    {
        $headline = is_array($value['value'] ?? null) ? $this->describe($value['value'], $depth + 1) : '';
        $unit = is_array($value['unit'] ?? null) ? $this->describe($value['unit'], $depth + 1) : '';

        return '' !== $unit ? sprintf('%s (%s)', $headline, $unit) : $headline;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function describeArray(array $value, int $depth): string
    {
        if ($depth > 3) {
            return '…';
        }

        $parts = [];

        foreach ($value as $key => $item) {
            if (count($parts) >= 5) {
                $parts[] = '…';
                break;
            }

            $described = is_array($item) ? $this->describe($item, $depth + 1) : '';
            $parts[] = is_int($key) || (is_string($key) && ctype_digit($key))
                ? $described
                : sprintf('%s: %s', $this->plainText((string) $key), $described);
        }

        return '['.implode(', ', $parts).']';
    }

    /**
     * Rich text, raw HTML and code all become collapsed plain text. Tags are
     * removed rather than escaped here; escaping for output happens in the
     * renderer.
     */
    private function plainText(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function truncate(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        return mb_strimwidth($value, 0, self::MAX_LENGTH, '…');
    }
}
