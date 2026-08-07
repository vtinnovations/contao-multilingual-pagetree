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

use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;
use Vtinnovations\ContaoMultilingualPagetree\Security\Capability;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;

/**
 * Protects the content mode change.
 *
 * A change is only accepted when the backend user may edit the language
 * configuration, the request carries a valid Contao token and - if stored
 * content would become inactive - the editor explicitly confirmed it. Nothing is
 * ever deleted; a rejected change simply keeps the stored value.
 */
final class ModeSwitchGuard
{
    public const CONFIRM_FIELD = 'contentTranslationModeConfirm';

    public function __construct(
        private readonly ModeSwitchAnalyzer $analyzer,
        private readonly LanguageRecordLocator $languages,
        private readonly BackendActionGuard $actionGuard,
        private readonly CapabilityPolicy $capabilities,
    ) {
    }

    /**
     * @param int         $languageRecordId tl_inline_language record id
     * @param string|null $currentValue     Persisted mode before the change
     *
     * @throws ModeSwitchDeniedException
     */
    public function confirmSwitch(int $languageRecordId, ContentTranslationMode $requested, ?string $currentValue): ContentTranslationMode
    {
        $current = ContentTranslationMode::fromValue($currentValue);

        if ($current === $requested) {
            return $requested;
        }

        // A configuration change is a state-changing action: it must arrive by
        // POST, carry a valid token and come from a user who may edit the
        // language configuration.
        $denied = $this->actionGuard->denyReason('tl_inline_language');

        if (BackendActionGuard::DENIED_TOKEN === $denied || BackendActionGuard::DENIED_METHOD === $denied) {
            throw ModeSwitchDeniedException::invalidToken($this->label('contaoMultilingualPagetreeContentModeInvalidToken'));
        }

        if (null !== $denied) {
            throw ModeSwitchDeniedException::denied($this->label('contaoMultilingualPagetreeContentModeDenied'));
        }

        // Free content trees are a licensed capability. Switching away from them
        // stays possible without a licence, so an installation can always return
        // to the connected mode without losing access to its configuration.
        if ($requested->isFree() && !$this->capabilities->allows(Capability::FreeContentMode)) {
            throw ModeSwitchDeniedException::denied($this->label('contaoMultilingualPagetreeContentModeDenied'));
        }

        $record = $this->languages->find($languageRecordId);

        if (null === $record) {
            throw ModeSwitchDeniedException::denied($this->label('contaoMultilingualPagetreeContentModeDenied'));
        }

        $summary = $this->analyzer->analyse(
            (int) ($record['pid'] ?? 0),
            (string) ($record['language'] ?? ''),
            $current,
            $requested,
        );

        if ($summary->requiresConfirmation() && !$this->isConfirmed()) {
            throw ModeSwitchDeniedException::unconfirmed($this->confirmationMessage($summary));
        }

        return $requested;
    }

    /**
     * The message states which mode becomes active, how much stored content
     * exists and that nothing is deleted.
     */
    public function confirmationMessage(ModeSwitchSummary $summary): string
    {
        $template = $this->label('contaoMultilingualPagetreeContentModeConfirm');

        if ('' === $template) {
            $template = 'Activating "%s" for "%s" keeps %d connected translation records and %d free records stored, '
                .'but %d of them will no longer render. No data is deleted. Confirm the change to continue.';
        }

        return sprintf(
            $template,
            StringUtil::specialchars($summary->requested->value),
            StringUtil::specialchars($summary->language),
            $summary->connectedRecords(),
            $summary->freeRecords(),
            $summary->recordsBecomingInactive(),
        );
    }

    private function isConfirmed(): bool
    {
        try {
            $value = Input::post(self::CONFIRM_FIELD);
        } catch (\Throwable) {
            return false;
        }

        return in_array($value, ['1', 1, true, 'true'], true);
    }



    private function label(string $key): string
    {
        try {
            System::loadLanguageFile('default');
        } catch (\Throwable) {
            // Fall back to the neutral default text.
        }

        $label = $GLOBALS['TL_LANG']['MSC'][$key] ?? null;

        return is_string($label) ? $label : '';
    }
}
