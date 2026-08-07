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
 * The single authority for "what is the reviewable state of this source
 * record?".
 *
 * The field selection comes exclusively from the point 7 field-policy registry,
 * so third-party registered fields participate automatically and protected
 * structural fields never do. The calculation never mutates the source record
 * and never depends on backend request state or DCA load order.
 */
final class SourceFingerprintCalculator
{
    /** @var array<string, SourceFingerprint> */
    private array $cache = [];

    public function __construct(
        private readonly TranslationFieldRegistry $fields,
        private readonly CanonicalValueNormalizer $normalizer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param string $entityType Entity name, source table or translation table
     */
    public function createFingerprint(string $entityType, object|array $sourceRecord): SourceFingerprint
    {
        try {
            $row = $this->row($sourceRecord);
            $policy = $this->fields->getPolicy($entityType);

            if ('' === $policy->sourceTable) {
                return SourceFingerprint::empty();
            }

            $contentType = 'tl_content' === $policy->sourceTable && isset($row['type']) && is_string($row['type'])
                ? $row['type']
                : null;

            $values = [];

            foreach ($policy->fields($contentType) as $field => $valueType) {
                // A field the record does not carry is skipped instead of being
                // invented; a removed policy field simply disappears.
                if (!array_key_exists($field, $row)) {
                    continue;
                }

                $values[$field] = $this->normalizer->normalize($row[$field], is_string($valueType) ? $valueType : null);
            }

            // Field order must never influence the hash.
            ksort($values, SORT_STRING);

            return SourceFingerprint::create($this->hash($values), $values);
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf(
                'Contao Multilingual Pagetree: could not fingerprint a "%s" source record: %s',
                $entityType,
                $exception->getMessage(),
            ));

            return SourceFingerprint::empty();
        }
    }

    /**
     * Memoised per request so a list view fingerprints one source record once.
     */
    public function cachedFingerprint(string $entityType, int $sourceId, object|array $sourceRecord): SourceFingerprint
    {
        $key = $entityType.'|'.$sourceId;

        return $this->cache[$key] ??= $this->createFingerprint($entityType, $sourceRecord);
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function hash(array $values): string
    {
        if ([] === $values) {
            return '';
        }

        $json = json_encode(
            $values,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (false === $json) {
            return '';
        }

        return hash('sha256', $json);
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
