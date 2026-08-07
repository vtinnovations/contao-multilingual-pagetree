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

use Contao\BackendTemplate;
use Contao\DataContainer;
use Contao\System;
use Symfony\Component\Routing\RouterInterface;
use Vtinnovations\ContaoMultilingualPagetree\Storage\LegacyStateAdoption;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Security\RootPagePermission;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;
use Vtinnovations\ContaoMultilingualPagetree\Security\BackendActionGuard;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;

/** Compact root-only licence panel embedded in Page Properties. */
final class RootLicenseDca
{
    public function __construct(
        private readonly RootDomainRegistry $domains,
        private readonly RootScope $context,
        private readonly RootPagePermission $permission,
        private readonly CapabilityPolicy $policy,
        private readonly PackageStoreInterface $store,
        private readonly LegacyStateAdoption $adopter,
        private readonly BackendActionGuard $guard,
        private readonly RouterInterface $router,
        private readonly RootPageContext $pages,
        private readonly ?ModuleEntryNotice $entryNotice = null,
    ) {
    }

    /**
     * `input_field_callback` of the licence field.
     *
     * The record is resolved through {@see RootPageContext} rather than through
     * `DataContainer::$activeRecord`, so the panel also renders while the record
     * is being created and on Contao versions that deprecate that property.
     *
     * The panel stays visible when nothing is activated, when the root page has
     * no domain yet and when verification fails - those are exactly the states
     * an administrator needs to see. Only a user without permission gets an
     * empty string.
     */
    public function render(DataContainer $dc): string
    {
        $rootId = $this->pages->currentId($dc);
        $creating = $this->pages->isCreating($dc);

        // Contao selects a root palette only for genuine website roots. The
        // check is repeated here because this callback is reachable directly.
        if (!$creating && $rootId > 0 && !$this->pages->isRootPage($rootId)) {
            return '';
        }

        System::loadLanguageFile('default');

        // A root page that is not saved yet cannot be checked against page
        // mounts, so the general permission decides. Every action behind the
        // panel is authorised again with the real id server side.
        if (!$this->permission->canView($creating ? 0 : $rootId)
            || !$this->permission->canEditField('tl_page', PageRootPalette::LICENCE_FIELD)) {
            return '';
        }

        $GLOBALS['TL_CSS']['contao_multilingual_pagetree_root_licence'] = 'bundles/vtinnovationscontaomultilingualpagetree/css/backend-license.css';
        $GLOBALS['TL_JAVASCRIPT']['contao_multilingual_pagetree_root_licence'] = 'bundles/vtinnovationscontaomultilingualpagetree/js/root-licence-navigation.js';

        if ($creating || $rootId <= 0) {
            return $this->renderUnsavedRoot();
        }

        $domain = $this->domains->domain($rootId);
        if (null !== $domain) {
            $this->context->select($rootId, $domain);
            try { $this->adopter->adoptIfUnambiguous(); } catch (\Throwable) {}

            // Entering this section is the module-entry event. The notice claims
            // it at most once per authenticated session and never blocks or
            // changes what is rendered.
            $this->entryNotice?->noteEntry();
        } else {
            $this->context->clear();
        }

        // A verification failure must never blank the section: it is reported
        // through the status instead.
        try {
            $this->policy->reset();
            $decision = $this->policy->decision();
        } catch (\Throwable) {
            return $this->renderUnavailableState($rootId, $domain);
        }
        $labels = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeRootLicence'] ?? [];
        $template = new BackendTemplate('be_contao_multilingual_pagetree_root_license');
        $template->labels = $labels;
        $template->rootId = $rootId;
        $template->rootDomain = $domain;
        $template->licenceDomain = $decision->boundHost;
        $template->status = $decision->statusLabel();
        $template->statusLabel = $labels['statuses'][$decision->statusLabel()] ?? $decision->statusLabel();
        $template->lifetime = $decision->lifetime;
        $template->active = $decision->granted;
        try { $template->stored = $this->store->exists(); } catch (\Throwable) { $template->stored = true; }
        try { $template->legacyNotice = $this->adopter->needsManualActivation(); } catch (\Throwable) { $template->legacyNotice = false; }
        $template->canManage = null !== $domain && $this->permission->canManage($rootId);
        $template->token = $this->guard->tokenValue();
        $template->activateUrl = $this->url('activate', $rootId);
        $template->replaceUrl = $this->url('replace', $rootId);
        $template->refreshUrl = $this->url('refresh', $rootId);
        $template->verifyUrl = $this->url('verify', $rootId);
        $template->removeUrl = $this->url('remove', $rootId);

        return $template->parse();
    }

    /**
     * The panel for a root whose stored state could not be evaluated at all.
     *
     * It deliberately still renders, so an administrator can see the problem and
     * replace the licence instead of facing a form section that vanished.
     */
    private function renderUnavailableState(int $rootId, ?string $domain): string
    {
        $labels = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeRootLicence'] ?? [];
        $template = new BackendTemplate('be_contao_multilingual_pagetree_root_license');
        $template->labels = $labels;
        $template->rootId = $rootId;
        $template->rootDomain = $domain;
        $template->licenceDomain = null;
        $template->status = 'state_unusable';
        $template->statusLabel = $labels['statuses']['state_unusable'] ?? 'state_unusable';
        $template->lifetime = false;
        $template->active = false;
        $template->stored = false;
        $template->legacyNotice = false;
        $template->canManage = null !== $domain && $this->permission->canManage($rootId);
        $template->token = $this->guard->tokenValue();
        $template->activateUrl = $this->url('activate', $rootId);
        $template->replaceUrl = $this->url('replace', $rootId);
        $template->refreshUrl = $this->url('refresh', $rootId);
        $template->verifyUrl = $this->url('verify', $rootId);
        $template->removeUrl = $this->url('remove', $rootId);

        return $template->parse();
    }

    private function renderUnsavedRoot(): string
    {
        $labels = $GLOBALS['TL_LANG']['MSC']['contaoMultilingualPagetreeRootLicence'] ?? [];
        $template = new BackendTemplate('be_contao_multilingual_pagetree_root_license');
        $template->labels = $labels;
        $template->rootId = 0;
        $template->rootDomain = null;
        $template->licenceDomain = null;
        $template->status = 'not_activated';
        $template->statusLabel = $labels['statuses']['not_activated'] ?? 'not_activated';
        $template->lifetime = false;
        $template->active = false;
        $template->stored = false;
        $template->legacyNotice = false;
        $template->canManage = false;
        $template->token = '';
        $template->activateUrl = '';
        $template->replaceUrl = '';
        $template->refreshUrl = '';
        $template->verifyUrl = '';
        $template->removeUrl = '';

        return $template->parse();
    }

    /**
     * An unroutable action disables its control instead of breaking the whole
     * form section.
     */
    private function url(string $action, int $rootId): string
    {
        try {
            return $this->router->generate('contao_multilingual_pagetree_root_licence_'.$action, ['rootId' => $rootId]);
        } catch (\Throwable) {
            return '';
        }
    }
}
