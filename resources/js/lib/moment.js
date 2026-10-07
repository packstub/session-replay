/**
 * Links to a moment of a replay. ?t=<seconds into the replay> is what people
 * write and copy; ?at=<epoch ms> is what the server puts into the log context
 * (session_replay_moment), the time of the request that failed. Pure, tested
 * with node --test.
 */

/** How long before an ?at= moment the replay opens: the server's and the browser's clocks rarely agree to the second. */
export const LEAD_IN_MS = 3000;

/** Where to open a replay that starts at startedAt (epoch ms): ?t= wins, then ?at=, else the start. */
export function startOffset(search, startedAt) {
    const params = new URLSearchParams(search);
    const seconds = Number(params.get('t'));

    if (seconds > 0) return seconds * 1000;

    const at = Number(params.get('at'));

    return at > 0 ? Math.max(0, at - startedAt - LEAD_IN_MS) : 0;
}

export function momentUrl(href, milliseconds) {
    const url = new URL(href);
    const seconds = Math.floor(Math.max(0, Number(milliseconds) || 0) / 1000);

    url.hash = '';
    url.searchParams.delete('at');

    if (seconds > 0) url.searchParams.set('t', String(seconds));
    else url.searchParams.delete('t');

    return url.toString();
}
