/**
 * Failed requests of apps that do not go through Livewire (Inertia, Vue,
 * React, plain fetch or axios): the recorder wraps fetch and XMLHttpRequest
 * and asks requestMarker() whether a finished request is worth a "request"
 * marker. Pure functions, tested with node --test.
 *
 * Only the app's own origin counts (a blocked analytics call is not an error
 * of the app), only an answer at or above the threshold or none at all, and
 * never the recorder's own uploads. Never headers or bodies.
 */

/** The marker for a finished request, or null when it is not one: { label, payload }. */
export function requestMarker({ url, method = 'GET', status = 0, duration = 0, threshold = 500, page, skip = [], type = null }) {
    // A fetch with redirect: 'manual' or mode: 'no-cors' resolves with a status of 0 that is no failure.
    if (type === 'opaque' || type === 'opaqueredirect') return null;

    let parsed;
    let origin;

    try {
        parsed = new URL(url, page);
        origin = new URL(page).origin;
    } catch {
        return null;
    }

    if (parsed.origin !== origin) return null;
    if (skip.some((prefix) => prefix && parsed.href.startsWith(prefix))) return null;
    if (status !== 0 && status < threshold) return null;

    const verb = String(method || 'GET').toUpperCase();

    return {
        label: `${verb} ${parsed.pathname} ${status ? `failed (${status})` : 'got no answer'}`,
        payload: { method: verb, status: status || null, url: parsed.href, duration: Math.round(duration) },
    };
}

/** Whether a fetch init's or Request's headers (a Headers object, a plain object or a list of pairs) name `name`. */
export function hasHeader(headers, name) {
    if (!headers) return false;

    const wanted = name.toLowerCase();

    try {
        if (typeof headers.has === 'function') return headers.has(name);
        if (Array.isArray(headers)) return headers.some((pair) => String(pair?.[0]).toLowerCase() === wanted);

        return Object.keys(headers).some((key) => key.toLowerCase() === wanted);
    } catch {
        return false;
    }
}

/**
 * A promise that settles like `promise`, after `onResolve` or `onReject` saw
 * how. Attaching a handler to the app's own promise would mark it handled, and
 * a failure the app never catches would no longer reach unhandledrejection
 * (and the error marker, or the app's error tracker). The app gets this one
 * instead: its own handlers, or the lack of them, keep their effect. Neither
 * callback can get in the way.
 */
export function observe(promise, onResolve, onReject) {
    return promise.then(
        (value) => {
            try {
                onResolve(value);
            } catch {
                // Nothing of ours in the way of the app's request.
            }

            return value;
        },
        (error) => {
            try {
                onReject(error);
            } catch {
                // As above.
            }

            throw error;
        },
    );
}
