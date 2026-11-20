/**
 * Uploads that did not get through. Pure functions over plain objects, so
 * they run in Node for the tests; the IndexedDB side lives in ../pending.js.
 *
 * A failed upload is retried while the page stays open, with a backoff that
 * covers a few minutes of a server being down. In mode "on_error" the window
 * a trigger uploaded is also kept in the browser (IndexedDB) until it gets
 * through, so a page reload during the outage does not lose it: the next
 * page load of that tab sends it first.
 */

/** Retries after a failed upload: about 2, 4, 8, 16, 32 s, then about once a minute, four minutes in all on average. */
export const MAX_RETRIES = 8;

/**
 * Milliseconds to wait before retry number `tries` (1-based): the step of the
 * schedule above, jittered to anywhere from half of it to one and a half
 * times it. Tabs that failed together (a server outage fails every tab at the
 * same moment) then come back one by one instead of together, while the
 * schedule as a whole lasts as long as before, on average. `random` is
 * Math.random, or a fake in the tests.
 */
export function retryDelay(tries, random = Math.random) {
    const step = Math.min(60_000, 1000 * 2 ** Math.max(1, tries));

    return Math.round(step * (0.5 + clamp01(random())));
}

/** The spread before the first upload of a window an outage triggered: 30 to 120 s. */
export const SPREAD_MIN_MS = 30_000;
export const SPREAD_MAX_MS = 120_000;

/**
 * Whether a failed request's status says the server (or the proxy in front of
 * it) was away rather than the app failing: no answer at all, 502, 503 or 504.
 * Every open tab sees such a failure at the same moment, so the window it
 * triggers waits spreadDelay() before it goes up; an app error (500) is one
 * person's and goes up at once.
 */
export function isOutage(status) {
    const code = Number(status) || 0;

    return code === 0 || code === 502 || code === 503 || code === 504;
}

/** Milliseconds a window an outage triggered waits before its first upload. */
export function spreadDelay(random = Math.random) {
    return Math.round(SPREAD_MIN_MS + (SPREAD_MAX_MS - SPREAD_MIN_MS) * clamp01(random()));
}

/**
 * The longest pause a Retry-After answer gets, however long the server asks
 * for: a tab paused longer than this has likely been closed or gone idle.
 */
export const RETRY_AFTER_MAX_MS = 10 * 60_000;

/** Pauses on Retry-After one batch may take before it is given up on; they do not count as retries. */
export const MAX_PAUSES = 10;

/**
 * Milliseconds to pause every upload of the tab for, from a 503 or 429
 * answer's Retry-After header (seconds, or an HTTP date), or null when there
 * is none worth reading. At least a second, at most RETRY_AFTER_MAX_MS, and up
 * to a fifth longer at random, so tabs told the same number do not all come
 * back on the same second.
 */
export function retryAfterDelay(header, now = Date.now(), random = Math.random) {
    if (header === null || header === undefined) return null;

    const value = String(header).trim();

    if (value === '') return null;

    let ms;

    if (/^\d+$/.test(value)) {
        ms = Number(value) * 1000;
    } else {
        // An HTTP date names its weekday and month; Date.parse alone would also take "-5" or "1.5" for a year.
        const at = /[a-z]{3}/i.test(value) ? Date.parse(value) : Number.NaN;

        if (Number.isNaN(at)) return null;

        ms = at - now;
    }

    ms = Math.min(RETRY_AFTER_MAX_MS, Math.max(1000, ms));

    return Math.min(RETRY_AFTER_MAX_MS, Math.round(ms * (1 + 0.2 * clamp01(random()))));
}

/**
 * Milliseconds left until `until` (a timestamp kept in the browser: the end of
 * a window's spread or of a pause), never below 0 and never above `max`, so a
 * clock that jumped back cannot hold uploads for longer than it was meant to.
 */
export function timeLeft(until, now, max) {
    const ms = Number(until) - now;

    return Number.isFinite(ms) ? Math.min(max, Math.max(0, ms)) : 0;
}

function clamp01(value) {
    const number = Number(value);

    return Number.isFinite(number) ? Math.min(1, Math.max(0, number)) : 0.5;
}

/** The key of a kept window batch: one recording may keep two when the window was split. */
export function pendingKey(sessionId, seq) {
    return `${sessionId}:${seq}`;
}

/**
 * What to do with a kept batch found on a page load: "send" it when it
 * belongs to this tab's recording, "drop" it when it is too old (idleTimeout
 * since it was kept) or was kept for someone else than the person this page
 * is signed in as, and "keep" it when it belongs to another tab's live
 * recording of the same person.
 */
export function pendingFate(record, { sessionId, identity, now, idleTimeout }) {
    if (!record || typeof record.session !== 'string' || typeof record.savedAt !== 'number') return 'drop';
    if (now - record.savedAt > idleTimeout || record.savedAt > now + 60_000) return 'drop';
    if ((record.identity ?? null) !== (identity ?? null)) return 'drop';

    return record.session === sessionId ? 'send' : 'keep';
}
