import { pendingKey } from './lib/pending.js';

/**
 * The kept window batches of mode "on_error", in IndexedDB: one database,
 * one store, a handful of small records (the gzip blob, the index next to
 * it, the recording it belongs to, when it was kept). Every call resolves
 * even where IndexedDB is missing or blocked (private mode, a disabled
 * storage), with null or an empty list: the recorder then behaves as if
 * nothing was kept.
 */

const DB_NAME = 'session-replay';
const STORE = 'pending';

let opening = null;

function open() {
    if (opening) return opening;

    opening = new Promise((resolve) => {
        let request;

        try {
            request = window.indexedDB.open(DB_NAME, 1);
        } catch {
            resolve(null);

            return;
        }

        request.onupgradeneeded = () => {
            try {
                request.result.createObjectStore(STORE, { keyPath: 'key' });
            } catch {
                // Already there.
            }
        };
        request.onsuccess = () => {
            const db = request.result;

            // Another tab upgrading or the browser clearing site data: open again next time.
            db.onversionchange = () => {
                db.close();
                opening = null;
            };
            resolve(db);
        };
        request.onerror = () => resolve(null);
        request.onblocked = () => resolve(null);
    });

    return opening;
}

/**
 * Whether the database exists, without creating it: a sweep must not leave
 * one behind in an app that never kept anything. Null where the browser
 * cannot tell (no indexedDB.databases()).
 */
export async function pendingStoreExists() {
    try {
        if (typeof window.indexedDB?.databases !== 'function') return null;

        return (await window.indexedDB.databases()).some((database) => database.name === DB_NAME);
    } catch {
        return null;
    }
}

/** Runs `work` on the store and resolves with the request's result, or null when anything goes wrong. */
async function run(mode, work) {
    const db = await open();

    if (!db) return null;

    return new Promise((resolve) => {
        try {
            const store = db.transaction(STORE, mode).objectStore(STORE);
            const request = work(store);

            request.onsuccess = () => resolve(request.result ?? null);
            request.onerror = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

/** Keep a batch: the gzip blob, what the upload needs next to it and the stylesheet texts it may still have to upload. */
export function savePending(sessionId, batch, blob, assets, identity) {
    return run('readwrite', (store) =>
        store.put({
            key: pendingKey(sessionId, batch.seq),
            session: sessionId,
            seq: batch.seq,
            meta: batch.meta,
            anonymous: !!batch.anonymous,
            identity: identity ?? null,
            assets,
            blob,
            savedAt: Date.now(),
        }),
    );
}

export function deletePending(sessionId, seq) {
    return run('readwrite', (store) => store.delete(pendingKey(sessionId, seq)));
}

/** Every kept batch, of every tab: the caller sorts out which are its own and which are stale. */
export async function listPending() {
    const records = await run('readonly', (store) => store.getAll());

    return Array.isArray(records) ? records : [];
}
