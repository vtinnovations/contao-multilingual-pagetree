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

namespace Vtinnovations\ContaoMultilingualPagetree\Url;

/**
 * Server-side validation of a language URL mapping before it is stored.
 *
 * The rule the whole architecture rests on: one effective hostname plus one
 * effective entry point belongs to exactly one language of exactly one website
 * root. Anything that would make an incoming request ambiguous is rejected -
 * never resolved by picking a winner.
 */
final class LanguageUrlCollisionValidator
{
    public function __construct(private readonly LanguageUrlResolver $resolver)
    {
    }

    /**
     * @throws InvalidLanguageUrlException when the mapping cannot be resolved deterministically
     */
    public function validate(
        int $rootId,
        int $languageId,
        string $languageCode,
        mixed $protocol,
        mixed $domain,
        mixed $entryPoint,
        bool $published,
    ): LanguageUrlMapping {
        if ($rootId <= 0) {
            throw new InvalidLanguageUrlException('unknownRoot', LanguageUrlMessages::text('unknownRoot'));
        }

        // Normalisation happens in the two dedicated normalisers only; an
        // invalid value never reaches the comparison below.
        $candidate = $this->resolver->projectMapping(
            $rootId,
            $languageId,
            $languageCode,
            $protocol,
            $domain,
            $entryPoint,
            $published,
        );

        $this->assertNoCrossRootConflict($candidate);
        $this->assertNoSiblingConflict($candidate);

        return $candidate;
    }

    /**
     * A language hostname may join an existing root, but never one that another
     * root already owns: two roots claiming the same exact hostname make root
     * resolution non-deterministic.
     */
    private function assertNoCrossRootConflict(LanguageUrlMapping $candidate): void
    {
        if (null === $candidate->configuredDomain) {
            return;
        }

        foreach ($this->resolver->rootsClaimingHost($candidate->configuredDomain) as $claimingRootId) {
            if ($claimingRootId !== $candidate->rootId) {
                throw new InvalidLanguageUrlException('crossRootConflict', LanguageUrlMessages::text('crossRootConflict'));
            }
        }
    }

    private function assertNoSiblingConflict(LanguageUrlMapping $candidate): void
    {
        foreach ($this->resolver->mappings($candidate->rootId)->all() as $existing) {
            if ($this->isSameRecord($existing, $candidate)) {
                continue;
            }

            // An unpublished language occupies no URL, so it can never collide.
            if (!$existing->isPublished || !$candidate->isPublished) {
                continue;
            }

            if ($existing->targetKey() !== $candidate->targetKey()) {
                continue;
            }

            if ($existing->effectiveProtocol !== $candidate->effectiveProtocol) {
                throw new InvalidLanguageUrlException('protocolAmbiguity', LanguageUrlMessages::text('protocolAmbiguity'));
            }

            if (EntryPointNormalizer::ROOT === $candidate->effectiveEntryPoint) {
                throw new InvalidLanguageUrlException('duplicateRootMapping', LanguageUrlMessages::text('duplicateRootMapping'));
            }

            throw new InvalidLanguageUrlException('duplicateMapping', LanguageUrlMessages::text('duplicateMapping'));
        }
    }

    private function isSameRecord(LanguageUrlMapping $existing, LanguageUrlMapping $candidate): bool
    {
        if ($existing->languageId > 0 && $existing->languageId === $candidate->languageId) {
            return true;
        }

        // The website root's own implicit mapping carries id 0; it is matched
        // by language instead.
        return 0 === $existing->languageId
            && 0 === $candidate->languageId
            && LanguageUrlMappingSet::normalizeLanguage($existing->languageCode) === LanguageUrlMappingSet::normalizeLanguage($candidate->languageCode);
    }
}
