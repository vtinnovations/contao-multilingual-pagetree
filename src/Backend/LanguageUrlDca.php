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

namespace Vtinnovations\ContaoMultilingualPagetree\Backend;

use Contao\Database;
use Contao\DataContainer;
use Vtinnovations\ContaoMultilingualPagetree\Url\EntryPointNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\InvalidLanguageUrlException;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageDomainNormalizer;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlCollisionValidator;
use Vtinnovations\ContaoMultilingualPagetree\Url\LanguageUrlResolver;
use Vtinnovations\ContaoMultilingualPagetree\Url\ProtocolMode;

/**
 * Native DCA save callbacks for the language URL fields.
 *
 * Each callback does two things and nothing else: it normalises its own field
 * through the one central normaliser, and it re-runs the central collision
 * validation for the whole record. Both the normalisation rules and the
 * collision rules live in the URL services, so a direct POST that bypasses the
 * form is validated by exactly the same code as the form itself.
 *
 * Write authorisation is not re-implemented here either: it is delegated to the
 * existing central resolver in {@see SiteLanguageDca}, so a user who may not
 * edit this root cannot reach the fields through a direct request.
 */
final class LanguageUrlDca
{
    public function __construct(
        private readonly LanguageUrlResolver $urls,
        private readonly LanguageUrlCollisionValidator $collisions,
        private readonly LanguageDomainNormalizer $domains,
        private readonly EntryPointNormalizer $entryPoints,
        private readonly SiteLanguageDca $scope,
    ) {
    }

    public function validateProtocol(mixed $value, DataContainer $dc): string
    {
        $recordId = (int) ($dc->id ?? 0);
        $this->scope->assertRecordWrite($recordId);

        $mode = ProtocolMode::fromValue($value);
        $this->assertRecordIsResolvable($recordId, ['urlProtocol' => $mode->value]);

        return $mode->value;
    }

    public function validateDomain(mixed $value, DataContainer $dc): string
    {
        $recordId = (int) ($dc->id ?? 0);
        $this->scope->assertRecordWrite($recordId);

        $domain = $this->domains->normalize($value) ?? '';
        $this->assertRecordIsResolvable($recordId, ['urlDomain' => $domain]);

        return $domain;
    }

    public function validateEntryPoint(mixed $value, DataContainer $dc): string
    {
        $recordId = (int) ($dc->id ?? 0);
        $this->scope->assertRecordWrite($recordId);

        $entryPoint = $this->entryPoints->normalize($value);
        $this->assertRecordIsResolvable($recordId, ['urlEntryPoint' => $entryPoint]);

        return $entryPoint;
    }

    public function validatePublished(mixed $value, DataContainer $dc): mixed
    {
        $recordId = (int) ($dc->id ?? 0);

        return $this->validatePublishedState($recordId, (bool) $value) ? $value : '';
    }

    /** Canonical publication validation shared by form and list toggle adapters. */
    public function validatePublishedState(int $recordId, bool $published): bool
    {
        if ($recordId <= 0) {
            throw new \InvalidArgumentException('The language record could not be resolved.');
        }

        $this->scope->assertRecordWrite($recordId);

        // Publishing must not weaken the collision rules, but an unpublish is
        // always safe and must stay possible even for a record that currently
        // conflicts.
        if ($published) {
            $this->assertRecordIsResolvable($recordId, ['published' => '1']);
        }

        return $published;
    }

    /**
     * Validates the persisted record with one submitted field replaced.
     *
     * @param array<string, string> $overrides
     *
     * @throws InvalidLanguageUrlException when the resulting mapping is ambiguous
     */
    private function assertRecordIsResolvable(int $recordId, array $overrides): void
    {
        $record = $this->record($recordId);

        if (null === $record) {
            // A record that does not exist yet (Contao creates the row before
            // the first save callback runs) simply has nothing to collide with.
            return;
        }

        $record = [...$record, ...$overrides];
        $rootId = (int) ($record['pid'] ?? 0);

        if ($rootId <= 0) {
            return;
        }

        // The persisted rows changed during this very request, so the memoised
        // mappings must not be reused for the comparison.
        $this->urls->reset();

        $this->collisions->validate(
            $rootId,
            $recordId,
            (string) ($record['language'] ?? ''),
            (string) ($record['urlProtocol'] ?? ''),
            (string) ($record['urlDomain'] ?? ''),
            (string) ($record['urlEntryPoint'] ?? ''),
            (bool) ($record['published'] ?? false),
        );

        $this->urls->reset();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function record(int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }

        try {
            $result = Database::getInstance()
                ->prepare('SELECT id, pid, language, urlProtocol, urlDomain, urlEntryPoint, published FROM tl_inline_language WHERE id=?')
                ->execute($recordId);

            return $result->numRows ? $result->row() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
