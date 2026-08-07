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

namespace Vtinnovations\ContaoMultilingualPagetree\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\Module;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Helper\LanguageHelper;
use Vtinnovations\ContaoMultilingualPagetree\Model\CalendarEventsTranslationModel;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationOverlayResolver;

#[AsHook('getAllEvents')]
#[AsHook('parseTemplate', method: 'onParseTemplate')]
class CalendarEventsTranslationListener
{
    private LanguageHelper $languageHelper;
    private ?ResponseContextAccessor $responseContextAccessor;

    public function __construct(
        LanguageHelper $languageHelper,
        ?ResponseContextAccessor $responseContextAccessor = null,
        ?TranslationOverlayResolver $overlayResolver = null,
    ) {
        $this->languageHelper = $languageHelper;
        $this->responseContextAccessor = $responseContextAccessor ?? System::getContainer()->get('contao.routing.response_context_accessor', System::NULL_ON_INVALID_REFERENCE);
        $this->overlayResolver = $overlayResolver ?? System::getContainer()->get(TranslationOverlayResolver::class);
    }

    private TranslationOverlayResolver $overlayResolver;

    /**
     * Intercept event items collected for calendars and event lists.
     */
    public function __invoke(array $allEvents, array $calendars, int $start, int $end, Module $module): array
    {
        if (!$this->languageHelper->isFrontendRequest() || $this->languageHelper->isDefaultLanguage()) {
            return $allEvents;
        }

        $activeLanguage = $this->languageHelper->getActiveLanguage();

        foreach ($allEvents as $timeIndex => &$days) {
            if (!is_array($days)) {
                continue;
            }
            foreach ($days as $dayIndex => &$events) {
                if (!is_array($events)) {
                    continue;
                }
                foreach ($events as $eIndex => &$event) {
                    $eventId = is_array($event) ? ($event['id'] ?? 0) : ($event->id ?? 0);
                    if (!$eventId) {
                        continue;
                    }

                    $translation = CalendarEventsTranslationModel::findByPidAndLanguage((int) $eventId, $activeLanguage);
                    if ($translation === null) {
                        continue;
                    }

                    if (!$this->languageHelper->isPublished($translation)) {
                        unset($events[$eIndex]);
                        continue;
                    }

                    $sourceEvent = $event;
                    foreach (['title', 'teaser', 'details', 'location'] as $field) {
                        $value = $this->overlayResolver->resolveField($sourceEvent, $translation, $field, 'tl_calendar_events_translation');
                        if (is_array($event)) {
                            $event[$field] = $value;
                        } else {
                            $event->$field = $value;
                        }
                    }
                    $alias = $this->overlayResolver->resolveField($sourceEvent, $translation, 'alias', 'tl_calendar_events_translation');

                    if (is_array($event)) {
                        if ($alias !== ($sourceEvent['alias'] ?? null)) {
                            if (isset($event['href']) && !empty($event['alias'])) {
                                $event['href'] = preg_replace(
                                    '/(\/|items=)' . preg_quote($event['alias'], '/') . '([?&"\'<]|$)/',
                                    '$1'.$alias.'$2',
                                    $event['href']
                                );
                            }
                            $event['alias'] = $alias;
                        }
                    } else {
                        if ($alias !== ($sourceEvent->alias ?? null)) {
                            if (isset($event->href) && !empty($event->alias)) {
                                $event->href = preg_replace(
                                    '/(\/|items=)' . preg_quote($event->alias, '/') . '([?&"\'<]|$)/',
                                    '$1'.$alias.'$2',
                                    $event->href
                                );
                            }
                            $event->alias = $alias;
                        }
                    }
                }
            }
        }

        return $allEvents;
    }

    /**
     * Intercept template rendering for event detail readers.
     */
    public function onParseTemplate(\Contao\Template $template): void
    {
        if (!$this->languageHelper->isFrontendRequest() || $this->languageHelper->isDefaultLanguage()) {
            return;
        }

        if (!str_starts_with($template->getName(), 'event_')) {
            return;
        }

        $rawId = (string) ($template->id ?? '');
        if (preg_match('/(?:^|_)(\d+)$/', $rawId, $matches)) {
            $eventId = (int) $matches[1];
        } else {
            $eventId = (int) $rawId;
        }

        if (!$eventId) {
            return;
        }

        $activeLanguage = $this->languageHelper->getActiveLanguage();
        $translation = CalendarEventsTranslationModel::findByPidAndLanguage($eventId, $activeLanguage);

        if ($translation !== null) {
            if (!$this->languageHelper->isPublished($translation)) {
                $template->title = '';
                $template->teaser = '';
                $template->details = '';
                return;
            }

            $originalTitle = $template->title ?? '';
            $originalTitleRaw = htmlspecialchars_decode((string)$originalTitle, ENT_QUOTES);
            $title = $this->overlayResolver->resolveField($template, $translation, 'title', 'tl_calendar_events_translation');
            $teaser = $this->overlayResolver->resolveField($template, $translation, 'teaser', 'tl_calendar_events_translation');
            $details = $this->overlayResolver->resolveField($template, $translation, 'details', 'tl_calendar_events_translation');
            $location = $this->overlayResolver->resolveField($template, $translation, 'location', 'tl_calendar_events_translation');
            $alias = $this->overlayResolver->resolveField($template, $translation, 'alias', 'tl_calendar_events_translation');
            $pageTitle = $this->overlayResolver->resolveField($template, $translation, 'pageTitle', 'tl_calendar_events_translation');
            $description = $this->overlayResolver->resolveField($template, $translation, 'description', 'tl_calendar_events_translation');

                $template->title = $title;
                $template->eventTitle = $title;
                $template->pageTitle = $pageTitle;

                // Event templates typically use 'link' for the anchor text
                if (isset($template->link)) {
                    $template->link = $title;
                }

                // Aggressively replace the original title in ALL string properties
                if ($originalTitle !== '' && $originalTitle !== $title) {
                    $arrData = $template->getData();
                    foreach ($arrData as $k => $v) {
                        if (is_string($v)) {
                            // Replace HTML encoded version (e.g. V&amp;T)
                            if (strpos($v, $originalTitle) !== false) {
                                $template->$k = str_replace($originalTitle, (string) $title, $template->$k);
                            }
                            // Replace raw version (e.g. V&T)
                            if ($originalTitleRaw !== $originalTitle && strpos($v, $originalTitleRaw) !== false) {
                                $template->$k = str_replace($originalTitleRaw, (string) $title, $template->$k);
                            }
                        }
                    }
                }

                if (isset($template->linkHeadline)) {
                    $template->linkHeadline = preg_replace(
                        '/(<a[^>]*>)(.*?)(<\/a>)/is',
                        '$1'.$title.'$3',
                        $template->linkHeadline
                    );
                    $template->linkHeadline = preg_replace(
                        '/(title=")(.*?)(")/i',
                        '$1'.\Contao\StringUtil::specialchars((string) $title).'$3',
                        $template->linkHeadline
                    );
                }
            $template->teaser = $teaser;
            $template->details = $details;
            $template->location = $location;

            // Replace Alias in Links (e.g., link)
            if ($alias !== ($template->alias ?? null)) {
                $oldAlias = $template->alias ?? '';
                if ($oldAlias && isset($template->link)) {
                    $template->link = preg_replace(
                        '/(\/|items=)' . preg_quote($oldAlias, '/') . '([?&"\'<]|$)/',
                        '$1'.$alias.'$2',
                        $template->link
                    );
                }
                if ($oldAlias && isset($template->href)) {
                    $template->href = preg_replace(
                        '/(\/|items=)' . preg_quote($oldAlias, '/') . '([?&"\'<]|$)/',
                        '$1'.$alias.'$2',
                        $template->href
                    );
                }
                $template->alias = $alias;
            }

            if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof \Contao\PageModel) {
                $GLOBALS['objPage']->pageTitle = $pageTitle;
            }

            if ($this->responseContextAccessor) {
                $responseContext = $this->responseContextAccessor->getResponseContext();
                if ($responseContext && $responseContext->has(HtmlHeadBag::class)) {
                    $headBag = $responseContext->get(HtmlHeadBag::class);
                    $headBag->setTitle((string) $pageTitle);
                    $headBag->setMetaDescription((string) $description);
                }
            }
        }
    }
}
