/*
 * Contao Multilingual Pagetree
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */

(() => {
    'use strict';

    const openPanel = (targetId, rootId) => {
        if (!/^cmp-[a-z0-9-]+$/.test(targetId) || !/^[1-9][0-9]*$/.test(rootId)) {
            return false;
        }

        const panel = document.getElementById(targetId);
        if (!panel) {
            return false;
        }

        const section = panel.closest('fieldset');
        const sectionId = section && section.id ? section.id : '';
        let activated = false;
        if (sectionId) {
            const tabs = document.querySelectorAll('a[href], [role="tab"][aria-controls]');
            for (const tab of tabs) {
                const href = tab.getAttribute('href') || '';
                let fragment = '';
                try {
                    fragment = new URL(href, window.location.href).hash;
                } catch (_) {
                    fragment = '';
                }
                if (fragment === `#${sectionId}` || tab.getAttribute('aria-controls') === sectionId) {
                    tab.click();
                    activated = true;
                    break;
                }
            }
        }
        if (!activated && section) {
            const legendControl = section.querySelector('legend button, legend a, legend');
            if (legendControl) {
                legendControl.click();
            }
        }

        const focusTarget = panel.querySelector('[data-cmp-licence-focus], .cmp-root-licence__status') || panel;
        window.requestAnimationFrame(() => {
            panel.scrollIntoView({block: 'start'});
            focusTarget.focus({preventScroll: true});
        });

        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(null, '', `#${targetId}`);
        }

        return true;
    };

    const submitAction = (button) => {
        const panel = button.closest('[data-cmp-licence-panel]');
        const actionName = button.dataset.cmpLicenceAction || '';
        const actionUrl = button.dataset.cmpLicenceActionUrl || '';
        if (!panel || !/^(activate|replace|refresh|verify|remove)$/.test(actionName) || !actionUrl) {
            return false;
        }
        if ('remove' === actionName && !window.confirm(button.dataset.confirm || '')) {
            return true;
        }

        let target;
        try {
            target = new URL(actionUrl, window.location.href);
        } catch (_) {
            return false;
        }
        if (target.origin !== window.location.origin) {
            return false;
        }
        target.hash = 'cmp-root-licence-panel';

        const form = document.createElement('form');
        form.method = 'post';
        form.action = target.href;
        form.hidden = true;
        for (const source of panel.querySelectorAll('[data-cmp-post-name]')) {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = source.dataset.cmpPostName;
            field.value = source.value || '';
            form.appendChild(field);
        }
        if ('remove' === actionName) {
            const confirmation = document.createElement('input');
            confirmation.type = 'hidden';
            confirmation.name = 'confirm_remove';
            confirmation.value = '1';
            form.appendChild(confirmation);
        }
        document.body.appendChild(form);
        form.submit();

        return true;
    };

    document.addEventListener('click', (event) => {
        const action = event.target.closest('button[data-cmp-licence-action][data-cmp-licence-action-url]');
        if (action && submitAction(action)) {
            event.preventDefault();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (!window.location.hash) {
            return;
        }
        const targetId = window.location.hash.slice(1);
        const panel = document.getElementById(targetId);
        const root = panel && panel.querySelector('[data-cmp-post-name="root_id"]');
        if (root) {
            openPanel(targetId, root.value || '');
        }
    });
})();
