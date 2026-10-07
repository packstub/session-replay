import { record } from '@rrweb/record';
import { getRecordConsolePlugin } from '@rrweb/rrweb-plugin-console-record';
import { onCLS, onINP, onLCP } from 'web-vitals';
import { dropHiddenValues, redactUrl, redactUrlAttributes, redactUrls } from './lib/privacy.js';
import { EVENT_FULL_SNAPSHOT, assetPlaceholder, attributeMatcher, referencedAssets, renameVolatileIds, shareSnapshot, sharedSnapshots, stripAttributes, styleSlots } from './lib/process.js';
import { ACTIVE_SOURCES, activeTime, checkoutInterval, estimateChars, isTrigger, trimWindow, windowStart } from './lib/buffer.js';
import { navigationPause } from './lib/navigation.js';
import { MAX_RETRIES, pendingFate, retryDelay } from './lib/pending.js';
import { ask, notice } from './ask.js';
import { deletePending, listPending, savePending } from './pending.js';

/**
 * The recorder. Reads window.__sessionReplay (written by @sessionReplay),
 * decides whether this browser session is recorded, and uploads rrweb events
 * in gzip batches with a small index next to each one (markers, counts, the
 * stylesheets the batch references), so the server never has to open a batch.
 *
 * In mode "on_error" a tab starts in the "buffering" phase: the last
 * onError.bufferMs stay in memory, nothing is uploaded, and the first marker
 * of a trigger type uploads that window (after asking, with onError.ask) and
 * moves the tab to "recording" for the rest of its session. "declined" means
 * the person said no; the tab is left alone until its session ends.
 *
 * An upload that fails is retried for a few minutes while the page stays
 * open. The window a trigger uploaded is also kept in IndexedDB until it gets
 * through (onError.keepPending): the server that is down is usually the one
 * whose failed request fired the trigger, and a reload must not lose the
 * replay of it. The next page load of the tab sends it first.
 */

const config = window.__sessionReplay;
const EVENT_META = 4;
const EVENT_INCREMENTAL = 3;
const EVENT_CUSTOM = 5;
const STORAGE_KEY = 'sr:session';
const FIRST_FLUSH_MS = 800;
const CONSENT_KEY = 'sr:consent';
const CONSOLE_PLUGIN = 'rrweb/console@1';
const KEEPALIVE_LIMIT = 60 * 1024;
const MAX_BUFFERED_EVENTS = 400;
const MAX_BUFFERED_CHARS = 1_500_000;
// What the rolling window may hold, by the same estimate as above; past it the window shrinks to the newest snapshot.
const MAX_WINDOW_CHARS = 5_000_000;

let session = null;
// Set while this tab records: stops everything. stopRrweb is rrweb's own, null while wire:navigate swaps the page.
let stopRecording = null;
let stopRrweb = null;
// Once the tab used wire:navigate its snapshots are numbered after the first page's, so they never match a shared one.
let navigatedAway = false;
const unshared = new WeakSet();
let stopped = false;
let buffer = [];
let bufferedChars = 0;
let markers = [];
let assets = new Set();
let pendingAssetText = new Map();
let hashCache = new Map();
let pendingAsync = 0;
let sending = Promise.resolve();
let flushTimer = null;
let flushInterval = config ? config.flushInterval : 5000;
let activeMs = 0;
let lastActiveAt = 0;
let lastError = { at: 0, label: '' };
let lastSnapshotAt = 0;
let checkoutPending = false;
let overWindow = false;
let asking = false;
/** What a failed request tells the question ({ status, message }), set around the mark() that may put it up. */
let askError = null;
// seq of the kept window batches (IndexedDB) of this recording, dropped when the tab stops.
let kept = new Set();

const onError = config?.mode === 'on_error';
const windowMs = onError ? config.onError.bufferMs : 0;
const checkoutMs = onError ? checkoutInterval(windowMs) : 0;
const keepPending = onError && !!config.onError.keepPending;

const matches = config ? attributeMatcher(config.size.stripAttributes, config.size.keepAttributes) : null;
const canHash = !!(window.crypto && window.crypto.subtle && window.TextEncoder);

function storage(kind) {
    try {
        return window[kind];
    } catch {
        return null;
    }
}

function read(kind, key) {
    try {
        return storage(kind)?.getItem(key) ?? null;
    } catch {
        return null;
    }
}

function write(kind, key, value) {
    try {
        storage(kind)?.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the recording still works for this page.
    }
}

function uuid() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();

    const bytes = new Uint8Array(16);

    (window.crypto || { getRandomValues: (array) => array.forEach((_, i) => (array[i] = Math.floor(Math.random() * 256))) }).getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** One recording per tab, continued across page loads until the tab sits idle for idleTimeout. */
function loadSession() {
    const now = Date.now();
    let state = null;

    try {
        state = JSON.parse(read('sessionStorage', STORAGE_KEY) || 'null');
    } catch {
        state = null;
    }

    // A tab that signed in, signed out or switched person since the last page starts a new recording:
    // the server keeps one person per recording and would refuse the rest of this one.
    if (!state || typeof state.id !== 'string' || now - state.lastActivity > config.idleTimeout || (state.identity ?? null) !== (config.identity ?? null)) {
        // The sampling decision is made once and kept with the session.
        state = { id: uuid(), seq: 0, lastActivity: now, sampled: Math.random() < config.sampleRate, identity: config.identity ?? null };
    }

    // A tab from before mode "on_error" was turned on keeps recording; one that was buffering when it was turned off records.
    if (!['buffering', 'recording', 'declined'].includes(state.phase)) state.phase = onError && state.seq === 0 ? 'buffering' : 'recording';
    if (!onError && state.phase === 'buffering') state.phase = 'recording';

    return state;
}

function saveSession() {
    if (!session) return;

    session.lastActivity = Date.now();
    write('sessionStorage', STORAGE_KEY, JSON.stringify(session));

    // While buffering the cookie is set too: the log line of the server error that triggers the upload names the recording.
    if (config.cookie && session.phase !== 'declined') {
        const secure = location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = `${config.cookie}=${session.id}; path=/; max-age=${Math.round(config.idleTimeout / 1000)}; SameSite=Lax${secure}`;
    }
}

function clearCookie() {
    if (config.cookie) document.cookie = `${config.cookie}=; path=/; max-age=0; SameSite=Lax`;
}

function hasConsent() {
    if (config.respectGpc && navigator.globalPrivacyControl) return false;

    return config.consent !== 'opt-in' || read('localStorage', CONSENT_KEY) === '1';
}

/** Add a marker to the index and, as a custom event, to the recording itself. */
function mark(type, label, payload = {}) {
    if (!stopRecording || stopped) return;

    const text = String(label ?? '').slice(0, 480) || type;

    markers.push({ type, label: text, payload, at: Date.now() });

    try {
        // While wire:navigate swaps the page rrweb is stopped; the event goes into the recording all the same.
        if (stopRrweb) record.addCustomEvent(`sr:${type}`, { label: text, ...payload });
        else emit({ type: EVENT_CUSTOM, data: { tag: `sr:${type}`, payload: { label: text, ...payload } }, timestamp: Date.now() });
    } catch {
        // Recording ended between the check and the call.
    }

    return isTrigger(type, config.onError?.triggers) ? trigger() : false;
}

async function sha256(text) {
    const cached = hashCache.get(text);

    if (cached) return cached;

    const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    const hash = [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    if (hashCache.size > 30) hashCache.clear();

    hashCache.set(text, hash);

    return hash;
}

/** Swap stylesheet text for its hash; the text is uploaded once, only if the server does not have it. */
async function dedupeStyles(event, slots) {
    for (const slot of slots) {
        const hash = await sha256(slot.text);

        slot.holder[slot.key] = assetPlaceholder(hash);
        assets.add(hash);

        if (!pendingAssetText.has(hash)) pendingAssetText.set(hash, slot.text);
    }

    return event;
}

function push(event) {
    buffer.push(event);
    bufferedChars += event.type === EVENT_FULL_SNAPSHOT ? 200_000 : 300;

    if (session?.phase === 'buffering') {
        holdWindow(event);

        return;
    }

    if (buffer.length >= MAX_BUFFERED_EVENTS || bufferedChars >= MAX_BUFFERED_CHARS) flush();
}

/**
 * Mode "on_error": a fresh snapshot every checkoutMs while something happens,
 * so the window can always start at one, and the window cut at a snapshot
 * when one arrives. While the person is being asked the window stops moving,
 * or the error could slide out of it; only the memory ceiling still applies.
 */
function holdWindow(event) {
    if (event.type === EVENT_FULL_SNAPSHOT) {
        lastSnapshotAt = Math.max(lastSnapshotAt, event.timestamp);

        if (!asking || overWindow) trimBuffer(overWindow ? Infinity : Date.now() - windowMs);

        overWindow = false;

        return;
    }

    if (bufferedChars >= MAX_WINDOW_CHARS) overWindow = true;

    // Incremental events only: the meta event of a snapshot still being hashed must not ask for another one.
    if (event.type === EVENT_INCREMENTAL && (overWindow || (lastSnapshotAt && event.timestamp - lastSnapshotAt > checkoutMs && !asking))) checkout();
}

function checkout() {
    if (checkoutPending) return;

    checkoutPending = true;

    // Not from inside rrweb's own emit call.
    setTimeout(() => {
        checkoutPending = false;

        try {
            if (stopRrweb && !stopped && session?.phase === 'buffering') {
                record.takeFullSnapshot(true);
                lastSnapshotAt = Date.now();
            }
        } catch {
            // Recording ended in between.
        }
    }, 0);
}

function trimBuffer(cutoff) {
    const kept = trimWindow(buffer, markers, windowStart(buffer, cutoff));

    buffer = kept.events;
    markers = kept.markers;
    bufferedChars = estimateChars(buffer);
    activeMs = activeTime(buffer);

    // Stylesheets of dropped snapshots: forget their text, unless a snapshot still being hashed may need it.
    if (pendingAsync === 0) {
        assets = new Set(referencedAssets(buffer));

        for (const hash of pendingAssetText.keys()) if (!assets.has(hash)) pendingAssetText.delete(hash);
    }

    saveSession();
}

/**
 * A trigger fired while buffering: upload the window and keep recording, after asking when onError.ask is on.
 * True when the question went up.
 */
function trigger() {
    if (!session || session.phase !== 'buffering' || asking || stopped) return false;

    trimBuffer(Date.now() - windowMs);

    if (!config.onError.ask) {
        share(false);

        return false;
    }

    asking = true;

    ask(config.onError.labels, { offerAnonymous: !!config.onError.offerAnonymous, error: askError })
        .then((answer) => {
            asking = false;

            if (stopped || !session || session.phase !== 'buffering') return;

            if (answer.share) {
                share(answer.anonymous);
                notice(config.onError.labels.sent);

                return;
            }

            // Declined: the window is dropped and this tab is not recorded again until its session ends.
            stop();
            session.phase = 'declined';
            saveSession();
        })
        .catch(() => {
            asking = false;
        });

    return true;
}

function share(anonymous) {
    session.phase = 'recording';
    session.anonymous = !!anonymous;
    saveSession();

    if (buffer.length) {
        const batch = takeBatch();

        // The window itself: kept in the browser when its upload fails, unlike the batches that follow.
        batch.window = keepPending;
        queue(batch);
    }

    schedule();
}

function trackActivity(event) {
    if (event.type !== 3 || !ACTIVE_SOURCES.has(event.data?.source)) return;

    // Gaps under five seconds count as one stretch of activity.
    if (lastActiveAt && event.timestamp - lastActiveAt < 5000) activeMs += event.timestamp - lastActiveAt;

    lastActiveAt = event.timestamp;
}

function consoleMarker(event) {
    if (event.type !== 6 || event.data?.plugin !== CONSOLE_PLUGIN || event.data.payload?.level !== 'error') return;

    const label = (event.data.payload.payload || []).join(' ').slice(0, 480);

    // An uncaught error is also logged by the console plugin; keep the "error" marker only.
    if (event.timestamp - lastError.at < 250 && label.includes(lastError.label.slice(0, 80))) return;

    markers.push({ type: 'console', label: label || 'console.error', payload: {}, at: event.timestamp });

    // After the event is in the buffer: emit() pushes it right after this returns.
    if (isTrigger('console', config.onError?.triggers)) setTimeout(trigger, 0);
}

function emit(event) {
    if (stopped) return;

    trackActivity(event);
    consoleMarker(event);
    stripAttributes(event, matches);
    dropHiddenValues(event);
    redactUrlAttributes(event, config.privacy.redactQuery, location.href);

    if (event.type === EVENT_META && event.data && event.data.href) event.data.href = redacted(event.data.href);
    if (event.type === EVENT_FULL_SNAPSHOT && navigatedAway) unshared.add(event);

    const slots = config.size.dedupeStylesheets && canHash ? styleSlots(event, config.size.dedupeMinBytes) : [];

    if (slots.length === 0) {
        push(event);

        return;
    }

    // Hashing is asynchronous; the player sorts by timestamp, so arriving a little late is fine.
    pendingAsync++;
    dedupeStyles(event, slots)
        .catch(() => event)
        .then((processed) => {
            pendingAsync--;
            push(processed);
        });
}

async function gzip(text) {
    if (typeof CompressionStream === 'undefined') return new Blob([text], { type: 'application/json' });

    const stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'));

    return new Blob([await new Response(stream).arrayBuffer()], { type: 'application/gzip' });
}

function takeBatch() {
    const batch = {
        seq: session.seq++,
        events: buffer,
        meta: {
            // The page the batch starts on, which for a window uploaded on error may be an earlier one (wire:navigate).
            url: buffer.find((event) => event.type === EVENT_META)?.data?.href || redacted(location.href),
            viewport: { width: window.innerWidth, height: window.innerHeight },
            from: buffer[0].timestamp,
            to: buffer[buffer.length - 1].timestamp,
            events: buffer.length,
            activeMs: Math.round(activeMs),
            markers,
            assets: [...assets],
        },
        anonymous: !!session.anonymous,
        tries: 0,
        blob: null,
        window: false,
        kept: false,
    };

    buffer = [];
    bufferedChars = 0;
    markers = [];
    assets = new Set();
    activeMs = 0;
    saveSession();

    return batch;
}

function form(batch, blob) {
    const data = new FormData();

    data.append('token', config.token);
    data.append('session', session.id);
    data.append('seq', String(batch.seq));

    // The person chose to send this recording without their name: the server drops who they are from the token.
    if (batch.anonymous) data.append('anonymous', '1');
    data.append('meta', JSON.stringify(batch.meta));
    data.append('events', blob, 'events');

    return data;
}

async function uploadAssets(hashes) {
    for (const hash of hashes) {
        const text = pendingAssetText.get(hash);

        if (text === undefined) continue;

        const data = new FormData();

        data.append('token', config.token);
        data.append('hash', hash);
        data.append('content', await gzip(text), 'content');

        try {
            await fetch(config.assetUrl, { method: 'POST', body: data });
        } catch {
            // The next recording that references this stylesheet uploads it; the hash stays valid.
        }
    }
}

const SHARED_KEY = 'sr:shared';
const SHARED_TTL = 60 * 60 * 1000;

/** Hashes this tab uploaded in the last hour (the server keeps a snapshot for a day after it was last sent). */
function uploadedSnapshots() {
    try {
        const now = Date.now();

        return Object.fromEntries(Object.entries(JSON.parse(read('sessionStorage', SHARED_KEY) || '{}')).filter(([, at]) => now - at < SHARED_TTL));
    } catch {
        return {};
    }
}

/**
 * On a page the app shares snapshots for: store the page's tree once per hash
 * and keep only the hash in the batch. A tree is swapped only once the server
 * has it; when the upload fails the batch carries it as before. The final
 * request of a closing page never comes here, so it always carries its tree.
 */
async function shareSnapshots(batch) {
    for (const event of batch.events) {
        if (event.type !== EVENT_FULL_SNAPSHOT || !event.data || !event.data.node || unshared.has(event)) continue;

        try {
            renameVolatileIds(event.data.node, config.snapshots.volatileIds);

            const text = JSON.stringify(event.data.node);
            const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
            const hash = [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
            const uploaded = uploadedSnapshots();

            if (!uploaded[hash]) {
                const data = new FormData();

                data.append('token', config.token);
                data.append('hash', hash);
                data.append('content', await gzip(text), 'content');

                // The answer is the same whether the server had it or not; only a failure keeps the tree inline.
                if (!(await fetch(config.snapshots.url, { method: 'POST', body: data })).ok) continue;

                uploaded[hash] = Date.now();
                write('sessionStorage', SHARED_KEY, JSON.stringify(Object.fromEntries(Object.entries(uploaded).slice(-50))));
            }

            shareSnapshot(event, hash);
        } catch {
            // Offline or no crypto.subtle: the snapshot stays in the batch.
        }
    }

    batch.meta.snapshots = sharedSnapshots(batch.events);
}

async function send(batch) {
    // Before the gzip is made and cached: a retry or a batch kept from an earlier page keeps what it has.
    if (config.snapshots && config.snapshots.share && canHash && !batch.blob) await shareSnapshots(batch);

    const blob = batch.blob || (batch.blob = await gzip(JSON.stringify(batch.events)));

    if (blob.size > config.maxBatchBytes && batch.events.length > 1) {
        // Too big for one upload: split and send the halves in order.
        const middle = Math.ceil(batch.events.length / 2);
        const tail = { ...batch, seq: session.seq++, events: batch.events.slice(middle), meta: { ...batch.meta, markers: [], assets: [], activeMs: 0 }, blob: null };

        batch.blob = null;
        batch.events = batch.events.slice(0, middle);
        batch.meta.events = batch.events.length;
        batch.meta.to = batch.events[batch.events.length - 1].timestamp;
        tail.meta.events = tail.events.length;
        tail.meta.from = tail.events[0].timestamp;
        saveSession();

        await send(batch);
        await send(tail);

        return;
    }

    let response;

    try {
        response = await fetch(config.ingestUrl, { method: 'POST', body: form(batch, blob) });
    } catch {
        return failed(batch);
    }

    if (response.status === 429 || response.status >= 500) {
        if (response.status === 429) flushInterval = Math.min(flushInterval * 2, 60_000);

        return failed(batch);
    }

    // The server answered: accepted, refused or asking to stop. Either way the kept copy has done its job.
    if (batch.kept) settle(batch);

    let body = {};

    try {
        body = await response.json();
    } catch {
        body = {};
    }

    if (body.stop) {
        stop();

        return;
    }

    if (response.ok) {
        await uploadAssets(body.missing_assets || []);

        for (const hash of batch.meta.assets) pendingAssetText.delete(hash);
    }
}

/** The server could not take the batch: the window goes to IndexedDB the first time, then the batch is retried. */
async function failed(batch) {
    if (batch.window && !batch.kept && !stopped) {
        batch.kept = true;
        kept.add(batch.seq);

        // The stylesheets the window references and the server may not have yet: the next page cannot hash them again.
        const assetText = {};

        for (const hash of batch.meta.assets) if (pendingAssetText.has(hash)) assetText[hash] = pendingAssetText.get(hash);

        await savePending(session.id, batch, batch.blob, assetText, config.identity);
    }

    return retry(batch);
}

/** The kept copy of a batch is no longer needed. */
function settle(batch) {
    batch.kept = false;
    kept.delete(batch.seq);
    deletePending(session.id, batch.seq);
}

function retry(batch) {
    if (++batch.tries > MAX_RETRIES || stopped) return;

    return wait(retryDelay(batch.tries)).then(() => (stopped ? undefined : send(batch)));
}

/** Waits `ms`, or less when the browser says it is back online. */
function wait(ms) {
    return new Promise((resolve) => {
        const done = () => {
            clearTimeout(timer);
            window.removeEventListener('online', done);
            resolve();
        };
        const timer = setTimeout(done, ms);

        window.addEventListener('online', done);
    });
}

function queue(batch) {
    sending = sending.then(() => send(batch)).catch(() => {});

    return sending;
}

function flush() {
    if (stopped || !session || session.phase !== 'recording' || buffer.length === 0) return sending;

    return queue(takeBatch());
}

/**
 * A page load in a tab whose window did not get through: send what is kept
 * before anything this page records, so the recording stays in order. What
 * is too old or belongs to someone else is dropped; another tab's is left
 * for that tab.
 */
async function resumePending() {
    const own = [];

    for (const record of await listPending()) {
        const fate = pendingFate(record, { sessionId: session.id, identity: config.identity, now: Date.now(), idleTimeout: config.idleTimeout });

        if (fate === 'drop') deletePending(record.session, record.seq);
        if (fate === 'send') own.push(record);
    }

    own.sort((a, b) => a.seq - b.seq);

    for (const record of own) {
        if (stopped) return;

        kept.add(record.seq);

        for (const [hash, text] of Object.entries(record.assets || {})) if (!pendingAssetText.has(hash)) pendingAssetText.set(hash, text);

        await send({ seq: record.seq, events: [], meta: record.meta, anonymous: !!record.anonymous, tries: 0, blob: record.blob, window: true, kept: true });
    }
}

/** The page is going away: no time for compression or retries, one keepalive request with what is left. */
function flushOnUnload() {
    if (stopped || !session || session.phase !== 'recording' || buffer.length === 0) return;

    const batch = takeBatch();
    const blob = new Blob([JSON.stringify(batch.events)], { type: 'application/json' });

    try {
        fetch(config.ingestUrl, { method: 'POST', body: form(batch, blob), keepalive: blob.size < KEEPALIVE_LIMIT });
    } catch {
        // Nothing left to do on a closing page.
    }
}

function schedule() {
    clearTimeout(flushTimer);

    flushTimer = setTimeout(() => {
        flush();
        schedule();
    }, flushInterval);
}

/** The URL without the values of the query parameters privacy.redact_query lists. */
function redacted(url) {
    return redactUrl(url, config.privacy.redactQuery, location.href);
}

function describe(element) {
    if (!(element instanceof Element)) return 'unknown';

    // A click lands on the label or the icon inside a control; the control is what was clicked.
    element = element.closest('button, a, [role="button"], summary, label, input, select, textarea') || element;

    // Inside a blocked region the replay shows an empty box; its captions stay out of the marker list too.
    if (config.privacy.blockSelector && element.closest(config.privacy.blockSelector)) return element.tagName.toLowerCase();

    const masked = config.privacy.maskAllText || (config.privacy.maskTextSelector && element.closest(config.privacy.maskTextSelector));
    const tag = element.tagName.toLowerCase();

    // What the author called it reads better in a list than utility classes: alt, aria-label, title, and a
    // button's own caption. Never the text of anything else (a link or a cell may hold a name).
    let name = (element.getAttribute('alt') || element.getAttribute('aria-label') || element.getAttribute('title') || '').trim();

    if (!name && (tag === 'button' || element.getAttribute('role') === 'button')) name = (element.textContent || '').replace(/\s+/g, ' ').trim();

    if (name && !masked) return `${tag} "${name.slice(0, 60)}"`;

    const id = element.id ? `#${element.id}` : '';
    const classes = typeof element.className === 'string' && element.className ? '.' + element.className.trim().split(/\s+/).slice(0, 2).join('.') : '';

    return `${tag}${id}${classes}`.slice(0, 120);
}

let lastPage = null;

/** One "navigation" marker per page, however many signals announce it. */
function markPage() {
    if (lastPage === location.href) return;

    lastPage = location.href;

    const page = new URL(redacted(location.href));

    mark('navigation', page.pathname + page.search, { url: page.href, title: document.title.slice(0, 200) });
}

function watchErrors() {
    window.addEventListener('error', (event) => {
        // Resource load failures bubble here without a message; they are not script errors.
        if (!event.message) return;

        lastError = { at: Date.now(), label: event.message };
        mark('error', redactUrls(event.message, config.privacy.redactQuery), {
            source: redacted(event.filename),
            line: event.lineno,
            column: event.colno,
            stack: redactUrls(String(event.error?.stack || ''), config.privacy.redactQuery).slice(0, 2000),
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const label = String(event.reason?.message || event.reason || 'Unhandled promise rejection');

        lastError = { at: Date.now(), label };
        mark('error', label, { stack: String(event.reason?.stack || '').slice(0, 2000) });
    });
}

/**
 * The title of an error page: Laravel's production pages carry the short message there ("Server Error", "Not Found").
 * The debug page's title is the app's name, which says nothing about the error.
 */
function errorTitle(html) {
    if (typeof html !== 'string' || !html) return null;

    try {
        const title = new DOMParser().parseFromString(html.slice(0, 20000), 'text/html').title.trim().replace(/\s+/g, ' ');

        if (!title || title === String(config.appName || '').trim()) return null;

        return title.slice(0, 120);
    } catch {
        return null;
    }
}

function watchLivewire() {
    const hook = () => {
        try {
            window.Livewire.hook('request', ({ url, fail }) => {
                fail(({ status, content, preventDefault }) => {
                    askError = { status: Number(status) || null, message: errorTitle(content) };

                    const asked = mark('request', `Livewire request failed (${status})`, { status, url: String(url || '').slice(0, 500) });

                    askError = null;

                    // The question in place of Livewire's modal with the error page (on_error.livewire_error_modal).
                    // An expired page (419) keeps Livewire's offer to reload: the question would have nothing to send to.
                    if (asked && config.onError?.replaceLivewireModal && status !== 419) preventDefault?.();
                });
            });
        } catch {
            // A Livewire without the request hook: nothing to watch.
        }
    };

    window.Livewire ? hook() : document.addEventListener('livewire:init', hook, { once: true });

    // wire:navigate swaps the page without a load; the recording restarted with a snapshot of it (see
    // watchNavigation, registered first), the index gets the page. Livewire also fires it on the first load,
    // which start() already marked.
    document.addEventListener('livewire:navigated', () => markPage());
}

function watchRageClicks() {
    let clicks = [];
    let quietUntil = 0;

    document.addEventListener(
        'click',
        (event) => {
            // Keyboard and scripted clicks carry no position; three presses of Enter are not rage.
            if (event.detail === 0) return;

            const now = Date.now();

            clicks = clicks.filter((click) => now - click.at < 700);
            clicks.push({ at: now, x: event.clientX, y: event.clientY });

            const near = clicks.filter((click) => Math.hypot(click.x - event.clientX, click.y - event.clientY) < 30);

            if (near.length >= 3 && now > quietUntil) {
                quietUntil = now + 2000;
                clicks = [];
                mark('rage-click', describe(event.target), { x: event.clientX, y: event.clientY });
            }
        },
        true,
    );
}

function watchVitals() {
    const report = (metric) => mark('vital', `${metric.name} ${metric.name === 'CLS' ? metric.value.toFixed(3) : Math.round(metric.value) + ' ms'}`, { name: metric.name, value: metric.value, rating: metric.rating });

    onLCP(report);
    onINP(report);
    onCLS(report);
}

function recordOptions() {
    return {
        emit,
        plugins: config.capture.console.length ? [getRecordConsolePlugin({ level: config.capture.console, lengthThreshold: 1000, logger: window.console })] : [],
        maskAllInputs: config.privacy.maskAllInputs,
        maskInputOptions: { password: true },
        maskTextSelector: config.privacy.maskAllText ? '*' : config.privacy.maskTextSelector || undefined,
        blockSelector: config.privacy.blockSelector || undefined,
        ignoreSelector: config.privacy.ignoreSelector || undefined,
        slimDOMOptions: 'all',
        inlineStylesheet: true,
        recordCanvas: false,
        collectFonts: false,
        sampling: config.size.sampling,
    };
}

/**
 * wire:navigate replaces the whole <body>. Recorded as it happens, that is one
 * mutation adding every node of the new page, larger than a snapshot of it and
 * slower to make. So rrweb stops before the swap and starts again after it:
 * the new page arrives as a full snapshot, like a page load, and the player
 * can seek from it. The session, the buffer and the markers go on.
 */
const navigation = navigationPause({
    pause() {
        try {
            stopRrweb?.();
        } catch {
            // Already stopped.
        }

        stopRrweb = null;
        navigatedAway = true;
    },
    resume() {
        if (stopped || !stopRecording) return;

        try {
            stopRrweb = record(recordOptions()) || null;
        } catch {
            stopRrweb = null;
        }

        // Like the first snapshot of a page load: up early, or a tab closed within the flush interval loses it.
        if (stopRrweb && session?.phase === 'recording') {
            setTimeout(() => {
                if (!stopped && buffer.length) flush();
            }, FIRST_FLUSH_MS);
        }
    },
});

function watchNavigation() {
    document.addEventListener('livewire:navigating', () => navigation.navigating(!!stopRrweb && !stopped));
    document.addEventListener('livewire:navigated', () => navigation.navigated());
}

function start() {
    if (stopRecording || !config) return false;

    if (!hasConsent()) return false;

    session = loadSession();
    saveSession();

    if (!session.sampled || session.phase === 'declined') return false;

    stopped = false;
    lastSnapshotAt = 0;

    stopRrweb = record(recordOptions()) || null;

    if (!stopRrweb) return false;

    stopRecording = () => {
        const stopNow = stopRrweb;

        stopRrweb = null;
        stopNow?.();
    };

    markPage();

    if (session.phase === 'buffering') return true;

    if (keepPending) sending = sending.then(resumePending).catch(() => {});

    schedule();

    // The page snapshot goes up early and compressed: left to the unload request it is too large for keepalive
    // and a page left within the first interval would be missing from the replay.
    setTimeout(() => {
        if (!stopped && buffer.length) flush();
    }, FIRST_FLUSH_MS);

    return true;
}

function stop() {
    stopped = true;
    clearTimeout(flushTimer);
    navigation.stop();

    try {
        stopRecording?.();
    } catch {
        // Already stopped.
    }

    stopRecording = null;
    buffer = [];
    bufferedChars = 0;
    markers = [];
    clearCookie();

    // Stopped for good (consent withdrawn, the server said so, the person declined): nothing stays in the browser.
    for (const seq of kept) deletePending(session.id, seq);
    kept = new Set();
}

if (config && !window.SessionReplay) {
    // Before watchLivewire: the recording restarts on livewire:navigated before the page is marked.
    watchNavigation();

    // Vitals first: their page-hide callbacks must run before the final flush below.
    if (config.capture.vitals) watchVitals();
    if (config.capture.errors) watchErrors();
    if (config.capture.livewire) watchLivewire();
    if (config.capture.rageClicks) watchRageClicks();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') pendingAsync === 0 ? flushOnUnload() : flush();
    });
    window.addEventListener('pagehide', flushOnUnload);

    window.SessionReplay = {
        start,
        stop,
        flush,
        /** Record a moment of your own: SessionReplay.mark('Checkout started', { cart: 3 }). */
        mark: (label, payload = {}) => mark('custom', label, payload),
        /** consent(true) remembers the answer and starts; consent(false) forgets it and stops. */
        consent(given) {
            write('localStorage', CONSENT_KEY, given ? '1' : '0');

            return given ? start() : (stop(), false);
        },
        isRecording: () => !!stopRecording && !stopped && session?.phase === 'recording',
        /** Mode "on_error": keeping the last moments in the browser, waiting for a trigger. */
        isBuffering: () => !!stopRecording && !stopped && session?.phase === 'buffering',
        sessionId: () => (stopRecording && session ? session.id : null),
    };

    start();
}
