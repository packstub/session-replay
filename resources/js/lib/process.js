/**
 * What happens to an rrweb event between the recorder and the wire, and the
 * reverse in the player. Pure functions over plain objects, so they run in
 * Node for the tests.
 *
 * rrweb shapes used here: a full snapshot is { type: 2, data: { node } }, a
 * mutation is { type: 3, data: { source: 0, adds: [{ node }], attributes:
 * [{ id, attributes }] } }; a serialized element is { type: 2, tagName,
 * attributes, childNodes }, a text node { type: 3, textContent, isStyle }.
 * An inlined stylesheet sits in attributes._cssText (link, or a style
 * element built from rules) or in the text child of a style element.
 */

export const EVENT_FULL_SNAPSHOT = 2;
export const EVENT_INCREMENTAL = 3;
export const SOURCE_MUTATION = 0;
const NODE_ELEMENT = 2;
const NODE_TEXT = 3;

const ASSET_PREFIX = '/*sr-asset:';
const ASSET_SUFFIX = '*/';
const ASSET_PATTERN = /^\/\*sr-asset:([a-f0-9]{64})\*\/$/;

export function assetPlaceholder(hash) {
    return ASSET_PREFIX + hash + ASSET_SUFFIX;
}

export function assetHash(text) {
    const match = typeof text === 'string' && text.length === ASSET_PREFIX.length + 64 + ASSET_SUFFIX.length ? ASSET_PATTERN.exec(text) : null;

    return match ? match[1] : null;
}

/** ['wire:*', 'x-cloak'] → a test for an attribute name: exact names, or a prefix when the pattern ends in *. */
function nameTest(patterns) {
    const exact = new Set();
    const prefixes = [];

    for (const pattern of patterns) {
        if (typeof pattern !== 'string' || pattern === '' || pattern === '*') continue;

        pattern.endsWith('*') ? prefixes.push(pattern.slice(0, -1)) : exact.add(pattern);
    }

    if (exact.size === 0 && prefixes.length === 0) return null;

    return (name) => exact.has(name) || prefixes.some((prefix) => name.startsWith(prefix));
}

/** ['wire:*', 'x-*'] → a test for an attribute to drop; names matching `keep` always stay. */
export function attributeMatcher(patterns = [], keep = []) {
    const dropped = nameTest(patterns);
    const kept = nameTest(keep);

    if (dropped === null) return null;

    return (name) => dropped(name) && !(kept && kept(name));
}

/** Every serialized node an event carries: the snapshot's tree or the trees a mutation added. */
function roots(event) {
    if (event.type === EVENT_FULL_SNAPSHOT && event.data && event.data.node) return [event.data.node];

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION) {
        return (event.data.adds || []).map((add) => add.node).filter(Boolean);
    }

    return [];
}

function walk(node, visit) {
    const stack = [node];

    while (stack.length) {
        const current = stack.pop();

        visit(current);

        if (current.childNodes) for (const child of current.childNodes) stack.push(child);
    }
}

/**
 * Drop the attributes the player never uses (it runs no scripts): Livewire's
 * wire:snapshot and wire:effects, Alpine expressions and the like. Mutates.
 */
export function stripAttributes(event, matches) {
    if (!matches) return event;

    for (const root of roots(event)) {
        walk(root, (node) => {
            if (node.type !== NODE_ELEMENT || !node.attributes) return;

            for (const name of Object.keys(node.attributes)) {
                if (name !== '_cssText' && matches(name)) delete node.attributes[name];
            }
        });
    }

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION && event.data.attributes) {
        event.data.attributes = event.data.attributes.filter((change) => {
            for (const name of Object.keys(change.attributes || {})) {
                if (name !== '_cssText' && matches(name)) delete change.attributes[name];
            }

            // A change that only touched dropped attributes says nothing any more.
            return Object.keys(change.attributes || {}).length > 0 || change.styleDiff || change._unchangedStyles;
        });
    }

    return event;
}

/**
 * The places an event holds stylesheet text: [{ holder, key, text }], where
 * holder[key] is the text (or, in the player, the placeholder). A <link>
 * whose sheet had not loaded when rrweb serialized it gets its text later,
 * in a mutation's attribute changes: { attributes: [{ id, attributes: {
 * _cssText } }] }.
 */
export function styleSlots(event, minBytes = 0) {
    const slots = [];

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION && Array.isArray(event.data.attributes)) {
        for (const change of event.data.attributes) {
            if (change && change.attributes && typeof change.attributes._cssText === 'string' && change.attributes._cssText.length >= minBytes) {
                slots.push({ holder: change.attributes, key: '_cssText', text: change.attributes._cssText });
            }
        }
    }

    for (const root of roots(event)) {
        walk(root, (node) => {
            if (node.type === NODE_ELEMENT && node.attributes && typeof node.attributes._cssText === 'string' && node.attributes._cssText.length >= minBytes) {
                slots.push({ holder: node.attributes, key: '_cssText', text: node.attributes._cssText });
            }

            if (node.type === NODE_TEXT && node.isStyle && typeof node.textContent === 'string' && node.textContent.length >= minBytes) {
                slots.push({ holder: node, key: 'textContent', text: node.textContent });
            }
        });
    }

    return slots;
}

/** Hashes the player has to fetch before it can show these events. */
export function referencedAssets(events) {
    const hashes = new Set();

    for (const event of events) {
        for (const slot of styleSlots(event)) {
            const hash = assetHash(slot.text);

            if (hash) hashes.add(hash);
        }
    }

    return [...hashes];
}

/** Put the stylesheets back; a hash without text becomes an empty sheet rather than a comment that hides the gap. */
export function restoreAssets(events, texts) {
    for (const event of events) {
        for (const slot of styleSlots(event)) {
            const hash = assetHash(slot.text);

            if (hash) slot.holder[slot.key] = texts.get(hash) ?? '';
        }
    }

    return events;
}

/*
 * Shared snapshots. On a page the app opted in (snapshots.share_routes,
 * snapshots.share_paths), the recorder stores the snapshot's node tree once
 * per SHA-256 of its JSON and the chunk keeps the full snapshot event without
 * its tree: { type: 2, data: { srSnapshot: '<sha256>', initialOffset } }. A
 * full snapshot with data.node is the format every recording had before and
 * still has on every other page.
 */

const SNAPSHOT_KEY = 'srSnapshot';
const HASH_PATTERN = /^[a-f0-9]{64}$/;

// Attributes that hold an element id, or a space-separated list of them.
const ID_ATTRIBUTES = ['id', 'for', 'form', 'list', 'aria-activedescendant', 'aria-controls', 'aria-describedby', 'aria-details', 'aria-errormessage', 'aria-flowto', 'aria-labelledby', 'aria-owns', 'popovertarget'];

/** The hash a full snapshot event points at instead of carrying its tree, or null. */
export function snapshotHash(event) {
    const hash = event && event.type === EVENT_FULL_SNAPSHOT && event.data ? event.data[SNAPSHOT_KEY] : null;

    return typeof hash === 'string' && HASH_PATTERN.test(hash) ? hash : null;
}

/** Swap the snapshot's tree for its hash. Mutates. */
export function shareSnapshot(event, hash) {
    delete event.data.node;
    event.data[SNAPSHOT_KEY] = hash;

    return event;
}

/** Hashes of the shared snapshots these events point at. */
export function sharedSnapshots(events) {
    const hashes = new Set();

    for (const event of events) {
        const hash = snapshotHash(event);

        if (hash) hashes.add(hash);
    }

    return [...hashes];
}

/** A document with an empty body: what a snapshot that could not be loaded replays as, instead of breaking the player. */
function emptyDocument() {
    const element = (tagName, id, childNodes = []) => ({ type: NODE_ELEMENT, tagName, attributes: {}, childNodes, id });

    return { type: 0, childNodes: [element('html', -2, [element('head', -3), element('body', -4)])], id: -1 };
}

/** Put the trees back from their JSON text (Map hash → text); every event gets its own copy. Mutates. */
export function restoreSnapshots(events, texts) {
    for (const event of events) {
        const hash = snapshotHash(event);

        if (!hash) continue;

        let node = null;

        try {
            node = texts.has(hash) ? JSON.parse(texts.get(hash)) : null;
        } catch {
            node = null;
        }

        event.data.node = node && typeof node === 'object' ? node : emptyDocument();
        delete event.data[SNAPSHOT_KEY];
    }

    return events;
}

/**
 * Ids a script makes up on every page load (['fi-dropdown-panel-*']) get the
 * same made-up suffix on every load: prefix + 'sr' + a counter in tree order,
 * applied to the id and to every attribute that points at it, so the pair
 * still matches. Only shared snapshots go through this; nothing selects on a
 * random id, so the replay looks the same. Mutates.
 */
export function renameVolatileIds(node, patterns = []) {
    const prefixes = patterns.filter((pattern) => typeof pattern === 'string' && pattern.length > 1 && pattern.endsWith('*')).map((pattern) => pattern.slice(0, -1));

    if (!node || prefixes.length === 0) return node;

    const renamed = new Map();
    const rename = (token) => {
        const prefix = prefixes.find((candidate) => token.length > candidate.length && token.startsWith(candidate));

        if (!prefix) return token;

        if (!renamed.has(token)) renamed.set(token, `${prefix}sr${renamed.size + 1}`);

        return renamed.get(token);
    };

    walk(node, (current) => {
        if (current.type !== NODE_ELEMENT || !current.attributes) return;

        for (const name of ID_ATTRIBUTES) {
            const value = current.attributes[name];

            if (typeof value !== 'string' || value === '') continue;

            const tokens = value.trim().split(/\s+/);
            const next = tokens.map(rename);

            if (next.some((token, index) => token !== tokens[index])) current.attributes[name] = next.join(' ');
        }
    });

    return node;
}

/** Oldest first, which is what the replayer expects after chunks arrive out of order. */
export function sortEvents(events) {
    return events.sort((a, b) => a.timestamp - b.timestamp);
}

/*
 * data-replay-skip: an element carrying the attribute is left out of the
 * recording entirely, with everything inside it (the Assist widget, its
 * prompts, the agent's cursor and the annotation canvas). data-replay-block
 * keeps an empty box of the element's size and data-replay-ignore only drops
 * input events; neither removes an element. The attribute must be on the
 * element when it is inserted: one added later does not take the element out.
 */

export const SKIP_ATTRIBUTE = 'data-replay-skip';
const SOURCE_MOUSE_MOVE = 1;
const SOURCE_TOUCH_MOVE = 6;
const SOURCE_DRAG = 12;

function carriesSkip(node) {
    return !!node && node.type === NODE_ELEMENT && !!node.attributes && Object.prototype.hasOwnProperty.call(node.attributes, SKIP_ATTRIBUTE);
}

function collectIds(node, ids) {
    walk(node, (current) => {
        if (typeof current.id === 'number') ids.add(current.id);
    });
}

/** Drop the skipped subtrees of a serialized tree, remembering their ids. Mutates. */
function pruneTree(node, skipped) {
    walk(node, (current) => {
        if (!Array.isArray(current.childNodes)) return;

        // Filtered before walk() pushes the children: a dropped subtree is never visited.
        current.childNodes = current.childNodes.filter((child) => {
            if (!carriesSkip(child)) return true;

            collectIds(child, skipped);

            return false;
        });
    });
}

/**
 * Take the elements carrying data-replay-skip out of an event. `skipped` is
 * the Set of rrweb ids left out so far on this page (cleared by a full
 * snapshot, which lists the page again): a mutation adding nodes into a
 * skipped element, or changing, typing into or removing one, is dropped too.
 * Returns false when nothing of the event is left. Mutates.
 */
export function skipNodes(event, skipped) {
    if (!event || !event.data) return true;

    if (event.type === EVENT_FULL_SNAPSHOT) {
        if (!event.data.node) return true;

        skipped.clear();
        pruneTree(event.data.node, skipped);

        return true;
    }

    if (event.type !== EVENT_INCREMENTAL) return true;

    const data = event.data;

    if (data.source !== SOURCE_MUTATION) {
        // Input, clicks, scrolls, media, selections: anything aimed at a skipped node goes.
        return !(typeof data.id === 'number' && skipped.has(data.id));
    }

    const adds = Array.isArray(data.adds) ? data.adds : [];

    if (skipped.size === 0 && !adds.some((add) => add && add.node && (carriesSkip(add.node) || hasSkipInside(add.node)))) return true;

    const before = adds.length + (data.removes?.length || 0) + (data.attributes?.length || 0) + (data.texts?.length || 0);

    // rrweb does not order adds parent first: repeat until no add lands in a newly skipped node.
    let grew = true;

    while (grew) {
        grew = false;

        for (const add of adds) {
            if (!add || !add.node || skipped.has(add.node.id)) continue;

            if (carriesSkip(add.node) || skipped.has(add.parentId)) {
                collectIds(add.node, skipped);
                grew = true;
            }
        }
    }

    data.adds = adds.filter((add) => {
        if (!add || !add.node) return true;
        if (skipped.has(add.node.id)) return false;

        pruneTree(add.node, skipped);

        // The replayer waits for a sibling that never comes: append instead.
        if (skipped.has(add.nextId)) add.nextId = null;
        if (skipped.has(add.previousId)) add.previousId = null;

        return true;
    });

    if (Array.isArray(data.removes)) data.removes = data.removes.filter((remove) => !skipped.has(remove.id) && !skipped.has(remove.parentId));
    if (Array.isArray(data.attributes)) data.attributes = data.attributes.filter((change) => !skipped.has(change.id));
    if (Array.isArray(data.texts)) data.texts = data.texts.filter((change) => !skipped.has(change.id));

    const after = data.adds.length + (data.removes?.length || 0) + (data.attributes?.length || 0) + (data.texts?.length || 0);

    return before === 0 || after > 0;
}

function hasSkipInside(node) {
    let found = false;

    walk(node, (current) => {
        if (carriesSkip(current)) found = true;
    });

    return found;
}

/** Pointer samples, which a live stream that falls behind drops first. */
export function isPointerSample(event) {
    return !!event && event.type === EVENT_INCREMENTAL && !!event.data && [SOURCE_MOUSE_MOVE, SOURCE_TOUCH_MOVE, SOURCE_DRAG].includes(event.data.source);
}
