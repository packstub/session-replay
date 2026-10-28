/**
 * Timers for Assist's poll loops: setTimeout in a Web Worker. A hidden tab's
 * own timers slow to once a minute, which would lose the side of the call
 * Calls holds for a window (5 s) and look like a drop to the other side.
 * No package imports here: the node tests load the visitor's code without
 * node_modules.
 */

function nativeTimers() {
    return { set: (fn, ms) => setTimeout(fn, ms), clear: (id) => clearTimeout(id) };
}

/** setTimeout in a Web Worker (not throttled in a hidden tab); plain timers where a CSP or the browser refuses one. */
export function workerTimers() {
    if (typeof Worker === 'undefined' || typeof Blob === 'undefined' || typeof URL?.createObjectURL !== 'function') return nativeTimers();

    const pending = new Map();
    let broken = false;
    let next = 1;
    let worker;

    const fallBack = () => {
        if (broken) return;

        broken = true;

        // What was waiting in the worker runs on plain timers.
        for (const [id, entry] of pending) entry.native = setTimeout(() => run(id), Math.max(0, entry.at - Date.now()));
    };

    const run = (id) => {
        const entry = pending.get(id);

        pending.delete(id);
        entry?.fn();
    };

    try {
        const source = 'const t=new Map();onmessage=({data:d})=>{if(d.clear){clearTimeout(t.get(d.id));t.delete(d.id);return}t.set(d.id,setTimeout(()=>{t.delete(d.id);postMessage(d.id)},d.ms))}';

        worker = new Worker(URL.createObjectURL(new Blob([source], { type: 'text/javascript' })));
        worker.onmessage = ({ data }) => run(data);
        worker.onerror = fallBack;
    } catch {
        return nativeTimers();
    }

    return {
        set(fn, ms) {
            const id = next++;
            const entry = { fn, at: Date.now() + ms, native: null };

            pending.set(id, entry);

            if (broken) entry.native = setTimeout(() => run(id), ms);
            else worker.postMessage({ id, ms });

            return id;
        },
        clear(id) {
            const entry = pending.get(id);

            if (!entry) return;

            pending.delete(id);

            if (entry.native !== null) clearTimeout(entry.native);
            else if (!broken) worker.postMessage({ id, clear: true });
        },
    };
}
