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

/** Default-deny, entity-specific translation field policy registry. */
final class TranslationFieldRegistry
{
    private const STRUCTURAL = [
        'id', 'pid', 'ptable', 'sorting', 'tstamp', 'type', 'CType', 'colPos',
        'parent', 'module', 'form', 'customTpl', 'galleryTpl', 'playerTpl',
        'sliderTpl', 'youtubeTpl', 'vimeoTpl', 'cssID', 'space', 'author',
        'jumpTo', 'source', 'singleSRC', 'multiSRC', 'orderSRC', 'perRow',
        'size', 'imagemargin', 'imageUrl', 'fullsize', 'overwriteMeta', 'meta',
        'inColumn', 'recurring', 'repeatEach', 'repeatEnd', 'startTime',
        'endTime', 'startDate', 'endDate', 'addTime', 'calendar', 'category',
        'protected', 'groups', 'guests', 'layout', 'subpageLayout', 'access',
    ];

    private const TECHNICAL = ['id', 'pid', 'tstamp', 'language', 'fieldStates', 'language_tabs', 'type'];
    private const INDEPENDENT = ['published', 'start', 'stop', 'invisible'];

    /** @var array<string, TranslationFieldPolicy>|null */
    private ?array $policies = null;

    public function __construct(private readonly iterable $contributors = [])
    {
    }

    public function getPolicy(string $entityOrTable): TranslationFieldPolicy
    {
        $this->initialize();

        foreach ($this->policies ?? [] as $policy) {
            if ($entityOrTable === $policy->entityType || $entityOrTable === $policy->sourceTable || $entityOrTable === $policy->translationTable) {
                return $policy;
            }
        }

        return TranslationFieldPolicy::denied($entityOrTable);
    }

    public function fields(string $translationTable, ?string $contentType = null): array
    {
        return $this->getPolicy($translationTable)->fields($contentType);
    }

    public function fieldNames(string $translationTable, ?array $availableFields = null, ?string $contentType = null): array
    {
        $fields = array_keys($this->fields($translationTable, $contentType));

        return $availableFields === null ? $fields : array_values(array_intersect($fields, $availableFields));
    }

    public function type(string $translationTable, string $field, ?string $contentType = null): ?string
    {
        return $this->fields($translationTable, $contentType)[$field] ?? null;
    }

    public function isTranslatable(string $translationTable, string $field, ?string $contentType = null): bool
    {
        return array_key_exists($field, $this->fields($translationTable, $contentType));
    }

    public function tables(): array
    {
        $this->initialize();

        return array_keys($this->policies ?? []);
    }

    public function sourceTable(string $translationTable): ?string
    {
        $source = $this->getPolicy($translationTable)->sourceTable;

        return $source !== '' ? $source : null;
    }

    /** @return list<string> */
    public function validate(array $sourceDcaFields): array
    {
        $errors = [];
        foreach ($this->policies() as $policy) {
            foreach ($policy->fields() as $field => $_type) {
                if (!isset($sourceDcaFields[$policy->sourceTable][$field])) {
                    $errors[] = sprintf('%s: configured field "%s" is missing.', $policy->sourceTable, $field);
                }
            }
        }

        return $errors;
    }

    /** @return array<string, TranslationFieldPolicy> */
    public function policies(): array
    {
        $this->initialize();

        return $this->policies ?? [];
    }

    private function initialize(): void
    {
        if ($this->policies !== null) {
            return;
        }

        $base = [
            'tl_page_translation' => $this->policy('page', 'tl_page', ['title' => 'string', 'pageTitle' => 'string', 'description' => 'string', 'alias' => 'string'], ['alias']),
            'tl_article_translation' => $this->policy('article', 'tl_article', ['title' => 'string']),
            'tl_content_translation' => $this->policy('content', 'tl_content', ['headline' => 'headline'], [], [
                'text' => ['text' => 'string', 'alt' => 'string', 'imageTitle' => 'string', 'caption' => 'string'],
                'accordionSingle' => ['text' => 'string'],
                'headline' => [], 'html' => ['html' => 'string'], 'code' => ['code' => 'serialized_array'],
                'list' => ['listitems' => 'serialized_array'], 'table' => ['tableitems' => 'serialized_array', 'summary' => 'string'],
                'hyperlink' => ['linkTitle' => 'string'], 'image' => ['alt' => 'string', 'imageTitle' => 'string', 'caption' => 'string'],
                'gallery' => ['caption' => 'string'], 'player' => ['playerCaption' => 'string'],
                'download' => ['linkTitle' => 'string'], 'downloads' => ['linkTitle' => 'string'],
            ], ['invisible', 'start', 'stop']),
            'tl_news_translation' => $this->policy('news', 'tl_news', ['headline' => 'string', 'subheadline' => 'string', 'teaser' => 'string', 'text' => 'string', 'alias' => 'string', 'pageTitle' => 'string', 'description' => 'string'], ['alias']),
            'tl_calendar_events_translation' => $this->policy('event', 'tl_calendar_events', ['title' => 'string', 'teaser' => 'string', 'details' => 'string', 'location' => 'string', 'alias' => 'string', 'pageTitle' => 'string', 'description' => 'string'], ['alias']),
            'tl_faq_translation' => $this->policy('faq', 'tl_faq', ['question' => 'string', 'answer' => 'string', 'alias' => 'string'], ['alias']),
        ];

        $contributors = array_values(array_filter(
            is_array($this->contributors) ? $this->contributors : iterator_to_array($this->contributors),
            'is_object',
        ));
        usort($contributors, static fn (object $a, object $b): int => $a::class <=> $b::class);
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof TranslationFieldPolicyContributorInterface) {
                continue;
            }
            try {
                foreach ($contributor->registrations() as $registration) {
                    if (!$registration instanceof TranslationFieldRegistration) {
                        continue;
                    }
                    $this->register($base, $registration);
                }
            } catch (\Throwable) {
                // One malformed extension must not make the core policies unavailable.
            }
        }

        $this->policies = $base;
    }

    private function policy(string $entity, string $source, array $fields, array $aliases = [], array $contentTypes = [], array $independent = ['published', 'start', 'stop']): TranslationFieldPolicy
    {
        return new TranslationFieldPolicy($entity, $source, $source.'_translation', $fields, self::STRUCTURAL, $independent, self::TECHNICAL, ['pid', 'language', 'tstamp'], $aliases, $contentTypes);
    }

    /** @param array<string, TranslationFieldPolicy> $policies */
    private function register(array &$policies, TranslationFieldRegistration $registration): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $registration->field)
            || in_array($registration->field, self::STRUCTURAL, true)
            || in_array($registration->field, self::INDEPENDENT, true)
            || !in_array($registration->valueType, ['string', 'headline', 'serialized_array', 'boolean', 'integer', 'nullable'], true)) {
            return;
        }
        $table = $registration->sourceTable.'_translation';
        $policy = $policies[$table] ?? null;
        if ($policy === null || ($registration->sourceTable === 'tl_content' && ($registration->contentType === null || !preg_match('/^[A-Za-z0-9_-]+$/', $registration->contentType)))) {
            return;
        }

        $fields = $policy->translatableFields;
        $types = $policy->contentTypeFields;
        if ($registration->sourceTable === 'tl_content') {
            $types[$registration->contentType] ??= [];
            $types[$registration->contentType][$registration->field] ??= $registration->valueType;
        } else {
            $fields[$registration->field] ??= $registration->valueType;
        }
        $policies[$table] = new TranslationFieldPolicy($policy->entityType, $policy->sourceTable, $policy->translationTable, $fields, $policy->structuralFields, $policy->independentFields, $policy->technicalFields, $policy->requiredFields, $policy->aliasFields, $types);
    }
}
