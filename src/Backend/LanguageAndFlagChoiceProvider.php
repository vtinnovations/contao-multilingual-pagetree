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

use Contao\CoreBundle\Intl\Countries;
use Contao\CoreBundle\Intl\Locales;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Symfony\Component\Intl\Languages;
use Vtinnovations\ContaoMultilingualPagetree\Language\LanguageFlagMapper;

/** Canonical backend choices and language-to-flag defaults. */
final class LanguageAndFlagChoiceProvider
{
    public function __construct(
        private readonly Locales $locales,
        private readonly Countries $countries,
        private readonly ?LanguageFlagMapper $flagMapper = null,
    ) {
    }

    /** @return array<string, string> canonical code => translated label */
    public function languageOptions(?DataContainer $dc = null): array
    {
        $names = $this->languageNames();
        $existing = $this->recordValue($dc, 'language');
        $existingCanonical = self::normalizeLanguage($existing);

        if ('' !== $existing && !isset($names[$existing])) {
            $names[$existing] = $names[$existingCanonical] ?? strtoupper($existing);
        }

        $options = [];
        foreach ($names as $code => $name) {
            $options[$code] = sprintf('%s (%s)', $name, $code);
        }
        uasort($options, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $options;
    }

    /** @return array<string, string> lowercase country code => visual label */
    public function flagOptions(?DataContainer $dc = null): array
    {
        try {
            $countries = $this->countries->getCountries();
        } catch (\Throwable) {
            $countries = [];
        }

        $existing = $this->recordValue($dc, 'flag');
        $codes = LanguageFlagMapper::AVAILABLE_FLAGS;
        if (1 === preg_match('/^[a-z]{2}$/', $existing) && !in_array($existing, $codes, true)) {
            $codes[] = $existing;
        }

        $options = [];
        foreach (array_values(array_unique($codes)) as $code) {
            $country = $countries[strtoupper($code)] ?? strtoupper($code);
            $options[$code] = sprintf('%s %s (%s)', self::flagEmoji($code), $country, $code);
        }
        uasort($options, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $options;
    }

    public function isKnownLanguage(string $language, ?string $existing = null): bool
    {
        $language = self::normalizeLanguage($language);

        return isset($this->languageNames()[$language])
            || (null !== $existing && $language === self::normalizeLanguage($existing));
    }

    public function defaultFlag(string $language): string
    {
        return $this->mapper()->defaultFlag($language);
    }

    public function validateFlag(mixed $value, DataContainer $dc): string
    {
        $submittedLanguage = Input::post('language');

        return $this->normalizeFlag(
            $value,
            is_string($submittedLanguage) ? $submittedLanguage : '',
            $this->recordValue($dc, 'flag'),
        );
    }

    public function normalizeFlag(mixed $value, string $language = '', ?string $existing = null): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->invalidFlagMessage());
        }

        $flag = strtolower(trim($value));
        if ('' === $flag) {
            return $this->defaultFlag($language);
        }

        $existing = is_string($existing) ? strtolower(trim($existing)) : '';
        if (1 !== preg_match('/^[a-z]{2}$/', $flag)
            || (!$this->mapper()->isAvailable($flag) && $flag !== $existing)) {
            throw new \InvalidArgumentException($this->invalidFlagMessage());
        }

        return $flag;
    }

    public function fillLanguageLabel(mixed $value, ?DataContainer $dc = null): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid language label.');
        }

        $label = trim($value);
        if ('' !== $label) {
            return $label;
        }

        $submittedLanguage = Input::post('language');
        return $this->fillLanguageLabelFor($label, is_string($submittedLanguage) ? $submittedLanguage : '');
    }

    public function fillLanguageLabelFor(string $label, string $language): string
    {
        $label = trim($label);
        if ('' !== $label) {
            return $label;
        }

        $language = self::normalizeLanguage($language);

        return $this->languageNames()[$language] ?? strtoupper($language);
    }

    public function renderAutomationConfig(): string
    {
        $labels = $this->languageNames();
        $defaults = [];
        foreach (array_keys($labels) as $language) {
            $defaults[$language] = $this->defaultFlag($language);
        }

        return sprintf(
            '<span id="cmp-language-flag-config" hidden data-default-flags="%s" data-language-labels="%s"></span>',
            StringUtil::specialchars(json_encode($defaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            StringUtil::specialchars(json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }

    public static function normalizeLanguage(string $language): string
    {
        return str_replace('_', '-', strtolower(trim($language)));
    }

    public static function flagEmoji(string $country): string
    {
        $country = strtoupper($country);
        if (1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            return '🏳';
        }

        return mb_chr(127397 + ord($country[0])).mb_chr(127397 + ord($country[1]));
    }

    /** @return array<string, string> */
    private function languageNames(): array
    {
        try {
            $available = $this->locales->getLocales();
            if ([] === $available) {
                $available = Languages::getNames();
            }
        } catch (\Throwable) {
            try {
                $available = Languages::getNames();
            } catch (\Throwable) {
                $available = ['de' => 'German', 'en' => 'English'];
            }
        }

        $names = [];
        foreach ($available as $code => $name) {
            if (!is_string($code) || !is_string($name)) {
                continue;
            }
            $canonical = self::normalizeLanguage($code);
            if (1 === preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $canonical) && '' !== trim($name)) {
                $names[$canonical] = trim($name);
            }
        }

        return $names;
    }

    private function recordValue(?DataContainer $dc, string $field): string
    {
        $value = $dc?->activeRecord?->{$field} ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function invalidFlagMessage(): string
    {
        return (string) ($GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeBackend']['invalidFlag'] ?? 'Select a valid flag.');
    }

    private function mapper(): LanguageFlagMapper
    {
        return $this->flagMapper ?? new LanguageFlagMapper();
    }
}
