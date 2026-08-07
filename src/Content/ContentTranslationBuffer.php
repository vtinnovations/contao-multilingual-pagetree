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

use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects the translated values of one submission between the field callbacks
 * and the record callback.
 *
 * Contao validates and normalises a field, hands it to that field's
 * `save_callback`, and only then writes it. That callback is therefore the one
 * moment where a normalised translated value exists and the source row has not
 * been touched yet - but it fires once per field, so the values have to be
 * gathered somewhere before the record-level callback can store them together.
 *
 * The buffer is request scoped and is released between worker cycles, so one
 * editor's submission can never leak into the next request.
 */
final class ContentTranslationBuffer implements ResetInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    /** Captured values are keyed by source element *and* language. */
    public function capture(int $sourceId, string $language, string $field, mixed $value): void
    {
        if ($sourceId <= 0 || '' === $language || '' === $field) {
            return;
        }

        $this->values[$this->key($sourceId, $language)][$field] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(int $sourceId, string $language): array
    {
        return $this->values[$this->key($sourceId, $language)] ?? [];
    }

    public function has(int $sourceId, string $language): bool
    {
        return [] !== $this->values($sourceId, $language);
    }

    /** Releases one submission once it has been persisted. */
    public function release(int $sourceId, string $language): void
    {
        unset($this->values[$this->key($sourceId, $language)]);
    }

    public function reset(): void
    {
        $this->values = [];
    }

    private function key(int $sourceId, string $language): string
    {
        return $sourceId.'|'.strtolower($language);
    }
}
