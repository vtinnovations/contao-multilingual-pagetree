/*
 * Contao Multilingual Pagetree
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[name^="fieldState_"]').forEach((select) => {
        const fieldName = select.name.substring('fieldState_'.length);
        const input = document.querySelector(`[name="${CSS.escape(fieldName)}"], [name="${CSS.escape(fieldName)}[]"]`);
        if (!input) {
            return;
        }

        const update = () => {
            const custom = select.value === 'custom';
            input.disabled = !custom;
            input.closest('.widget')?.classList.toggle('contao-multilingual-pagetree-inactive', !custom);
        };

        select.addEventListener('change', update);
        update();
    });
});
