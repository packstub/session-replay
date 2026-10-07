import assert from 'node:assert/strict';
import { test } from 'node:test';
import { REDACTED, dropHiddenValues, inputMaskOptions, isUnmasked, maskTextAttributes, redactPluginUrls, redactUrl, redactUrlAttributes, redactUrls, textMasker } from '../lib/privacy.js';

const names = ['token', 'signature', 'code', 'email'];

test('the values of listed query parameters are replaced, in any case, and the rest stays', () => {
    assert.equal(
        redactUrl('https://app.test/admin/password-reset/reset?email=ada%40example.com&Token=abc&signature=123&page=2', names),
        `https://app.test/admin/password-reset/reset?email=${REDACTED}&Token=${REDACTED}&signature=${REDACTED}&page=2`,
    );
});

test('a URL without secrets comes back exactly as it was', () => {
    assert.equal(redactUrl('https://app.test/orders?page=2&sort=-total', names), 'https://app.test/orders?page=2&sort=-total');
    assert.equal(redactUrl('not a url', names), 'not a url');
    assert.equal(redactUrl('https://app.test/?code=1', []), 'https://app.test/?code=1');
});

test('relative URLs are resolved against a base', () => {
    assert.equal(redactUrl('/callback?code=xyz&state=1', ['code'], 'https://app.test'), `https://app.test/callback?code=${REDACTED}&state=1`);
});

test('URLs inside a text are redacted where they stand', () => {
    const stack = 'TypeError: x\n    at https://app.test/js/app.js?token=abc:10:5\n    at (https://app.test/orders?page=1)';

    assert.equal(redactUrls(stack, names), `TypeError: x\n    at https://app.test/js/app.js?token=${REDACTED}:10:5\n    at (https://app.test/orders?page=1)`);
});

test('hidden inputs lose their value in a snapshot and in added nodes', () => {
    const snapshot = {
        type: 2,
        data: {
            node: {
                type: 0,
                childNodes: [
                    { type: 2, tagName: 'input', attributes: { type: 'hidden', name: '_token', value: 'secret' }, childNodes: [] },
                    { type: 2, tagName: 'input', attributes: { type: 'text', value: '***' }, childNodes: [] },
                ],
            },
        },
    };

    dropHiddenValues(snapshot);

    assert.equal(snapshot.data.node.childNodes[0].attributes.value, undefined);
    assert.equal(snapshot.data.node.childNodes[1].attributes.value, '***');

    const mutation = { type: 3, data: { source: 0, adds: [{ node: { type: 2, tagName: 'input', attributes: { type: 'HIDDEN', value: 'sig' } } }] } };

    dropHiddenValues(mutation);

    assert.equal(mutation.data.adds[0].node.attributes.value, undefined);
});

test('links, sources and form actions in the page are redacted, in snapshots and in changed attributes', () => {
    const snapshot = {
        type: 2,
        data: {
            node: {
                type: 0,
                childNodes: [
                    { type: 2, tagName: 'a', attributes: { href: '/orders?token=abc#main', class: 'skip' }, childNodes: [] },
                    { type: 2, tagName: 'form', attributes: { action: 'https://app.test/invite?signature=xyz' }, childNodes: [] },
                    { type: 2, tagName: 'a', attributes: { href: '/orders?page=2' }, childNodes: [] },
                ],
            },
        },
    };

    redactUrlAttributes(snapshot, names, 'https://app.test');

    const [skip, form, plain] = snapshot.data.node.childNodes;

    assert.equal(skip.attributes.href, `https://app.test/orders?token=${REDACTED}#main`);
    assert.equal(skip.attributes.class, 'skip');
    assert.equal(form.attributes.action, `https://app.test/invite?signature=${REDACTED}`);
    assert.equal(plain.attributes.href, '/orders?page=2');

    const change = { type: 3, data: { source: 0, adds: [], attributes: [{ id: 5, attributes: { href: '/verify?code=1' } }] } };

    redactUrlAttributes(change, names, 'https://app.test');

    assert.equal(change.data.attributes[0].attributes.href, `https://app.test/verify?code=${REDACTED}`);
});

test('srcset, poster and ping URLs are redacted too', () => {
    const snapshot = {
        type: 2,
        data: {
            node: {
                type: 2,
                tagName: 'img',
                attributes: { srcset: '/a.jpg?signature=1 1x, https://cdn.test/b.jpg?token=2&w=3 2x', poster: '/p.jpg?token=4', ping: '/track?code=5 /other' },
                childNodes: [],
            },
        },
    };

    redactUrlAttributes(snapshot, names, 'https://app.test');

    const attributes = snapshot.data.node.attributes;

    assert.equal(attributes.srcset, `https://app.test/a.jpg?signature=${REDACTED} 1x, https://cdn.test/b.jpg?token=${REDACTED}&w=3 2x`);
    assert.equal(attributes.poster, `https://app.test/p.jpg?token=${REDACTED}`);
    assert.equal(attributes.ping, `https://app.test/track?code=${REDACTED} /other`);
});

test('every input is masked by tag with mask_all_inputs, hidden and file inputs always', () => {
    const all = inputMaskOptions(true);
    const some = inputMaskOptions(false);

    // rrweb masks when the tag or the type is listed; "input" covers hidden, file and any type rrweb's own list misses.
    for (const key of ['input', 'textarea', 'select', 'password']) assert.equal(all[key], true, key);
    for (const key of ['password', 'hidden', 'file']) assert.equal(some[key], true, key);
    assert.equal(some.input, undefined);
    assert.equal(some.textarea, undefined);
});

test('text attributes of masked elements are masked, in snapshots, added nodes and changed attributes', () => {
    const masked = new Set([2, 3, 7]);
    const snapshot = {
        type: 2,
        data: {
            node: {
                type: 0,
                id: 1,
                childNodes: [
                    { type: 2, id: 2, tagName: 'img', attributes: { alt: 'Avatar of Jane Doe', src: '/a.png', class: 'avatar' }, childNodes: [] },
                    { type: 2, id: 3, tagName: 'input', attributes: { type: 'submit', value: 'Remove Jane', placeholder: 'Name' }, childNodes: [] },
                    { type: 2, id: 4, tagName: 'button', attributes: { title: 'Save changes' }, childNodes: [] },
                ],
            },
        },
    };

    maskTextAttributes(snapshot, (id) => masked.has(id));

    const [avatar, submit, plain] = snapshot.data.node.childNodes;

    assert.equal(avatar.attributes.alt, '****** ** **** ***');
    assert.equal(avatar.attributes.src, '/a.png');
    assert.equal(avatar.attributes.class, 'avatar');
    assert.equal(submit.attributes.value, '****** ****');
    assert.equal(submit.attributes.placeholder, '****');
    assert.equal(plain.attributes.title, 'Save changes');

    const change = { type: 3, data: { source: 0, adds: [], attributes: [{ id: 7, attributes: { 'aria-label': 'Jane' } }, { id: 8, attributes: { 'aria-label': 'Menu' } }] } };

    maskTextAttributes(change, (id) => masked.has(id));

    assert.equal(change.data.attributes[0].attributes['aria-label'], '****');
    assert.equal(change.data.attributes[1].attributes['aria-label'], 'Menu');
});

test('a text input keeps its value attribute for the input masking to handle', () => {
    const snapshot = { type: 2, data: { node: { type: 2, id: 1, tagName: 'input', attributes: { type: 'text', value: '***' }, childNodes: [] } } };

    maskTextAttributes(snapshot, () => true);

    assert.equal(snapshot.data.node.attributes.value, '***');
});

test('URLs in console messages and stack frames are redacted', () => {
    const event = {
        type: 6,
        data: {
            plugin: 'rrweb/console@1',
            payload: { level: 'error', payload: ['"failed https://app.test/reset?token=abc"'], trace: ['at https://app.test/app.js?signature=x:10:5'] },
        },
    };

    redactPluginUrls(event, names);

    assert.equal(event.data.payload.payload[0], `"failed https://app.test/reset?token=${REDACTED}"`);
    assert.equal(event.data.payload.trace[0], `at https://app.test/app.js?signature=${REDACTED}:10:5`);
});

test('masking is looked up only for elements that carry text attributes', () => {
    const asked = [];
    const snapshot = {
        type: 2,
        data: { node: { type: 0, id: 1, childNodes: [{ type: 2, id: 2, tagName: 'div', attributes: { class: 'row' }, childNodes: [] }, { type: 2, id: 3, tagName: 'img', attributes: { alt: 'x' }, childNodes: [] }] } },
    };

    maskTextAttributes(snapshot, (id) => asked.push(id) && false);

    assert.deepEqual(asked, [3]);
});

/** An element whose closest() matches the selectors it was given, the way the browser would. */
function element(...matching) {
    return { closest: (selector) => (selector.split(',').some((part) => matching.includes(part.trim())) ? {} : null) };
}

test('with mask_all_text, text inside the unmask selector stays readable unless the mask selector matches', () => {
    const mask = textMasker('[data-replay-unmask]', '[data-replay-mask], [contenteditable]');

    assert.equal(mask('Orders', element('[data-replay-unmask]')), 'Orders');
    assert.equal(mask('Jane Doe', element()), '**** ***');
    assert.equal(mask('Jane Doe', element('[data-replay-unmask]', '[data-replay-mask]')), '**** ***');
    assert.equal(mask('Draft', element('[data-replay-unmask]', '[contenteditable]')), '*****');
    assert.equal(mask('Orders', null), '******');
});

test('an unmask selector the browser rejects masks', () => {
    const throwing = { closest: () => { throw new Error('SyntaxError'); } };

    assert.equal(isUnmasked(throwing, '[bad', null), false);
    assert.equal(isUnmasked(element('[data-replay-unmask]'), '', null), false);
});
