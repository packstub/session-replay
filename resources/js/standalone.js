import { boot } from './core.js';
import { createTokenRefresher, readTokenResponse, replayHeader, tokenHeaders, tokenUrl } from './lib/standalone.js';

/**
 * @packstub/session-replay: the recorder for a frontend served apart from the
 * Laravel app (a Vue or React app on its own origin). start() fetches what
 * @sessionReplay would put in a page from the token route (GET
 * {path}/token, behind the app's own sign-in) and starts the same recorder
 * with it; uploads go to the app's ingest routes, which allow the frontend's
 * origin (ingest.origins). Call start() again after the person signs in or
 * out: a token for someone else starts a new recording.
 *
 * The token is renewed at half its life (createTokenRefresher()), so that an
 * upload is not refused first: the one a closing page sends cannot go again.
 * An upload answered 401 all the same still fetches one token, once.
 *
 * Nothing goes on window unless start({ global: true }) asks for
 * window.SessionReplay.
 */

// Before the recorder wraps window.fetch to watch the page's requests: the token request is the recorder's own.
const nativeFetch = typeof fetch === 'function' ? fetch.bind(globalThis) : null;

const CONSENT_KEY = 'sr:consent';

let controller = null;
let settings = {};
// Whom the token in use names (config.identity): a renewal is taken only for the same person.
let identity = null;
// Bumped by begin() and stop(): a renewal that was under way then knows the identity it compared with is not current.
let generation = 0;
// The app no longer names the person the token is for (signed out, someone else, not recorded): nothing records
// again until start() fetches a token, not even consent(true).
let revoked = false;
let watching = false;
let queue = Promise.resolve(false);

function warn(message) {
    try {
        console.warn(`Session Replay: ${message}`);
    } catch {
        // No console.
    }
}

/**
 * The token route's answer, checked (see readTokenResponse()): { record: false } when the app does not record this
 * person (or signs nobody in behind a required login), null when it could not be had or was not one.
 */
async function fetchToken() {
    const url = tokenUrl(settings.endpoint, location.href);
    const request = typeof settings.fetch === 'function' ? settings.fetch : nativeFetch;

    if (!request) return null;

    const response = await request(url, {
        method: 'GET',
        credentials: settings.credentials ?? 'include',
        headers: tokenHeaders(settings.headers),
        cache: 'no-store',
        // A sign-in page instead of the token is no answer.
        redirect: 'error',
    });

    if (response.status === 401 || response.status === 403) return { record: false };
    if (!response.ok) return null;

    let body = null;

    try {
        body = await response.json();
    } catch {
        body = null;
    }

    const result = readTokenResponse(body, url, location.origin);

    if (result === null) warn('the token route answered something that is not a recorder config for its own origin; nothing is recorded.');

    return result;
}

/**
 * A fresh token for the person being recorded, put in place of the one in use without touching the recording.
 * What the refresher's load() answers: the config and its lifetime, false when the app has none for this person any
 * more (signed out, someone else signed in, not recorded), null when it could not be had.
 */
async function renew() {
    const started = generation;
    const result = await fetchToken();

    if (result === null) return null;

    if (!result.record || (result.config.identity ?? null) !== identity) {
        // Signed out, or someone else signed in on this browser: the recording ends now, as start() would end it,
        // rather than going on under the old token's name until it expires. Unless start() or stop() came first.
        if (started === generation) revoke();

        return false;
    }

    controller?.update(result.config, { renewal: true });

    return { config: result.config, lifetime: result.lifetime };
}

/**
 * A fresh token for the same person, asked by the recorder once per batch the server refused with 401. It shares
 * the request with a renewal that is under way.
 */
async function refreshToken(batchIdentity) {
    const renewed = await refresher.refresh();

    if (!renewed || (renewed.config.identity ?? null) !== (batchIdentity ?? null)) return null;

    // As before the token was renewed ahead of time: a tab that sat idle past idle_minutes starts a new recording.
    controller?.update(renewed.config);

    return renewed.config.token;
}

/** The recording ends and what it kept in the browser goes with it (core's stop() sends nothing more). */
function revoke() {
    revoked = true;
    refresher.stop();
    controller?.api.stop();
}

function active() {
    return !!controller && (controller.api.isRecording() || controller.api.isBuffering());
}

const refresher = createTokenRefresher({ load: renew, ready: active });

/**
 * Timers run late in a background tab and stand still while the computer sleeps: the clock is read again whenever
 * the page is shown, and when it is hidden, which is often the last thing a page hears before it is closed.
 */
function watchPage() {
    if (watching) return;

    watching = true;
    document.addEventListener('visibilitychange', () => refresher.check());
    window.addEventListener('pageshow', () => refresher.check());
    window.addEventListener('online', () => refresher.check());
}

async function begin(options) {
    settings = { ...settings, ...(options || {}) };

    if (typeof window === 'undefined' || typeof document === 'undefined') return false;

    // @sessionReplay's recorder is already on this page: two recorders would make two recordings.
    if (!controller && window.SessionReplay) {
        warn('window.SessionReplay is already there (the @sessionReplay directive?); start() does nothing.');

        return false;
    }

    let result;
    const asked = Date.now();

    try {
        result = await fetchToken();
    } catch (error) {
        warn(error?.message || 'the token could not be fetched.');

        return false;
    }

    if (result === null) return false;

    generation++;

    // Signed out, or no longer recorded (recordWhen()): the recording of the person before ends here.
    if (!result.record) {
        revoke();

        return false;
    }

    revoked = false;
    identity = result.config.identity ?? null;
    refresher.set(result.lifetime, asked);
    watchPage();

    if (!controller) {
        controller = boot(result.config, {
            global: !!settings.global,
            refreshToken,
            // The API is usually the Laravel app itself: its failed requests are the app's.
            requestOrigins: [new URL(tokenUrl(settings.endpoint, location.href)).origin],
        });

        return active();
    }

    controller.update(result.config);
    controller.api.start();

    return active();
}

/**
 * Fetches a token and records: start({ endpoint: 'https://api.example.com/session-replay/token' }). Options:
 * endpoint (the token route), credentials ('include': the app's session cookie; 'omit' with a bearer token in
 * headers), headers (sent with the token request only), fetch (your own, e.g. one that adds a header) and global
 * (window.SessionReplay). Resolves to whether this tab records. Calls are taken one after the other.
 */
export function start(options = {}) {
    const run = queue.then(() => begin(options));

    queue = run.catch(() => false);

    return run;
}

/** Stops recording until start() or consent(true). */
export function stop() {
    generation++;
    refresher.stop();
    controller?.api.stop();
}

/** Record a moment of your own: mark('Checkout started', { cart: 3 }). */
export function mark(label, payload = {}) {
    return controller ? controller.api.mark(label, payload) : false;
}

/** Send the replay now (a "report a problem" button); resolves to whether anything went up. */
export function send(label = 'Problem reported', payload = {}) {
    return controller ? controller.api.send(label, payload) : Promise.resolve(false);
}

/**
 * consent(true) remembers the answer and starts recording; consent(false) forgets it and stops. Before start() the
 * answer is only remembered, for start() to find.
 */
export function consent(given) {
    // The token in hand names someone the app no longer does: the answer waits for start() and a token of its own.
    if (controller && !(given && revoked)) {
        const started = controller.api.consent(given);

        // Recording again with the token it had: renewed when that is due.
        if (given) refresher.resume();
        else refresher.stop();

        return started;
    }

    try {
        window.localStorage.setItem(CONSENT_KEY, given ? '1' : '0');
    } catch {
        // Blocked storage: the answer lasts as long as the page.
    }

    return false;
}

/** The running recording's id, or null. */
export function sessionId() {
    return controller ? controller.api.sessionId() : null;
}

/** Whether this tab uploads right now (in mode "on_error": only once something went wrong). */
export function isRecording() {
    return controller ? controller.api.isRecording() : false;
}

/**
 * The header that links your API's log lines to the replay: { 'X-Session-Replay': '<id>.<key>' }, or {} while
 * nothing records. It carries the recording's write key: send it to your own API only.
 */
export function header() {
    return replayHeader(controller?.recording() ?? null);
}
