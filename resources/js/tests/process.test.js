import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    SKIP_ATTRIBUTE,
    assetHash,
    assetPlaceholder,
    attributeMatcher,
    referencedAssets,
    renameVolatileIds,
    restoreAssets,
    restoreSnapshots,
    shareSnapshot,
    sharedSnapshots,
    snapshotHash,
    isPointerSample,
    skipNodes,
    sortEvents,
    stripAttributes,
    styleSlots,
} from '../lib/process.js';

const hash = 'a'.repeat(64);

const snapshot = () => ({
    type: 2,
    timestamp: 10,
    data: {
        node: {
            type: 0,
            childNodes: [
                {
                    type: 2,
                    tagName: 'html',
                    attributes: {},
                    childNodes: [
                        { type: 2, tagName: 'link', attributes: { _cssText: 'body{color:red}'.repeat(200) }, childNodes: [] },
                        { type: 2, tagName: 'style', attributes: {}, childNodes: [{ type: 3, isStyle: true, textContent: '.a{b:c}'.repeat(400) }] },
                        {
                            type: 2,
                            tagName: 'div',
                            attributes: { class: 'fi-page', 'wire:snapshot': '{"data":1}', 'wire:id': 'abc', 'x-data': '{open:false}', '@click': 'go()', ':class': 'x', 'x-cloak': '', 'data-id': '7' },
                            childNodes: [{ type: 3, textContent: 'Hello' }],
                        },
                    ],
                },
            ],
        },
    },
});

test('attributeMatcher honours wildcards, exact names and the keep list', () => {
    const matches = attributeMatcher(['wire:*', 'x-*', '@*', ':*', 'ax-load*', 'data-secret'], ['x-cloak', 'wire:loading*']);

    // Livewire hides [wire:loading] elements with a stylesheet rule; without the attribute every spinner shows.
    assert.equal(matches('wire:loading'), false);
    assert.equal(matches('wire:loading.delay'), false);
    assert.equal(matches('wire:target'), true);

    assert.equal(matches('wire:snapshot'), true);
    assert.equal(matches('x-data'), true);
    assert.equal(matches('@click'), true);
    assert.equal(matches(':class'), true);
    assert.equal(matches('data-secret'), true);
    assert.equal(matches('x-cloak'), false);
    assert.equal(matches('class'), false);
    assert.equal(matches('data-id'), false);
    assert.equal(attributeMatcher([], []), null);
});

test('stripAttributes cleans snapshots and keeps what a stylesheet can select on', () => {
    const event = stripAttributes(snapshot(), attributeMatcher(['wire:*', 'x-*', '@*', ':*'], ['x-cloak']));
    const div = event.data.node.childNodes[0].childNodes[2];

    assert.deepEqual(Object.keys(div.attributes).sort(), ['class', 'data-id', 'x-cloak']);
    assert.ok(event.data.node.childNodes[0].childNodes[0].attributes._cssText);
});

test('stripAttributes cleans mutations and drops changes that became empty', () => {
    const event = stripAttributes(
        {
            type: 3,
            timestamp: 20,
            data: {
                source: 0,
                adds: [{ parentId: 1, nextId: null, node: { type: 2, tagName: 'p', attributes: { 'wire:key': 'k', id: 'p' }, childNodes: [] } }],
                attributes: [
                    { id: 5, attributes: { 'wire:snapshot': '{}' } },
                    { id: 6, attributes: { 'wire:effects': '[]', class: 'open' } },
                ],
                removes: [],
                texts: [],
            },
        },
        attributeMatcher(['wire:*']),
    );

    assert.deepEqual(event.data.adds[0].node.attributes, { id: 'p' });
    assert.deepEqual(event.data.attributes, [{ id: 6, attributes: { class: 'open' } }]);
});

test('styleSlots finds link and style text above the size floor', () => {
    assert.equal(styleSlots(snapshot(), 1024).length, 2);
    assert.equal(styleSlots(snapshot(), 1_000_000).length, 0);
});

test('placeholders round-trip through referencedAssets and restoreAssets', () => {
    const event = snapshot();
    const slots = styleSlots(event, 1024);
    const link = slots.find((slot) => slot.key === '_cssText');
    const style = slots.find((slot) => slot.key === 'textContent');
    const original = link.text;

    link.holder[link.key] = assetPlaceholder(hash);
    style.holder[style.key] = assetPlaceholder('b'.repeat(64));

    assert.equal(assetHash(assetPlaceholder(hash)), hash);
    assert.equal(assetHash('/*sr-asset:nope*/'), null);
    assert.deepEqual(referencedAssets([event]).sort(), [hash, 'b'.repeat(64)]);

    restoreAssets([event], new Map([[hash, original]]));

    assert.equal(event.data.node.childNodes[0].childNodes[0].attributes._cssText, original);
    // Unknown hash: an empty sheet, not a stray comment.
    assert.equal(event.data.node.childNodes[0].childNodes[1].childNodes[0].textContent, '');
});

test('a link that loaded after it was serialized: its text in a mutation\'s attribute changes is deduplicated too', () => {
    const css = 'body{color:red}'.repeat(200);
    const event = {
        type: 3,
        timestamp: 20,
        data: { source: 0, texts: [], removes: [], adds: [], attributes: [{ id: 9, attributes: { rel: 'stylesheet', href: '/app.css', _cssText: css } }, { id: 10, attributes: { _cssText: 'a{}' } }] },
    };

    stripAttributes(event, attributeMatcher(['_*', 'wire:*']));
    assert.equal(event.data.attributes[0].attributes._cssText, css);

    const slots = styleSlots(event, 1024);

    // The small sheet stays inline.
    assert.equal(slots.length, 1);
    assert.equal(slots[0].text, css);

    slots[0].holder[slots[0].key] = assetPlaceholder(hash);

    assert.deepEqual(referencedAssets([event]), [hash]);

    restoreAssets([event], new Map([[hash, css]]));

    assert.equal(event.data.attributes[0].attributes._cssText, css);
    assert.equal(event.data.attributes[1].attributes._cssText, 'a{}');
});

test('a shared snapshot keeps its hash in the chunk and gets its tree back in the player', () => {
    const event = snapshot();
    event.data.initialOffset = { top: 120, left: 0 };
    const text = JSON.stringify(event.data.node);

    shareSnapshot(event, hash);

    assert.deepEqual(event.data, { initialOffset: { top: 120, left: 0 }, srSnapshot: hash });
    assert.equal(snapshotHash(event), hash);
    assert.deepEqual(sharedSnapshots([event, { type: 3, data: {} }, shareSnapshot(snapshot(), hash)]), [hash]);

    const second = shareSnapshot(snapshot(), hash);

    restoreSnapshots([event, second], new Map([[hash, text]]));

    assert.equal(JSON.stringify(event.data.node), text);
    assert.equal(event.data.srSnapshot, undefined);
    assert.equal(event.data.initialOffset.top, 120);
    // Each event gets its own copy: restoring stylesheets in one never touches the other.
    assert.notEqual(event.data.node, second.data.node);
});

test('an ordinary snapshot, a malformed hash and other events are left alone', () => {
    const event = snapshot();

    assert.equal(snapshotHash(event), null);
    assert.equal(snapshotHash({ type: 2, data: { srSnapshot: 'nope' } }), null);
    assert.equal(snapshotHash({ type: 3, data: { srSnapshot: hash } }), null);

    restoreSnapshots([event], new Map());

    assert.equal(event.data.node.childNodes[0].tagName, 'html');
});

test('a shared snapshot that could not be loaded replays as an empty page', () => {
    const missing = shareSnapshot(snapshot(), hash);
    const broken = shareSnapshot(snapshot(), 'c'.repeat(64));

    restoreSnapshots([missing, broken], new Map([['c'.repeat(64), '{not json']]));

    for (const event of [missing, broken]) {
        assert.equal(event.data.node.type, 0);
        assert.deepEqual(event.data.node.childNodes[0].childNodes.map((node) => node.tagName), ['head', 'body']);
    }
});

test('renameVolatileIds gives made-up ids the same name on every load and keeps the pairs matching', () => {
    const page = (suffix, other) => ({
        type: 0,
        childNodes: [
            { type: 2, tagName: 'button', attributes: { 'aria-controls': `fi-dropdown-panel-${suffix}`, class: 'fi-btn' }, childNodes: [] },
            { type: 2, tagName: 'div', attributes: { id: `fi-dropdown-panel-${suffix}`, 'aria-labelledby': `title fi-dropdown-panel-${other}` }, childNodes: [] },
            { type: 2, tagName: 'div', attributes: { id: `fi-dropdown-panel-${other}` }, childNodes: [] },
            { type: 2, tagName: 'label', attributes: { for: 'email', id: 'fi-dropdown-panel-' }, childNodes: [] },
        ],
    });

    const first = renameVolatileIds(page('ij8vsnsd', 'k2'), ['fi-dropdown-panel-*']);
    const second = renameVolatileIds(page('8521d3yo', 'zz'), ['fi-dropdown-panel-*']);

    assert.equal(JSON.stringify(first), JSON.stringify(second));

    const [button, panel, other, label] = first.childNodes;

    assert.equal(button.attributes['aria-controls'], panel.attributes.id);
    assert.match(panel.attributes['aria-labelledby'], /^title fi-dropdown-panel-sr\d$/);
    assert.equal(panel.attributes['aria-labelledby'].split(' ')[1], other.attributes.id);
    assert.equal(button.attributes.class, 'fi-btn');
    // A bare prefix and anything else stay as they were.
    assert.deepEqual(label.attributes, { for: 'email', id: 'fi-dropdown-panel-' });

    // Without patterns nothing changes.
    assert.deepEqual(renameVolatileIds(page('a', 'b'), []), page('a', 'b'));
    assert.deepEqual(renameVolatileIds(page('a', 'b'), ['exact-name', '*']), page('a', 'b'));
});

test('sortEvents orders by timestamp', () => {
    assert.deepEqual(sortEvents([{ timestamp: 3 }, { timestamp: 1 }, { timestamp: 2 }]).map((e) => e.timestamp), [1, 2, 3]);
});

const el = (id, tagName, attributes = {}, childNodes = []) => ({ type: 2, id, tagName, attributes, childNodes });

test('skipNodes drops data-replay-skip subtrees from a snapshot and remembers their ids', () => {
    const skipped = new Set([999]);
    const event = {
        type: 2,
        data: {
            node: {
                type: 0,
                id: 1,
                childNodes: [el(2, 'body', {}, [el(3, 'p', {}, [{ type: 3, id: 4, textContent: 'hi' }]), el(5, 'div', { [SKIP_ATTRIBUTE]: '' }, [el(6, 'button', {}, [{ type: 3, id: 7, textContent: 'End' }])])])],
            },
        },
    };

    assert.equal(skipNodes(event, skipped), true);
    assert.deepEqual(event.data.node.childNodes[0].childNodes.map((node) => node.id), [3]);
    // A snapshot lists the page again: what was skipped before is forgotten.
    assert.deepEqual([...skipped].sort((a, b) => a - b), [5, 6, 7]);
});

test('skipNodes drops adds into skipped nodes, in any order, and the changes aimed at them', () => {
    const skipped = new Set();
    const event = {
        type: 3,
        data: {
            source: 0,
            // The child comes before its skipped parent, as rrweb may order them.
            adds: [
                { parentId: 10, nextId: null, node: el(11, 'span') },
                { parentId: 2, nextId: 3, node: el(10, 'div', { [SKIP_ATTRIBUTE]: '1' }) },
                { parentId: 2, nextId: 10, node: el(12, 'p', {}, [el(13, 'i', { [SKIP_ATTRIBUTE]: '' }), el(14, 'b')]) },
            ],
            removes: [],
            attributes: [],
            texts: [],
        },
    };

    assert.equal(skipNodes(event, skipped), true);
    assert.deepEqual(event.data.adds.map((add) => add.node.id), [12]);
    // Its next sibling never reaches the replay: appended instead of waiting for it.
    assert.equal(event.data.adds[0].nextId, null);
    assert.deepEqual(event.data.adds[0].node.childNodes.map((node) => node.id), [14]);
    assert.ok(skipped.has(10) && skipped.has(11) && skipped.has(13));

    const changes = { type: 3, data: { source: 0, adds: [], removes: [{ parentId: 2, id: 10 }], attributes: [{ id: 11, attributes: { class: 'x' } }], texts: [{ id: 13, value: 'secret' }] } };

    assert.equal(skipNodes(changes, skipped), false);

    const mixed = { type: 3, data: { source: 0, adds: [], removes: [], attributes: [{ id: 11, attributes: { class: 'x' } }, { id: 3, attributes: { class: 'y' } }], texts: [] } };

    assert.equal(skipNodes(mixed, skipped), true);
    assert.deepEqual(mixed.data.attributes.map((change) => change.id), [3]);
});

test('skipNodes drops input, clicks and scrolls aimed at a skipped node, and keeps the rest', () => {
    const skipped = new Set([10]);

    assert.equal(skipNodes({ type: 3, data: { source: 5, id: 10, text: 'my message', isChecked: false } }, skipped), false);
    assert.equal(skipNodes({ type: 3, data: { source: 2, type: 2, id: 10, x: 1, y: 1 } }, skipped), false);
    assert.equal(skipNodes({ type: 3, data: { source: 5, id: 3, text: '***' } }, skipped), true);
    assert.equal(skipNodes({ type: 4, data: { href: 'https://x.test' } }, skipped), true);
    // An empty mutation stays what it was.
    assert.equal(skipNodes({ type: 3, data: { source: 0, adds: [], removes: [], attributes: [], texts: [] } }, new Set()), true);
});

test('isPointerSample names the events a live stream drops first', () => {
    assert.equal(isPointerSample({ type: 3, data: { source: 1, positions: [] } }), true);
    assert.equal(isPointerSample({ type: 3, data: { source: 2 } }), false);
    assert.equal(isPointerSample({ type: 2, data: {} }), false);
});
