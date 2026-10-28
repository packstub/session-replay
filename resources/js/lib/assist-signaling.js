/**
 * The visitor's signaling for an assist call: the @packstub/calls signaling
 * interface over Session Replay's visitor routes instead of the Calls routes
 * (which sit behind `auth`). The request's poll is the guest's Calls poll, so
 * `poll()` also hands the request's own state (status, agent, consent) to
 * `onPoll`. Every request is a POST with the secret and the token in the body:
 * never in a URL, which access logs and proxies keep.
 *
 * The guest never starts, answers or declines a call (the server answers for
 * it when the page acknowledges a claim or accepts an offer), so `start`,
 * `answer` and `decline` refuse.
 */

const PLACEHOLDER = '__ID__';

export class AssistSignaling {
    /**
     * @param {object} options
     * @param {Record<string, string>} options.routes  poll, accept, decline, cancel, hangup, leave, signal, grants, store, heartbeat; `__ID__` where the request id goes
     * @param {() => { id: string, secret: string|null, token: string, session?: string|null, key?: string|null }} options.credentials
     * @param {(data: object) => void} [options.onPoll]  every poll answer, the request's own fields included
     * @param {typeof fetch} [options.fetch]
     * @param {(url: string, body: FormData) => boolean} [options.beacon]
     * @param {number} [options.timeout]
     */
    constructor({ routes, credentials, onPoll = () => {}, fetch: fetchFn, beacon, timeout = 10000 }) {
        this.routes = routes;
        this.credentials = credentials;
        this.onPoll = onPoll;
        this.timeout = timeout;
        this.fetch = fetchFn || ((...args) => globalThis.fetch(...args));
        this.beacon = beacon || ((url, body) => globalThis.navigator?.sendBeacon?.(url, body) ?? false);
        // What the browsers connect through, from the last poll that had a call.
        this.rtc = null;
    }

    url(name, id = this.credentials().id) {
        return this.routes[name].replace(PLACEHOLDER, encodeURIComponent(id ?? ''));
    }

    /** The secret and the token, plus the recording's id and key when there is one (create, re-bind, answer a knock). */
    body(extra = {}, { recording = false, secret = true } = {}) {
        const credentials = this.credentials();
        const body = { token: credentials.token, ...extra };

        if (secret && credentials.secret) body.secret = credentials.secret;
        if (recording && credentials.session) {
            body.session = credentials.session;
            if (credentials.key) body.key = credentials.key;
        }

        return body;
    }

    async request(url, body) {
        const response = await this.fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: this.timeout && typeof AbortSignal?.timeout === 'function' ? AbortSignal.timeout(this.timeout) : undefined,
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(data.message || `Assist request failed (${response.status})`);

            error.status = response.status;
            error.reason = data.reason ?? null;
            error.data = data;
            throw error;
        }

        return data;
    }

    // ---- the request -------------------------------------------------------

    /** Ask for help: the recording's triple when the tab has one. Answers the request, with `secret` once. */
    create(message, url) {
        return this.request(this.routes.store, this.body({ message: message || null, url: url || null }, { recording: true, secret: false }));
    }

    /** The first answer to an agent's knock: the triple instead of a secret, which this answer issues. */
    answerKnock(id) {
        return this.request(this.url('poll', id), this.body({ after: 0, hold: false }, { recording: true, secret: false })).then((data) => {
            this.onPoll(data);

            return data;
        });
    }

    accept(tab) {
        return this.request(this.url('accept'), this.body({ tab }));
    }

    declineOffer() {
        return this.request(this.url('decline'), this.body());
    }

    cancel() {
        return this.request(this.url('cancel'), this.body());
    }

    grant(kind, granted) {
        return this.request(this.url('grants'), this.body({ kind, granted }));
    }

    heartbeat() {
        return this.request(this.routes.heartbeat, this.body({}, { recording: true, secret: false }));
    }

    // ---- the @packstub/calls signaling interface ----------------------------

    /**
     * The request's poll, which is the guest's Calls poll once a call exists.
     * Carries the recording's triple when `rebind` says the tab's recording
     * changed, so the request follows it.
     */
    async poll({ after = 0, tab = null, hold = false, rebind = false } = {}) {
        const data = await this.request(this.url('poll'), this.body({ after, tab, hold: !!hold }, { recording: rebind || this.credentials().rebind === true }));

        if (data.rtc && Array.isArray(data.rtc.iceServers)) this.rtc = data.rtc;

        this.onPoll(data);

        return { call: data.call ?? null, incoming: null, signals: Array.isArray(data.signals) ? data.signals : [], cursor: typeof data.cursor === 'number' ? data.cursor : after };
    }

    send(callId, type, payload, to = null) {
        return this.request(this.url('signal'), this.body(to === null || to === undefined ? { type, payload } : { type, payload, to }));
    }

    hangup() {
        return this.request(this.url('hangup'), this.body());
    }

    /** Fire and forget as the page unloads: the guest's side is free for the tab's next page. */
    leave(callId, tab) {
        const credentials = this.credentials();

        if (!credentials.id || !credentials.secret) return;

        const body = new FormData();

        body.append('secret', credentials.secret);
        body.append('token', credentials.token);
        body.append('tab', tab);

        const url = this.url('leave');

        if (this.beacon(url, body)) return;

        this.fetch(url, { method: 'POST', body, keepalive: true }).catch(() => {});
    }

    start() {
        return Promise.reject(new Error('A visitor never starts an assist call.'));
    }

    answer() {
        return Promise.reject(new Error('The server answers an assist call for the visitor.'));
    }

    decline() {
        return Promise.reject(new Error('A visitor declines an offer, not a call.'));
    }
}
