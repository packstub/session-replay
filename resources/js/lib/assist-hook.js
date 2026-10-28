/**
 * What the recorder keeps of Assist on every page: the tab's own id, the
 * heartbeat that brings an agent's offer, the knock read from the upload
 * answer, the emit tap and a `SessionReplay.assist` that loads the rest.
 * The rest (assist.js, with the calls engine and the visitor's UI) is a
 * separate module, loaded with a dynamic import() of the same-origin script
 * URL only when it is needed: an offer knocks, the tab has a request going
 * (sessionStorage), the "Get help" button is on, or the page asks for help.
 * Events emitted before it loads are not needed: the module takes a full
 * snapshot when the channel opens.
 */

export const STATE_KEY = 'sr:assist';
export const TAB_KEY = 'sr:tab';

/** 24 hex characters from the browser's CSPRNG (Math.random only where there is none). */
export function randomId() {
    const bytes = new Uint8Array(12);
    const crypto = globalThis.crypto;

    if (crypto?.getRandomValues) crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * The tab's own id, kept in sessionStorage for the tab's life: with the
 * token's nonce, what keeps one help request per tab without a recording
 * (a page from a full-page cache gives every visitor the same nonce).
 */
export function tabId(read, write) {
    const saved = read(TAB_KEY);

    if (typeof saved === 'string' && /^[A-Za-z0-9_-]{8,64}$/.test(saved)) return saved;

    const id = randomId();

    write(TAB_KEY, id);

    return id;
}

/** The request this tab has going (id and secret in sessionStorage), or null. */
export function storedRequest(read) {
    try {
        const saved = JSON.parse(read(STATE_KEY) || 'null');

        return saved && typeof saved.id === 'string' && typeof saved.secret === 'string' ? saved : null;
    } catch {
        return null;
    }
}

const TERMINAL = ['cancelled', 'declined', 'expired', 'withdrawn', 'ended'];

/**
 * @param {object} options
 * @param {() => object} options.settings  config.assist
 * @param {() => string} options.token  the page's signed token
 * @param {() => ({ id: string, key: string|null }|null)} options.recording  the recording this tab makes, if any
 * @param {(key: string) => string|null} options.read  sessionStorage
 * @param {(key: string, value: string) => void} options.write
 * @param {(url: string) => Promise<object>} [options.load]  import() of the module
 * @param {(lend: object) => object} options.lend  what the recorder lends the module's installAssist()
 * @param {typeof fetch} [options.fetch]
 * @param {Document} [options.document]
 */
export function createAssistHook({ settings, token, recording, read, write, load, lend, fetch: fetchFn, document: doc = globalThis.document }) {
    const tab = tabId(read, write);
    const fetcher = fetchFn || ((...args) => globalThis.fetch(...args));
    const importer = load || ((url) => import(/* webpackIgnore: true */ url));

    let instance = null;
    let loading = null;
    let heartbeatTimer = null;

    /** The module, installed once; a refusal (a CSP, the network) leaves the recording alone and lets a later need try again. */
    function ensure() {
        if (instance) return Promise.resolve(instance);

        loading ||= Promise.resolve()
            .then(() => importer(settings().module))
            .then((module) => {
                instance = module.installAssist({ ...lend, tab });

                return instance;
            })
            .catch((error) => {
                loading = null;
                throw error;
            });

        return loading;
    }

    function knock(assist) {
        if (!assist || typeof assist.id !== 'string') return;

        ensure()
            .then((loaded) => loaded.knock(assist))
            .catch(() => {});
    }

    /** A visible tab's sign of life, which also brings an agent's offer to a page that uploads nothing. */
    async function heartbeat() {
        clearTimeout(heartbeatTimer);
        heartbeatTimer = setTimeout(heartbeat, settings().heartbeatMs || 15_000);

        const current = recording();

        if (doc?.visibilityState !== 'visible' || !current || !settings().routes?.heartbeat) return;

        try {
            const response = await fetcher(settings().routes.heartbeat, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                // The token and the key in the body, never in the URL.
                body: JSON.stringify({ token: token(), session: current.id, key: current.key ?? null }),
            });

            if (!response.ok) return;

            knock((await response.json())?.assist);
        } catch {
            // The next beat tries again.
        }
    }

    const stored = () => storedRequest(read);
    const going = () => {
        const saved = stored();

        return !!saved && !TERMINAL.includes(saved.status);
    };

    const api = {
        request: (options) => ensure().then((loaded) => loaded.api.request(options)),
        cancel: () => (instance || going() ? ensure().then((loaded) => loaded.api.cancel()) : Promise.resolve()),
        end: () => (instance || going() ? ensure().then((loaded) => loaded.api.end()) : Promise.resolve()),
        status() {
            if (instance) return instance.api.status();

            const saved = stored();

            return saved && !TERMINAL.includes(saved.status)
                ? { id: saved.id, status: saved.status ?? null, agent: saved.agent ? { name: saved.agent.name, avatar: saved.agent.avatar } : null }
                : { id: null, status: null, agent: null };
        },
    };

    function start() {
        doc?.addEventListener?.('visibilitychange', () => {
            if (doc.visibilityState === 'visible') heartbeat();
        });

        heartbeat();

        // A reload in the middle of a request goes on from where it was; the button needs the UI from the start.
        if (going() || settings().button) ensure().catch(() => {});
    }

    return {
        tab,
        api,
        ensure,
        knock,
        heartbeat,
        start,
        /** The live view's share of every emitted event, once the module is there. */
        tap: (event) => instance?.tap(event),
        loaded: () => !!instance,
        /** No more heartbeats (tests; a page that stops recording keeps beating harmlessly otherwise). */
        stop: () => clearTimeout(heartbeatTimer),
    };
}
