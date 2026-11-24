/**
 * The pure part of standalone.js (the npm package): where the token comes
 * from, what an answer of the token route must look like before the recorder
 * is started with it, and the log-context header. Tested with node --test.
 *
 * The answer is checked strictly: the recorder posts the page's events to
 * the URLs in it, so an answer that names any other origin than the token
 * route's own is refused, as is anything of the wrong shape.
 */

export const HEADER = 'X-Session-Replay';

const MAX_TOKEN_LENGTH = 16_384;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const KEY = /^[A-Za-z0-9]{1,128}$/;

/**
 * The token route's URL from the `endpoint` option, resolved against the page: http or https only, no user name or
 * password in it, no fragment. Throws on anything else.
 */
export function tokenUrl(endpoint, page) {
    if (typeof endpoint !== 'string' || endpoint.trim() === '') throw new TypeError('Session Replay: start() needs the token endpoint, e.g. "https://api.example.com/session-replay/token".');

    const url = new URL(endpoint.trim(), page);

    if (url.protocol !== 'https:' && url.protocol !== 'http:') throw new TypeError('Session Replay: the token endpoint must be an http or https URL.');
    if (url.username || url.password) throw new TypeError('Session Replay: the token endpoint must not carry a user name or password.');

    url.hash = '';

    return url.href;
}

/** Whether `url` is an absolute http(s) URL on `origin`. */
export function sameOrigin(url, origin) {
    if (typeof url !== 'string' || url === '') return false;

    try {
        const parsed = new URL(url);

        return (parsed.protocol === 'https:' || parsed.protocol === 'http:') && parsed.origin === origin;
    } catch {
        return false;
    }
}

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);
const isStringOrNull = (value) => value === null || value === undefined || typeof value === 'string';
const isStringList = (value) => Array.isArray(value) && value.every((entry) => typeof entry === 'string');

/** Why a config from the token route cannot be used, or null when it can. */
function problem(config, origin) {
    if (!isObject(config)) return 'no config';
    if (typeof config.token !== 'string' || config.token === '' || config.token.length > MAX_TOKEN_LENGTH) return 'token';
    if (!isStringOrNull(config.identity)) return 'identity';
    if (!sameOrigin(config.ingestUrl, origin)) return 'ingestUrl';
    if (!sameOrigin(config.assetUrl, origin)) return 'assetUrl';
    if (!isNumber(config.sampleRate) || config.sampleRate < 0 || config.sampleRate > 1) return 'sampleRate';
    if (!isNumber(config.idleTimeout) || config.idleTimeout <= 0) return 'idleTimeout';
    if (!isNumber(config.flushInterval) || config.flushInterval < 1000) return 'flushInterval';
    if (!isNumber(config.maxBatchBytes) || config.maxBatchBytes <= 0) return 'maxBatchBytes';
    if (config.consent !== 'always' && config.consent !== 'opt-in') return 'consent';
    if (config.mode !== 'session' && config.mode !== 'on_error') return 'mode';
    if (config.mode === 'on_error' && !isObject(config.onError)) return 'onError';
    if (!isObject(config.privacy) || !isStringList(config.privacy.redactQuery)) return 'privacy';
    if (!isObject(config.capture) || !isStringList(config.capture.console)) return 'capture';
    if (!isObject(config.size) || !isStringList(config.size.stripAttributes) || !isStringList(config.size.keepAttributes)) return 'size';
    if (!isObject(config.snapshots) || !sameOrigin(config.snapshots.url, origin)) return 'snapshots';

    return null;
}

/**
 * How long the token in an answer of the token route is taken, in milliseconds from when it was signed; null for
 * one that never expires or does not say. `expires_in` (seconds, since 1.3.1) is the server's own count and needs
 * no clock. An older app sends only `expires_at`, a time on the server's clock: read against this browser's (`now`),
 * so a browser whose clock runs behind takes the token for longer-lived than it is, and one that runs ahead of the
 * expiry gets null. Renewing at half the life (refreshDelay()) leaves room for the first; the 401 answer covers both.
 */
export function tokenLifetime(body, now = Date.now()) {
    if (!isObject(body)) return null;
    if (isNumber(body.expires_in)) return body.expires_in > 0 ? body.expires_in * 1000 : null;

    const expiresAt = typeof body.expires_at === 'string' ? Date.parse(body.expires_at) : NaN;

    return Number.isFinite(expiresAt) && expiresAt > now ? expiresAt - now : null;
}

/**
 * What the token route answered, made safe to start the recorder with: { record: false }, or { record: true,
 * config, expiresAt, lifetime } with the parts a frontend never gets forced off (Assist, shared snapshots and, on
 * another origin than the page's, the cookie). Null for an answer that is not one: the recorder is then not started.
 * `endpoint` is the token route's URL, `pageOrigin` the page's, `now` this browser's clock (see tokenLifetime()).
 */
export function readTokenResponse(body, endpoint, pageOrigin, now = Date.now()) {
    if (!isObject(body) || typeof body.record !== 'boolean') return null;
    if (!body.record) return { record: false };

    const origin = new URL(endpoint).origin;

    if (problem(body.config, origin) !== null) return null;

    const expiresAt = typeof body.expires_at === 'string' ? Date.parse(body.expires_at) : NaN;

    return {
        record: true,
        config: {
            ...body.config,
            identity: body.config.identity ?? null,
            assist: null,
            snapshots: { ...body.config.snapshots, share: false },
            cookie: origin === pageOrigin && typeof body.config.cookie === 'string' ? body.config.cookie : null,
        },
        expiresAt: Number.isFinite(expiresAt) ? expiresAt : null,
        lifetime: tokenLifetime(body, now),
    };
}

/** Why a config was refused, for the console warning; null when it is fine. Exported for the tests. */
export function configProblem(config, endpoint) {
    return problem(config, new URL(endpoint).origin);
}

/** The log-context header for a recording ({ id, key }): { 'X-Session-Replay': '<id>.<key>' }, or {} without one. */
export function replayHeader(recording) {
    if (!recording || typeof recording.id !== 'string' || !UUID.test(recording.id)) return {};

    const key = typeof recording.key === 'string' && KEY.test(recording.key) ? recording.key : null;

    return { [HEADER]: key ? `${recording.id}.${key}` : recording.id };
}

/** The headers of the token request: JSON wanted (a 401, never a redirect to a sign-in page), the app's own after. */
export function tokenHeaders(extra) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (isObject(extra)) for (const [name, value] of Object.entries(extra)) if (typeof value === 'string') headers[name] = value;

    return headers;
}

/** A token is renewed once this share of its life has passed: the other half is the margin a tab can sleep through. */
export const REFRESH_AT = 0.5;

// Never sooner than this after a token arrived, however short its life.
const MIN_REFRESH_DELAY = 1000;
// The longest one timer waits. Timers stand still while a laptop sleeps and run late in a background tab, and
// setTimeout() fires at once past 2^31 ms (24.8 days): the clock is read again at least this often.
const MAX_TIMER = 5 * 60_000;
// A renewal that failed (offline, a 5xx) is tried again after this, then twice as long each time, up to the maximum.
const RETRY_FIRST = 30_000;
const RETRY_MAX = 15 * 60_000;

/** How long after a token with this lifetime (ms) arrived it is renewed. */
export function refreshDelay(lifetime) {
    return Math.max(MIN_REFRESH_DELAY, Math.floor(lifetime * REFRESH_AT));
}

/** How long after the nth failed renewal in a row (1, 2, …) the next one is tried. */
export function retryDelay(failures) {
    return Math.min(RETRY_MAX, RETRY_FIRST * 2 ** Math.max(0, Math.min(failures, 20) - 1));
}

/**
 * Renews the token before it expires, so that no upload has to be refused first: the last one of a page (sent
 * while it unloads) cannot be sent twice. No DOM in here: the clock and the timers can be handed in.
 *
 * `load()` fetches and applies a token: resolves to an object with its `lifetime` (ms or null) when one was applied,
 * false when there is none for this person any more (signed out, not recorded: nothing more is asked until set()),
 * null or a rejection when it could not be had (tried again later, see retryDelay(), until the token in use has
 * expired; from then on only an upload answered 401 asks again). `ready()` says whether anything records: a tab
 * that does not asks for nothing and looks again later.
 *
 * Elapsed time is measured on the browser's clock from the moment a token was asked for, never compared with the
 * server's: only the lifetime comes from there.
 */
export function createTokenRefresher({ load, ready = () => true, now = () => Date.now(), setTimer = (callback, ms) => setTimeout(callback, ms), clearTimer = (id) => clearTimeout(id) }) {
    let lifetime = null;
    let receivedAt = 0;
    let retryAt = 0;
    let failures = 0;
    let armed = false;
    let timer = null;
    let inFlight = null;
    // Bumped by set() and stop(): a renewal that was under way then decides nothing about the schedule.
    let epoch = 0;

    const dueAt = () => Math.max(receivedAt + refreshDelay(lifetime), retryAt);

    function disarm() {
        if (timer !== null) clearTimer(timer);

        timer = null;
    }

    function arm() {
        disarm();

        if (!armed || lifetime === null) return;

        const wait = dueAt() - now();

        // Due already and still here: nothing records right now. Look again later.
        timer = setTimer(check, wait > 0 ? Math.min(wait, MAX_TIMER) : MAX_TIMER);
    }

    /** The timer's callback, and what the page calls when it is shown or hidden: renews when it is time. */
    function check() {
        if (!armed || lifetime === null || inFlight) return;

        if (now() >= dueAt() && ready()) refresh();
        else arm();
    }

    /** A token arrived (start(), or a renewal): `value` is its lifetime in ms or null, `at` when it was asked for. */
    function set(value, at = now()) {
        epoch++;
        lifetime = typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : null;
        receivedAt = at;
        retryAt = 0;
        failures = 0;
        armed = true;
        arm();
    }

    function settle(result, started, at) {
        inFlight = null;

        // set() or stop() since: the schedule is theirs. (Armed again in case resume() found this one under way.)
        if (started !== epoch) {
            arm();

            return result;
        }

        if (result && typeof result === 'object') {
            set(result.lifetime, at);
        } else if (result === false) {
            lifetime = null;
            disarm();
        } else if (lifetime !== null) {
            failures++;

            // Expired by now: the next upload is refused and asks once more itself.
            if (now() >= receivedAt + lifetime) lifetime = null;
            else retryAt = now() + retryDelay(failures);

            arm();
        }

        return result;
    }

    /**
     * Fetches a token now, whatever the schedule says (an upload was answered 401). One request at a time: a call
     * while one is under way gets that one's answer.
     */
    function refresh() {
        if (inFlight) return inFlight;

        const started = epoch;
        const at = now();

        disarm();

        inFlight = Promise.resolve()
            .then(load)
            .catch(() => null)
            .then((result) => settle(result === undefined ? null : result, started, at));

        return inFlight;
    }

    /** Nothing more is asked until set() or resume(). */
    function stop() {
        epoch++;
        armed = false;
        disarm();
    }

    /** After stop(), when the recording was started again with the token it had. */
    function resume() {
        armed = true;
        check();
    }

    return { set, stop, resume, check, refresh, pending: () => inFlight !== null, scheduled: () => timer !== null };
}
