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
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/root-licence-navigation.js'), 'utf8');

/** The operations the panel offers, in the order the panel offers them. */
const OPERATIONS = ['activate', 'replace', 'refresh', 'verify', 'remove'];

const boot = ({panelPresent = true, hash = ''} = {}) => {
    const listeners = {};
    const calls = {focus: 0, scroll: 0, tab: 0, prevented: 0, history: 0, submit: 0};
    let submittedForm = null;
    const focusTarget = {focus: () => calls.focus++};
    const section = {id: 'pal_contao_multilingual_pagetree_licence_legend', querySelector: () => null};
    const panel = {
        closest: () => section,
        querySelector: (selector) => selector.includes('root_id') ? {value: '17'} : focusTarget,
        querySelectorAll: () => [
            {dataset: {cmpPostName: 'root_id'}, value: '17'},
            {dataset: {cmpPostName: 'root_domain'}, value: 'example.invalid'},
            {dataset: {cmpPostName: 'REQUEST_TOKEN'}, value: 'csrf-test'},
            {dataset: {cmpPostName: 'licence_key'}, value: 'test-key'},
        ],
        scrollIntoView: () => calls.scroll++,
    };
    const tab = {
        getAttribute: (name) => 'href' === name ? '#pal_contao_multilingual_pagetree_licence_legend' : null,
        click: () => calls.tab++,
    };
    const document = {
        addEventListener: (name, callback) => { listeners[name] = callback; },
        getElementById: () => panelPresent ? panel : null,
        querySelectorAll: () => [tab],
        createElement: (tag) => {
            const element = {tag, children: [], appendChild(child) { this.children.push(child); }};
            if ('form' === tag) element.submit = () => { calls.submit++; submittedForm = element; };
            return element;
        },
        body: {appendChild: () => {}},
    };
    const window = {
        location: {href: 'https://backend.invalid/contao?do=page&act=edit&id=17', origin: 'https://backend.invalid', hash},
        history: {replaceState: () => calls.history++},
        requestAnimationFrame: (callback) => callback(),
        confirm: () => true,
    };
    vm.runInNewContext(source, {document, window, URL});

    return {listeners, calls, panel, submittedForm: () => submittedForm};
};

/** Clicks one action button and returns what the script did. */
const clickAction = (context, dataset) => {
    const button = {dataset, closest: () => context.panel};
    context.listeners.click({
        target: {closest: () => button},
        preventDefault: () => context.calls.prevented++,
    });

    return context;
};

// Every shipped operation posts its own isolated form to its own URL, carrying
// exactly the panel fields and nothing else.
for (const action of OPERATIONS) {
    const context = boot();
    clickAction(context, {
        cmpLicenceAction: action,
        cmpLicenceActionUrl: `/contao/licence/17/${action}`,
        confirm: 'Confirm?',
    });

    assert.equal(context.calls.submit, 1, `The ${action} operation must submit one isolated POST form.`);
    assert.equal(context.calls.prevented, 1, `The ${action} operation must suppress the default click.`);

    const form = context.submittedForm();
    assert.equal(form.tag, 'form');
    assert.equal(form.method, 'post', `The ${action} operation must use POST.`);
    assert.match(form.action, new RegExp(`/contao/licence/17/${action}#cmp-root-licence-panel$`));

    const names = form.children.map((field) => field.name);
    const expected = ['root_id', 'root_domain', 'REQUEST_TOKEN', 'licence_key'];
    assert.deepEqual(
        names,
        'remove' === action ? [...expected, 'confirm_remove'] : expected,
        `The ${action} operation must post exactly the panel fields.`,
    );
}

// Removal carries its explicit confirmation flag; nothing else does.
{
    const context = boot();
    clickAction(context, {
        cmpLicenceAction: 'remove',
        cmpLicenceActionUrl: '/contao/licence/17/remove',
        confirm: 'Confirm?',
    });
    const flag = context.submittedForm().children.find((field) => 'confirm_remove' === field.name);
    assert.equal(flag.value, '1', 'Removal must send its confirmation flag.');
}

// An operation outside the shipped set is refused rather than posted anywhere.
{
    const context = boot();
    clickAction(context, {cmpLicenceAction: 'transfer', cmpLicenceActionUrl: '/contao/licence/17/transfer'});
    assert.equal(context.calls.submit, 0, 'An unknown operation must never be submitted.');
    assert.equal(context.calls.prevented, 0);
}

// A cross-origin action URL is refused, so a crafted panel cannot post the
// request token and the licence key to another host.
{
    const context = boot();
    clickAction(context, {
        cmpLicenceAction: 'activate',
        cmpLicenceActionUrl: 'https://attacker.invalid/contao/licence/17/activate',
    });
    assert.equal(context.calls.submit, 0, 'A cross-origin action URL must never be submitted.');
}

// A missing action URL is refused.
{
    const context = boot();
    clickAction(context, {cmpLicenceAction: 'activate', cmpLicenceActionUrl: ''});
    assert.equal(context.calls.submit, 0, 'An action without a URL must never be submitted.');
}

// Arriving with the panel fragment opens and focuses the section without
// submitting anything.
{
    const context = boot({hash: '#cmp-root-licence-panel'});
    context.listeners.DOMContentLoaded();
    assert.deepEqual(
        context.calls,
        {focus: 1, scroll: 1, tab: 1, prevented: 0, history: 1, submit: 0},
        'The panel fragment must open, focus and scroll the section only.',
    );
}

// Without a fragment nothing is opened at all.
{
    const context = boot();
    context.listeners.DOMContentLoaded();
    assert.deepEqual(context.calls, {focus: 0, scroll: 0, tab: 0, prevented: 0, history: 0, submit: 0});
}

// A fragment whose panel is absent is ignored rather than throwing.
{
    const context = boot({panelPresent: false, hash: '#cmp-root-licence-panel'});
    context.listeners.DOMContentLoaded();
    assert.equal(context.calls.focus, 0, 'A missing panel must be ignored.');
}

process.stdout.write(`Root licence panel tests passed (${OPERATIONS.length} operations).\n`);
