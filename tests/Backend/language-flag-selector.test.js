/*
 * Contao Multilingual Pagetree
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const listeners = {};
const language = { value: '', dataset: {}, addEventListener: (name, callback) => { listeners[`language:${name}`] = callback; } };
const label = { value: '' };
const flag = { value: '', addEventListener: (name, callback) => { listeners[`flag:${name}`] = callback; } };
const config = { dataset: {
    defaultFlags: JSON.stringify({ en: 'gb', de: 'de' }),
    languageLabels: JSON.stringify({ en: 'English', de: 'German' }),
} };

const document = {
    readyState: 'complete',
    getElementById: (id) => id === 'cmp-language-flag-config' ? config : null,
    querySelector: (selector) => ({ 'select[name="language"]': language, 'input[name="label"]': label, 'select[name="flag"]': flag })[selector] || null,
};

vm.runInNewContext(fs.readFileSync('public/js/language-flag-selector.js', 'utf8'), { document, JSON, Object, String });

language.value = 'en';
listeners['language:change']();
assert.equal(flag.value, 'gb');
assert.equal(label.value, 'English');

flag.value = 'us';
listeners['flag:change']();
language.value = 'de';
listeners['language:change']();
assert.equal(flag.value, 'us', 'a manual flag override must survive later language changes');

label.value = 'Custom label';
language.value = 'en';
listeners['language:change']();
assert.equal(label.value, 'Custom label', 'a custom label must never be overwritten');
