import Player from 'rrweb-player';
import 'rrweb-player/dist/style.css';
import './player.css';
import { momentUrl, startOffset } from './lib/moment.js';
import { hasClass, livePoints, trailSegments } from './lib/pointer.js';
import { referencedAssets, restoreAssets, restoreSnapshots, sharedSnapshots, sortEvents } from './lib/process.js';

/**
 * The player: loads a recording's manifest, its chunks, the shared snapshots
 * and stylesheets they reference, and mounts rrweb-player with the markers
 * next to it. Mounts itself on every [data-session-replay-player] element; the element's
 * data-manifest is the manifest URL.
 *
 * Runs once per tab (the script tag has data-navigate-once): with Livewire's
 * wire:navigate it stops the players of the page it leaves and mounts those
 * of the page it arrives on.
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
    copy_link: 'Copy link to this moment',
    link_copied: 'Link copied',
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

// The players on the page, each with what stops it: one left listening keeps its recording in memory after
// wire:navigate swapped it out.
const mounted = new Map();

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
 * Returns what stops it.
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

    const observer = new MutationObserver((records) => {
        // rrweb removes and re-adds "active" for every click it plays.
        if (!mouse.classList.contains('active') || !records.some((record) => !hasClass(record.oldValue, 'active'))) return;

        const click = element('span', 'sr-click');

        click.style.left = mouse.style.left;
        click.style.top = mouse.style.top;
        replayer.wrapper.append(click);
        setTimeout(() => click.remove(), CLICK_MS);
    });

    observer.observe(mouse, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });

    return () => {
        observer.disconnect();

        if (frame !== null) cancelAnimationFrame(frame);
    };
}

/**
 * "Copy link to this moment": the page's URL with ?t= at the second the
 * replay is at, which opens the replay there for whoever may watch it.
 */
function copyLink(player, text) {
    const bar = element('div', 'sr-player__actions');
    const button = element('button', 'sr-chip sr-chip--action', text.copy_link);
    let reset = null;

    button.type = 'button';
    button.addEventListener('click', async () => {
        const url = momentUrl(location.href, player.getReplayer?.()?.getCurrentTime() ?? 0);

        try {
            await navigator.clipboard.writeText(url);
        } catch {
            // No clipboard outside a secure context (plain http): the link to copy by hand.
            window.prompt(text.copy_link, url);

            return;
        }

        button.textContent = text.link_copied;
        clearTimeout(reset);
        reset = setTimeout(() => (button.textContent = text.copy_link), 2000);
    });

    bar.append(button);

    return bar;
}

async function mount(root, options = {}) {
    const manifestUrl = options.manifestUrl || root.dataset.manifest;

    if (!manifestUrl || mounted.has(root)) return null;

    // Stopped while it was loading (the page was left): it never builds a player.
    const state = { stopped: false, stops: [] };

    mounted.set(root, state);
    root.classList.add('sr-player');

    const text = labels(root);
    const status = element('p', 'sr-player__status', text.loading);

    root.replaceChildren(status);

    let manifest;
    let events;

    try {
        manifest = await json(manifestUrl);
        events = state.stopped ? [] : await load(manifest, (done, total) => (status.textContent = `${text.loading} ${done}/${total}`));
    } catch (error) {
        status.textContent = error.status === 403 ? text.forbidden : text.failed;

        return null;
    }

    if (state.stopped) return null;

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

    state.stops.push(() => {
        player.pause?.();
        player.getReplayer?.()?.destroy?.();
        player.$destroy?.();
    });

    // Firefox swaps a new iframe's first document for another a moment after insertion, and rrweb only rebuilds
    // into the sandboxed document it registered at insertion. Opening that document cancels the swap.
    const frame = player.getReplayer?.()?.iframe;

    frame?.contentDocument?.open();
    frame?.contentDocument?.close();

    const stopPointer = pointer(player, root);

    if (stopPointer) state.stops.push(stopPointer);

    // Under the controller, in the replay's column.
    if (root.dataset.copyLink !== 'false') stage.append(copyLink(player, text));

    if (root.dataset.markers !== 'false') {
        root.append(markerList(manifest, startedAt, (offset) => player.goto(offset, true), text));
    }

    let resizing = null;

    const resize = () => {
        clearTimeout(resizing);
        resizing = setTimeout(() => {
            player.$set(size());
            player.triggerResize();
        }, 150);
    };

    window.addEventListener('resize', resize);
    state.stops.push(() => {
        clearTimeout(resizing);
        window.removeEventListener('resize', resize);
    });

    // ?t=83 opens the replay at 1:23; ?at=<epoch ms> (a log line's link) a moment before that time.
    const offset = startOffset(location.search, startedAt);

    if (offset > 0) player.goto(offset, false);

    root.dispatchEvent(new CustomEvent('session-replay:ready', { detail: { player, manifest }, bubbles: true }));

    return player;
}

/** Stops a player: paused, its listeners removed, its element emptied so it can be mounted again. */
function unmount(root) {
    const state = mounted.get(root);

    if (!state) return;

    mounted.delete(root);
    state.stopped = true;

    for (const stop of state.stops.splice(0).reverse()) {
        try {
            stop();
        } catch {
            // A player that is half gone is let go all the same.
        }
    }

    root.replaceChildren();
    root.classList.remove('sr-player');
}

function unmountAll() {
    [...mounted.keys()].forEach(unmount);
}

function mountAll() {
    // A player whose element left the page another way than wire:navigate.
    [...mounted.keys()].filter((root) => !root.isConnected).forEach(unmount);

    document.querySelectorAll('[data-session-replay-player]').forEach((root) => mount(root));
}

// Once per tab, even when a page loads this script a second time: every evaluation would add its listeners again.
if (!window.SessionReplayPlayer) {
    window.SessionReplayPlayer = { mount, mountAll, unmount };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', mountAll) : mountAll();

    // Panels that swap pages without a load (Livewire's wire:navigate): the page that goes takes its players with
    // it, the page that comes gets its own mounted.
    document.addEventListener('livewire:navigating', unmountAll);
    document.addEventListener('livewire:navigated', mountAll);
}
