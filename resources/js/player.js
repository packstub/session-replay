import Player from 'rrweb-player';
import 'rrweb-player/dist/style.css';
import './player.css';
import { hasClass, livePoints, trailSegments } from './lib/pointer.js';
import { referencedAssets, restoreAssets, restoreSnapshots, sharedSnapshots, sortEvents } from './lib/process.js';

/**
 * The player: loads a recording's manifest, its chunks, the shared snapshots
 * and stylesheets they reference, and mounts rrweb-player with the markers
 * next to it. Mounts itself on every [data-session-replay-player] element; the element's
 * data-manifest is the manifest URL.
 */

const COLORS = {
    error: '#ef4444',
    request: '#f97316',
    console: '#f59e0b',
    'rage-click': '#a855f7',
    navigation: '#3b82f6',
    vital: '#10b981',
    custom: '#64748b',
};

// English, unless the element carries data-labels (the Blade component passes the app's language).
const TEXT = {
    loading: 'Loading the recording…',
    forbidden: 'You are not allowed to watch this recording.',
    failed: 'The recording could not be loaded.',
    just_started: 'This recording just started; there is nothing to play yet.',
    no_snapshot: 'This recording has no page snapshot to play.',
    none_selected: 'Nothing of the selected kinds.',
    none_marked: 'Nothing was marked in this recording.',
    types: {
        error: 'Error',
        request: 'Request',
        console: 'Console',
        'rage-click': 'Rage click',
        navigation: 'Page',
        vital: 'Vital',
        custom: 'Custom',
    },
};

function labels(root) {
    let given = {};

    try {
        given = JSON.parse(root.dataset.labels || '{}') || {};
    } catch {
        // Unreadable labels leave the English ones.
    }

    return { ...TEXT, ...given, types: { ...TEXT.types, ...(given.types || {}) } };
}

function element(tag, className, text) {
    const node = document.createElement(tag);

    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

function clock(milliseconds) {
    const seconds = Math.max(0, Math.floor(milliseconds / 1000));
    const pad = (value) => String(value).padStart(2, '0');

    return seconds >= 3600 ? `${Math.floor(seconds / 3600)}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}` : `${Math.floor(seconds / 60)}:${pad(seconds % 60)}`;
}

async function json(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (!response.ok) throw Object.assign(new Error(`HTTP ${response.status}`), { status: response.status });

    return response.json();
}

async function inBatches(items, size, task) {
    const results = new Array(items.length);

    for (let index = 0; index < items.length; index += size) {
        await Promise.all(items.slice(index, index + size).map(async (item, offset) => (results[index + offset] = await task(item))));
    }

    return results;
}

async function load(manifest, progress) {
    let done = 0;

    const chunks = await inBatches(manifest.chunks, 4, async (chunk) => {
        let events = [];

        try {
            events = await json(chunk.url);
        } catch {
            // A chunk that is missing or unreadable leaves a gap; the rest still plays.
        }

        progress(++done, manifest.chunks.length);

        return Array.isArray(events) ? events : [];
    });

    const events = sortEvents(chunks.flat().filter((event) => event && typeof event.timestamp === 'number'));
    const snapshots = new Map();

    // Shared snapshots first: the stylesheets they reference are only known once their trees are back.
    await inBatches(manifest.snapshotUrl ? sharedSnapshots(events) : [], 4, async (hash) => {
        try {
            const response = await fetch(manifest.snapshotUrl.replace('__hash__', hash), { credentials: 'same-origin' });

            if (response.ok) snapshots.set(hash, await response.text());
        } catch {
            // Without its tree the page replays empty rather than not at all.
        }
    });

    restoreSnapshots(events, snapshots);

    const texts = new Map();

    await inBatches(referencedAssets(events), 4, async (hash) => {
        try {
            const response = await fetch(manifest.assetUrl.replace('__hash__', hash), { credentials: 'same-origin' });

            if (response.ok) texts.set(hash, await response.text());
        } catch {
            // Without its stylesheet a page replays unstyled rather than not at all.
        }
    });

    return restoreAssets(events, texts);
}

function markerList(manifest, startedAt, seek, text) {
    const panel = element('aside', 'sr-markers');
    const filters = element('div', 'sr-markers__filters');
    const list = element('ol', 'sr-markers__list');
    const hidden = new Set(['vital']);
    const types = [...new Set(manifest.markers.map((marker) => marker.type))];

    const render = () => {
        list.replaceChildren();

        const visible = manifest.markers.filter((marker) => !hidden.has(marker.type));

        if (visible.length === 0) {
            list.append(element('li', 'sr-markers__empty', manifest.markers.length ? text.none_selected : text.none_marked));

            return;
        }

        for (const marker of visible) {
            const offset = Math.max(0, marker.at - startedAt);
            const button = element('button', 'sr-marker');
            const dot = element('span', 'sr-marker__dot');

            button.type = 'button';
            dot.style.background = COLORS[marker.type] || COLORS.custom;
            button.append(dot, element('span', 'sr-marker__time', clock(offset)), element('span', 'sr-marker__label', marker.label));
            button.title = `${text.types[marker.type] || marker.type}: ${marker.label}`;
            // A second of lead-in, so the moment itself is seen happening.
            button.addEventListener('click', () => seek(Math.max(0, offset - 1000)));

            const item = element('li');

            item.append(button);
            list.append(item);
        }
    };

    for (const type of types) {
        const chip = element('button', 'sr-chip', `${text.types[type] || type} ${manifest.markers.filter((marker) => marker.type === type).length}`);

        chip.type = 'button';
        chip.style.setProperty('--sr-chip', COLORS[type] || COLORS.custom);
        chip.setAttribute('aria-pressed', String(!hidden.has(type)));
        chip.addEventListener('click', () => {
            hidden.has(type) ? hidden.delete(type) : hidden.add(type);
            chip.setAttribute('aria-pressed', String(!hidden.has(type)));
            render();
        });
        filters.append(chip);
    }

    render();
    panel.append(filters, list);

    return panel;
}

// How long (replay time) a stretch of the mouse trail stays, and how thick it is in recorded pixels.
const TRAIL_MS = 1500;
const TRAIL_WIDTH = 5;
const CLICK_MS = 1600;

/**
 * Draws where the mouse went and where it clicked, on top of rrweb's replayer:
 * a trail that fades along its length, and a ripple with a lingering dot on
 * every click. rrweb calls drawMouseTail() and marks .replayer-mouse active
 * only while playing, never while fast-forwarding to a seek, so neither shows
 * up for moments that were skipped. The colour is --sr-pointer on the player.
 */
function pointer(player, root) {
    const replayer = player.getReplayer?.();

    // Another rrweb build: keep its own trail and click pulse.
    if (!replayer || typeof replayer.drawMouseTail !== 'function' || !replayer.mouse || !replayer.wrapper) return;

    const color = () => getComputedStyle(root).getPropertyValue('--sr-pointer').trim() || '#4950f6';
    let points = [];
    let frame = null;

    const draw = () => {
        frame = null;

        const canvas = replayer.mouseTail;
        const context = canvas?.getContext('2d');

        if (!context) return;

        const now = replayer.getCurrentTime();

        points = livePoints(points, now, TRAIL_MS);
        context.clearRect(0, 0, canvas.width, canvas.height);
        // Butt ends: round ones overlap at every joint and show as beads along a fading line.
        context.lineCap = 'butt';
        context.lineJoin = 'round';
        context.lineWidth = TRAIL_WIDTH;
        context.strokeStyle = color();

        for (const { from, to, alpha } of trailSegments(points, now, TRAIL_MS)) {
            context.globalAlpha = alpha * 0.75;
            context.beginPath();
            context.moveTo(from.x, from.y);
            context.lineTo(to.x, to.y);
            context.stroke();
        }

        context.globalAlpha = 1;
    };

    const redraw = () => {
        if (frame === null) frame = requestAnimationFrame(draw);
    };

    replayer.drawMouseTail = ({ x, y }) => {
        points.push({ x, y, t: replayer.getCurrentTime() });
        redraw();
    };

    // The player reports its time on every frame while playing and once after a seek.
    player.addEventListener('ui-update-current-time', () => points.length && redraw());

    const mouse = replayer.mouse;

    new MutationObserver((records) => {
        // rrweb removes and re-adds "active" for every click it plays.
        if (!mouse.classList.contains('active') || !records.some((record) => !hasClass(record.oldValue, 'active'))) return;

        const click = element('span', 'sr-click');

        click.style.left = mouse.style.left;
        click.style.top = mouse.style.top;
        replayer.wrapper.append(click);
        setTimeout(() => click.remove(), CLICK_MS);
    }).observe(mouse, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });
}

async function mount(root, options = {}) {
    const manifestUrl = options.manifestUrl || root.dataset.manifest;

    if (!manifestUrl || root.dataset.srMounted) return null;

    root.dataset.srMounted = '1';
    root.classList.add('sr-player');

    const text = labels(root);
    const status = element('p', 'sr-player__status', text.loading);

    root.replaceChildren(status);

    let manifest;
    let events;

    try {
        manifest = await json(manifestUrl);
        events = await load(manifest, (done, total) => (status.textContent = `${text.loading} ${done}/${total}`));
    } catch (error) {
        status.textContent = error.status === 403 ? text.forbidden : text.failed;

        return null;
    }

    if (events.length < 2 || !events.some((event) => event.type === 2)) {
        status.textContent = manifest.live ? text.just_started : text.no_snapshot;

        return null;
    }

    const stage = element('div', 'sr-player__stage');
    const startedAt = events[0].timestamp;
    const size = () => {
        const width = Math.max(320, stage.clientWidth);

        // 16:10 unless the window is too short; the controller takes 80px below the frame.
        return { width, height: Math.max(240, Math.min(Math.round(width / 1.6), window.innerHeight - 220)) };
    };

    root.replaceChildren(stage);

    const player = new Player({
        target: stage,
        props: {
            events,
            ...size(),
            autoPlay: options.autoPlay ?? false,
            skipInactive: true,
            showController: true,
            speedOption: [1, 2, 4, 8],
            tags: Object.fromEntries(Object.entries(COLORS).map(([type, color]) => [`sr:${type}`, color])),
        },
    });

    // Firefox swaps a new iframe's first document for another a moment after insertion, and rrweb only rebuilds
    // into the sandboxed document it registered at insertion. Opening that document cancels the swap.
    const frame = player.getReplayer?.()?.iframe;

    frame?.contentDocument?.open();
    frame?.contentDocument?.close();

    pointer(player, root);

    if (root.dataset.markers !== 'false') {
        root.append(markerList(manifest, startedAt, (offset) => player.goto(offset, true), text));
    }

    let resizing = null;

    window.addEventListener('resize', () => {
        clearTimeout(resizing);
        resizing = setTimeout(() => {
            player.$set(size());
            player.triggerResize();
        }, 150);
    });

    // ?t=83 opens the replay at 1:23.
    const at = Number(new URLSearchParams(location.search).get('t'));

    if (at > 0) player.goto(at * 1000, false);

    root.dispatchEvent(new CustomEvent('session-replay:ready', { detail: { player, manifest }, bubbles: true }));

    return player;
}

function mountAll() {
    document.querySelectorAll('[data-session-replay-player]').forEach((root) => mount(root));
}

window.SessionReplayPlayer = { mount, mountAll };

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', mountAll) : mountAll();

// Panels that swap pages without a load (Livewire's wire:navigate) get their players mounted too.
document.addEventListener('livewire:navigated', mountAll);
