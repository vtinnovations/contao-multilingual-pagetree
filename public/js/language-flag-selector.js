/*
 * Contao Multilingual Pagetree
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */

(() => {
    'use strict';

    const initialise = () => {
        const config = document.getElementById('cmp-language-flag-config');
        const language = document.querySelector('select[name="language"]');
        const label = document.querySelector('input[name="label"]');
        const flag = document.querySelector('select[name="flag"]');

        if (!config || !language || !label || !flag || language.dataset.cmpFlagSelectorReady === '1') {
            return;
        }

        let defaults = {};
        let labels = {};
        try {
            defaults = JSON.parse(config.dataset.defaultFlags || '{}');
            labels = JSON.parse(config.dataset.languageLabels || '{}');
        } catch (_) {
            return;
        }

        language.dataset.cmpFlagSelectorReady = '1';
        let manuallyOverridden = false;

        flag.addEventListener('change', () => {
            manuallyOverridden = true;
        });

        language.addEventListener('change', () => {
            const code = String(language.value || '').toLowerCase().replace('_', '-');
            if (!manuallyOverridden && Object.prototype.hasOwnProperty.call(defaults, code)) {
                flag.value = defaults[code] || '';
            }
            if (label.value.trim() === '' && typeof labels[code] === 'string') {
                label.value = labels[code];
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise, { once: true });
    } else {
        initialise();
    }
})();
