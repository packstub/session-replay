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

/** Retries after a failed upload: 2, 4, 8, 16, 32 s, then once a minute, about four minutes in all. */
export const MAX_RETRIES = 8;

/** Milliseconds to wait before retry number `tries` (1-based). */
export function retryDelay(tries) {
    return Math.min(60_000, 1000 * 2 ** Math.max(1, tries));
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
