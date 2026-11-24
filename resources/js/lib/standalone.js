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
 * What the token route answered, made safe to start the recorder with: { record: false }, or { record: true,
 * config, expiresAt } with the parts a frontend never gets forced off (Assist, shared snapshots and, on another
 * origin than the page's, the cookie). Null for an answer that is not one: the recorder is then not started.
 * `endpoint` is the token route's URL, `pageOrigin` the page's.
 */
export function readTokenResponse(body, endpoint, pageOrigin) {
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
