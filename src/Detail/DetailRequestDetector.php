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

namespace Vtinnovations\ContaoMultilingualPagetree\Detail;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\CalendarEventsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Model\FaqTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Model\NewsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

final class DetailRequestDetector
{
    public const REQUEST_ATTRIBUTE = '_contao_multilingual_pagetree_detail';

    public function __construct(
        private readonly LanguageHelper $languageHelper,
        private readonly TranslationOverlayResolver $overlayResolver,
    ) {
    }

    public function detect(Request $request, PageModel $readerPage): ?DetailContext
    {
        $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($cached instanceof DetailContext) {
            return $cached;
        }

        [$alias, $remainingParameters] = $this->extractAliasAndParameters($request);
        if ($alias === null) {
            return null;
        }

        $hint = $this->detectTypeHint($request);
        $types = $hint !== null ? [$hint] : [DetailContext::NEWS, DetailContext::EVENT, DetailContext::FAQ];
        $matches = [];
        foreach ($types as $type) {
            $context = $this->resolveContext($type, $alias, $remainingParameters, $readerPage);
            if ($context !== null) {
                $matches[] = $context;
            }
        }

        // Without a reader hint, an alias shared by multiple record types is deliberately ambiguous.
        if (count($matches) !== 1) {
            return null;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $matches[0]);

        return $matches[0];
    }

    private function extractAliasAndParameters(Request $request): array
    {
        $parameters = $request->attributes->get('parameters');
        if (is_string($parameters) && trim($parameters, '/') !== '') {
            $parts = explode('/', trim($parameters, '/'));

            return [rawurldecode((string) array_shift($parts)), $parts];
        }

        foreach (['auto_item', 'items', 'event', 'faq'] as $name) {
            $value = $request->attributes->get($name);
            if (!is_string($value) || $value === '') {
                $value = $request->query->get($name);
            }
            if (is_string($value) && $value !== '') {
                return [$value, []];
            }
        }

        foreach (['auto_item', 'items', 'event', 'faq'] as $name) {
            $value = \Contao\Input::get($name);
            if (is_string($value) && $value !== '') {
                return [$value, []];
            }
        }

        return [null, []];
    }

    private function detectTypeHint(Request $request): ?string
    {
        $parts = [
            $request->attributes->get('_route'),
            $request->attributes->get('_controller'),
            $request->attributes->get('readerType'),
            $request->attributes->get('moduleType'),
        ];
        $moduleModel = $request->attributes->get('moduleModel');
        if (is_object($moduleModel)) {
            $parts[] = $moduleModel->type ?? null;
        }
        $context = strtolower(implode(' ', array_filter($parts, 'is_string')));

        return match (true) {
            str_contains($context, 'newsreader'), str_contains($context, 'news_reader') => DetailContext::NEWS,
            str_contains($context, 'eventreader'), str_contains($context, 'event_reader'), str_contains($context, 'calendar_event') => DetailContext::EVENT,
            str_contains($context, 'faqreader'), str_contains($context, 'faq_reader') => DetailContext::FAQ,
            default => null,
        };
    }

    private function resolveContext(string $type, string $alias, array $remainingParameters, PageModel $readerPage): ?DetailContext
    {
        $activeLanguage = $this->languageHelper->getActiveLanguage();
        $defaultLanguage = $this->languageHelper->getDefaultLanguage(
            $readerPage->type === 'root' ? (int) $readerPage->id : (int) $readerPage->rootId,
        );

        $source = null;
        if (!$this->languagesEqual($activeLanguage, $defaultLanguage)) {
            $translation = $this->findTranslationByAlias($type, $alias, $activeLanguage);
            if ($translation !== null && $this->languageHelper->isPublished($translation)) {
                $candidate = $this->findSourceById($type, (int) $translation->pid);
                if ($candidate !== null) {
                    $resolvedAlias = $this->overlayResolver->resolveField(
                        $candidate,
                        $translation,
                        'alias',
                        $this->translationTable($type),
                    );
                    if ((string) $resolvedAlias === $alias) {
                        $source = $candidate;
                    }
                }
            }
        }

        if ($source === null) {
            $candidate = $this->findSourceByAlias($type, $alias);
            if ($candidate !== null && !$this->languagesEqual($activeLanguage, $defaultLanguage)) {
                $translation = $this->findTranslationBySource($type, (int) $candidate->id, $activeLanguage);
                if ($translation === null || !$this->languageHelper->isPublished($translation)) {
                    $candidate = null;
                } elseif ((string) $this->overlayResolver->resolveField(
                    $candidate,
                    $translation,
                    'alias',
                    $this->translationTable($type),
                ) !== $alias) {
                    $candidate = null;
                }
            }
            $source = $candidate;
        }
        if ($source === null || !$this->belongsToCurrentSite($type, $source, $readerPage)) {
            return null;
        }

        return new DetailContext($type, (int) $source->id, (string) $source->alias, $remainingParameters);
    }

    private function findTranslationByAlias(string $type, string $alias, string $language): ?object
    {
        return match ($type) {
            DetailContext::NEWS => NewsTranslationModel::findOneByAlias($alias, $language),
            DetailContext::EVENT => CalendarEventsTranslationModel::findOneByAlias($alias, $language),
            DetailContext::FAQ => FaqTranslationModel::findOneByAlias($alias, $language),
            default => null,
        };
    }

    private function findTranslationBySource(string $type, int $sourceId, string $language): ?object
    {
        return match ($type) {
            DetailContext::NEWS => NewsTranslationModel::findByPidAndLanguage($sourceId, $language),
            DetailContext::EVENT => CalendarEventsTranslationModel::findByPidAndLanguage($sourceId, $language),
            DetailContext::FAQ => FaqTranslationModel::findByPidAndLanguage($sourceId, $language),
            default => null,
        };
    }

    private function findSourceByAlias(string $type, string $alias): ?object
    {
        if (!$this->sourceModelExists($type)) {
            return null;
        }

        return match ($type) {
            DetailContext::NEWS => NewsModel::findOneBy(['alias=?'], [$alias]),
            DetailContext::EVENT => CalendarEventsModel::findOneBy(['alias=?'], [$alias]),
            DetailContext::FAQ => FaqModel::findOneBy(['alias=?'], [$alias]),
            default => null,
        };
    }

    public function findSourceById(string $type, int $id): ?object
    {
        if (!$this->sourceModelExists($type)) {
            return null;
        }

        return match ($type) {
            DetailContext::NEWS => NewsModel::findByPk($id),
            DetailContext::EVENT => CalendarEventsModel::findByPk($id),
            DetailContext::FAQ => FaqModel::findByPk($id),
            default => null,
        };
    }

    private function belongsToCurrentSite(string $type, object $source, PageModel $readerPage): bool
    {
        if (!$this->containerModelExists($type)) {
            return false;
        }

        $container = match ($type) {
            DetailContext::NEWS => NewsArchiveModel::findByPk((int) $source->pid),
            DetailContext::EVENT => CalendarModel::findByPk((int) $source->pid),
            DetailContext::FAQ => FaqCategoryModel::findByPk((int) $source->pid),
            default => null,
        };
        $jumpTo = (int) ($container?->jumpTo ?? 0);
        if ($jumpTo <= 0) {
            // Without a container reader page there is no safe root-site boundary.
            return false;
        }

        $jumpPage = PageModel::findByPk($jumpTo);
        if ($jumpPage === null) {
            return false;
        }
        $jumpPage->loadDetails();
        $readerPage->loadDetails();

        $jumpRoot = $jumpPage->type === 'root' ? (int) $jumpPage->id : (int) $jumpPage->rootId;
        $readerRoot = $readerPage->type === 'root' ? (int) $readerPage->id : (int) $readerPage->rootId;

        return $jumpRoot > 0 && $jumpRoot === $readerRoot;
    }

    private function translationTable(string $type): string
    {
        return match ($type) {
            DetailContext::NEWS => 'tl_news_translation',
            DetailContext::EVENT => 'tl_calendar_events_translation',
            DetailContext::FAQ => 'tl_faq_translation',
        };
    }

    private function languagesEqual(string $left, string $right): bool
    {
        return str_replace('-', '_', strtolower($left)) === str_replace('-', '_', strtolower($right));
    }

    private function sourceModelExists(string $type): bool
    {
        return match ($type) {
            DetailContext::NEWS => class_exists(NewsModel::class),
            DetailContext::EVENT => class_exists(CalendarEventsModel::class),
            DetailContext::FAQ => class_exists(FaqModel::class),
            default => false,
        };
    }

    private function containerModelExists(string $type): bool
    {
        return match ($type) {
            DetailContext::NEWS => class_exists(NewsArchiveModel::class),
            DetailContext::EVENT => class_exists(CalendarModel::class),
            DetailContext::FAQ => class_exists(FaqCategoryModel::class),
            default => false,
        };
    }
}
