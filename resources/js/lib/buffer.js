/**
 * The rolling window of mode "on_error": the browser keeps the last few
 * seconds of events and uploads nothing until a trigger fires. Pure
 * functions over plain objects, so they run in Node for the tests.
 *
 * A replay can only start at a full snapshot (rrweb type 2, preceded by a
 * meta event, type 4), so the window is cut at a snapshot boundary: it begins
 * at the newest snapshot taken at or before the cutoff, which makes it at
 * least as long as asked and at most one snapshot interval longer.
 */

export const EVENT_META = 4;
export const EVENT_FULL_SNAPSHOT = 2;
const EVENT_INCREMENTAL = 3;

/** Marker types that may start an upload; navigation and vitals happen on every page and never do. */
export const TRIGGER_TYPES = ['error', 'request', 'console', 'rage-click', 'custom'];

// Sources of incremental events that mean a person did something: mouse move, interaction, scroll, input, touch move, drag.
export const ACTIVE_SOURCES = new Set([1, 2, 3, 5, 6, 12]);

/** Milliseconds between the fresh snapshots taken while buffering: half the window, between 5 and 30 seconds. */
export function checkoutInterval(windowMs) {
    return Math.min(30_000, Math.max(5_000, Math.round(windowMs / 2)));
}

/** Does a marker of this type start the upload? */
export function isTrigger(type, triggers) {
    return Array.isArray(triggers) && TRIGGER_TYPES.includes(type) && triggers.includes(type);
}

/**
 * Where the kept window begins: the timestamp of the meta event in front of
 * the newest full snapshot taken at or before `cutoff` (Infinity: the newest
 * snapshot of all). Null when there is no such snapshot, which keeps
 * everything: without a snapshot nothing before it could be played anyway.
 */
export function windowStart(events, cutoff) {
    let snapshot = null;

    for (const event of events) {
        if (event.type === EVENT_FULL_SNAPSHOT && event.timestamp <= cutoff && (snapshot === null || event.timestamp > snapshot.timestamp)) snapshot = event;
    }

    if (snapshot === null) return null;

    let start = snapshot.timestamp;
    let meta = -Infinity;

    // rrweb emits the meta event just before serializing the page; the snapshot is stamped when that is done.
    for (const event of events) {
        if (event.type === EVENT_META && event.timestamp <= snapshot.timestamp && event.timestamp > meta) meta = event.timestamp;
    }

    if (meta > -Infinity) start = meta;

    return start;
}

/**
 * The window from `start` on: the events and markers at or after it. The
 * page the window starts on keeps its navigation marker, moved to the start,
 * so the replay's index still says where it begins.
 */
export function trimWindow(events, markers, start) {
    if (start === null) return { events, markers };

    const kept = events.filter((event) => event.timestamp >= start);
    let page = null;
    const keptMarkers = [];

    for (const marker of markers) {
        if (marker.at >= start) keptMarkers.push(marker);
        else if (marker.type === 'navigation' && (page === null || marker.at >= page.at)) page = marker;
    }

    if (page !== null) keptMarkers.unshift({ ...page, at: start });

    return { events: kept, markers: keptMarkers };
}

/** Rough size of buffered events, the same estimate the recorder flushes by: a snapshot counts as 200 kB. */
export function estimateChars(events) {
    let chars = 0;

    for (const event of events) chars += event.type === EVENT_FULL_SNAPSHOT ? 200_000 : 300;

    return chars;
}

/** Milliseconds someone was active in these events: gaps under five seconds between interactions count as one stretch. */
export function activeTime(events) {
    let total = 0;
    let last = 0;

    for (const event of events) {
        if (event.type !== EVENT_INCREMENTAL || !ACTIVE_SOURCES.has(event.data?.source)) continue;

        if (last && event.timestamp - last < 5000 && event.timestamp > last) total += event.timestamp - last;

        last = Math.max(last, event.timestamp);
    }

    return total;
}
