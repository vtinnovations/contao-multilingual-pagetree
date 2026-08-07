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

namespace Vtinnovations\ContaoMultilingualPagetree\Translation;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Looks translation records up through Contao's registered models.
 *
 * The model class is resolved from $GLOBALS['TL_MODELS'] via
 * Model::getClassFromTable(), so no translation table is hardcoded here.
 * Results are memoised for the current request and released again afterwards,
 * which keeps long running workers free of stale translation state.
 */
final class ModelTranslationRecordLocator implements TranslationRecordLocatorInterface, ResetInterface
{
    /** @var array<string, object|null> */
    private array $cache = [];

    /** @var array<string, true> */
    private array $prewarmed = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TranslationFieldRegistry $fields,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function find(string $translationTable, int $sourceId, string $language, ?int $parentId = null): ?object
    {
        if ('' === $translationTable || $sourceId <= 0 || '' === $language) {
            return null;
        }

        $cacheKey = $this->cacheKey($translationTable, $sourceId, $language);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        if (null !== $parentId && $parentId > 0) {
            $this->prewarmSiblings($translationTable, $parentId, $language);

            if (array_key_exists($cacheKey, $this->cache)) {
                return $this->cache[$cacheKey];
            }
        }

        $this->cache[$cacheKey] = $this->fetchOne($translationTable, $sourceId, $language);

        return $this->cache[$cacheKey];
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->prewarmed = [];
    }

    private function fetchOne(string $translationTable, int $sourceId, string $language): ?object
    {
        $adapter = $this->modelAdapter($translationTable);

        if (null === $adapter) {
            return null;
        }

        try {
            $record = $adapter->findOneBy(['pid=?', 'language=?'], [$sourceId, $language]);
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not read %s for record %d (%s): %s', $translationTable, $sourceId, $language, $exception->getMessage()),
            );

            return null;
        }

        return is_object($record) ? $record : null;
    }

    /**
     * Loads the translations of all records sharing the same parent so that a
     * page with many content elements does not trigger one query per element.
     */
    private function prewarmSiblings(string $translationTable, int $parentId, string $language): void
    {
        $prewarmKey = $translationTable.'|'.$parentId.'|'.$language;

        if (isset($this->prewarmed[$prewarmKey])) {
            return;
        }

        $this->prewarmed[$prewarmKey] = true;

        $sourceTable = $this->fields->sourceTable($translationTable);

        if (null === $sourceTable || 1 !== preg_match('/^[a-z0-9_]+$/', $sourceTable)) {
            return;
        }

        $adapter = $this->modelAdapter($translationTable);

        if (null === $adapter) {
            return;
        }

        try {
            $records = $adapter->findBy(
                ['language=?', 'pid IN (SELECT id FROM '.$sourceTable.' WHERE pid=?)'],
                [$language, $parentId],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: could not pre-load %s for parent %d (%s): %s', $translationTable, $parentId, $language, $exception->getMessage()),
            );

            return;
        }

        if (null === $records) {
            return;
        }

        foreach ($records as $record) {
            $this->cache[$this->cacheKey($translationTable, (int) $record->pid, $language)] = $record;
        }
    }

    private function modelAdapter(string $translationTable): ?object
    {
        try {
            $this->framework->initialize();

            $class = $this->framework->getAdapter(Model::class)->getClassFromTable($translationTable);

            if (!is_string($class) || !class_exists($class)) {
                return null;
            }

            return $this->framework->getAdapter($class);
        } catch (\Throwable $exception) {
            $this->logger?->error(
                sprintf('Contao Multilingual Pagetree: no model available for %s: %s', $translationTable, $exception->getMessage()),
            );

            return null;
        }
    }

    private function cacheKey(string $translationTable, int $sourceId, string $language): string
    {
        return $translationTable.'|'.$sourceId.'|'.$language;
    }
}
