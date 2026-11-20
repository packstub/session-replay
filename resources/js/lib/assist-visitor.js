import { workerTimers } from './worker-timers.js';
import { AssistSignaling } from './assist-signaling.js';
import { CHANNEL_LABEL, Endpoint, FEATURE_MASKED, FEATURE_POINT, FROM_AGENT, LiveStyles, encodeEvent } from './assist-protocol.js';
import { RemoteControl, maskedField } from './assist-control.js';
import { isPointerSample } from './process.js';
import { createUi, label } from '../assist-ui.js';
import { STATE_KEY, randomId, storedRequest } from './assist-hook.js';

/**
 * Assist on the visitor's page, a module of its own that the recorder loads
 * with import() the first time it is needed (lib/assist-hook.js keeps the
 * heartbeat, the knock and the tab id in the recorder): asking for help
 * (SessionReplay.assist.request(), an optional "Get help" button), answering
 * an agent's offer (the knock in the upload answer and the heartbeat), and
 * once an agent is with the visitor, the live stage's visitor half: the DOM
 * mirror (the recorder's own events, already masked, over a data channel),
 * remote control, annotations, audio and screen share, each asked for
 * separately (30 s without an answer is no) and reported to the request's
 * grants log.
 *
 * The request id and secret live in sessionStorage beside sr:session, with
 * the grants a reload keeps (watch, audio, video). Remote control goes on to
 * the tab's next page of the site with assist.control_across_pages (see
 * handOver() and continues()); without it, it is asked again on every page.
 * Polling runs on a Web Worker timer: a hidden tab's own timers slow to once a
 * minute, which would lose the call (lib/assist-client.js, shared with the
 * agent's stage).
 *
 * The call is a @packstub/holler CallClient with media 'none' and the channel
 * "assist"; its signaling is AssistSignaling over Session Replay's visitor
 * routes, never Holler's own (they sit behind auth). The engine comes in as
 * `env.Client` and `env.drain`: the bundle (resources/js/assist.js) passes the
 * real one, the node tests a fake, so this file never imports the package.
 */

const TERMINAL = ['cancelled', 'declined', 'expired', 'withdrawn', 'ended'];
const PENDING = ['open', 'claimed', 'active'];
const GRANT_TIMEOUT_MS = 30_000;
// After a no, the agent cannot ask for the same thing again this soon (refused with reason `cooldown`).
export const ASK_COOLDOWN_MS = 10_000;
// Remote control handed over to the next page (assist.control_across_pages): that page must take it within this
// long of the last one being left, its channel to the agent included; past it, control has ended.
export const CARRY_MS = 30_000;
// How long a page that took control over says so ("… still controls this page"), before the usual line.
export const STILL_MS = 8_000;

/** How this page was reached: the navigation type, the referrer and its own URL. */
export function arrival() {
    let type = null;

    try {
        type = globalThis.performance?.getEntriesByType?.('navigation')?.[0]?.type ?? null;
    } catch {
        // No Navigation Timing: no type, so nothing is taken over.
    }

    return { type, referrer: globalThis.document?.referrer || '', href: globalThis.location?.href || '' };
}

const withoutHash = (url) => String(url || '').split('#')[0];

/**
 * Whether remote control handed over by the page this tab left (`carry`: its URL and when) goes on here. Only on
 * the very next page of the tab, so never past a page the assist did not run on (not recorded, another site): a
 * reload of the same URL, or a navigation whose referrer is the page that handed control over (a link, a form and
 * its redirect, a script's location change). A referrer the app's Referrer-Policy strips, the back and forward
 * buttons, and a hand-over older than CARRY_MS end control instead: it fails closed.
 */
export function continues(carry, { type, referrer, href } = {}, now = Date.now()) {
    if (!carry || typeof carry.from !== 'string' || !Number.isFinite(carry.at)) return false;
    if (now < carry.at || now - carry.at > CARRY_MS) return false;
    if (type === 'reload') return withoutHash(href) !== '' && withoutHash(href) === withoutHash(carry.from);
    if (type === 'navigate') return withoutHash(referrer) !== '' && withoutHash(referrer) === withoutHash(carry.from);

    return false;
}

/**
 * @param {object} recorder  what the recorder lends Assist
 * @param {() => object} recorder.config  the page's config (window.__sessionReplay)
 * @param {() => ({ id: string, key: string|null }|null)} recorder.recording  the recording this tab makes, if any
 * @param {() => boolean} recorder.snapshot  rrweb's takeFullSnapshot(), false while rrweb is not running
 * @param {() => boolean} [recorder.mirrors]  rrweb runs on the page shown now (after a wire:navigate: a recorded page)
 * @param {(id: number) => Node|null} recorder.node  rrweb's mirror
 * @param {() => void} recorder.goLive  mode on_error: start uploading now
 * @param {() => void} recorder.touch  the visitor is here: the idle timeout must not end the recording
 * @param {(url: string) => string} recorder.redacted
 * @param {(key: string) => string|null} recorder.read  sessionStorage
 * @param {(key: string, value: string) => void} recorder.write
 * @param {string} recorder.tab  the tab's own id (sessionStorage), sent with a new request
 * @param {object} env  the engine (`Client`, a CallClient class, and `drain`), plus the tests' fakes: `ui`, `timers`, `fetch`, `now`, `arrival`
 */
/** What the browser can share: getUserMedia and getDisplayMedia exist only in a secure context (https, localhost). */
export function mediaDevices() {
    const secure = globalThis.isSecureContext !== false;
    const devices = secure ? globalThis.navigator?.mediaDevices : undefined;

    return { media: typeof devices?.getUserMedia === 'function', screen: typeof devices?.getDisplayMedia === 'function' };
}

/** Why the microphone or camera did not start, for the agent: `blocked` (a permission said no) or `failed`. */
export function mediaError(error) {
    return ['NotAllowedError', 'SecurityError', 'PermissionDeniedError'].includes(error?.name) ? 'blocked' : 'failed';
}

export function installAssist(recorder, env) {
    if (typeof env?.Client !== 'function' || typeof env?.drain !== 'function') throw new TypeError('installAssist needs the calls engine: env.Client and env.drain.');

    const settings = () => recorder.config().assist || {};
    const labels = settings().labels || {};
    const timers = env.timers || workerTimers();
    const Client = env.Client;
    const drain = env.drain;
    const now = env.now || (() => Date.now());
    // What this browser can share at all: nothing on a page served over plain http (not a secure context), where
    // navigator.mediaDevices does not exist, so no permission prompt could ever open.
    const devices = env.devices || mediaDevices;
    // This page's window on the call (in Holler a window claims its side); a reload is a new one.
    const tab = randomId();
    const ui = env.ui || createUi({ labels });
    // Offers this tab answered or declined: the knock repeats with every upload while one is open.
    const declined = new Set();

    let state = load();
    let pollTimer = null;
    let client = null;
    let endpoint = null;
    let streaming = false;
    let liveStyles = null;
    let control = null;
    let controlling = false;
    let maskedGranted = false;
    // Control handed over by the page before (state.carry): the outline and the panel say so at once, and it starts
    // once this page's channel is up and its snapshot went out; until then nothing of the agent's is acted on.
    let carried = null;
    let resuming = false;
    let resumeTimer = null;
    // A wire:navigate swap under control: nothing is acted on until the new page is known and its snapshot went out.
    let suspended = false;
    let identityBefore = null;
    // The page is being left with control handed over: what ends now is logged by the page that takes it, or not.
    let leaving = false;
    // "… still controls this page" until then.
    let stillUntil = 0;
    let stillTimer = null;
    // Control ended while no channel was up (a reconnect, a page that did not take it over): said on the next one.
    let untold = null;
    // The agent's typing that came while the visitor's own activity paused them: the latest value per field,
    // applied once the pause is over (an input or a select carries the whole value, so nothing is lost).
    const deferred = new Map();
    let deferTimer = null;
    let lastPoll = null;
    let busy = false;
    // The microphone: muted by the visitor's own button (only that button unmutes; a reload keeps it), or by the
    // agent ending the audio (media.stop: only a fresh Allow of the visitor's lifts it). Never unmuted on a grant
    // remembered from earlier.
    let visitorMuted = !!state?.muted;
    let agentMuted = false;
    // Consent prompts: one at a time per kind (control, media, screen, masked), and none of a kind for
    // ASK_COOLDOWN_MS after the visitor said no to it.
    const asking = new Set();
    const saidNoAt = new Map();

    const signaling = new AssistSignaling({
        routes: settings().routes,
        credentials: () => {
            const recording = recorder.recording();

            return {
                id: state?.id ?? null,
                secret: state?.secret ?? null,
                token: recorder.config().token,
                session: recording?.id ?? null,
                key: recording?.key ?? null,
                // Every poll names the tab's recording: when it changed (idle timeout, a new recording after a
                // navigation) the request follows it; the same one changes nothing on the server.
                rebind: !!recording,
            };
        },
        onPoll: (data) => handle(data),
        ...(env.fetch ? { fetch: env.fetch } : {}),
    });

    function load() {
        return storedRequest(recorder.read);
    }

    function save() {
        recorder.write(STATE_KEY, state ? JSON.stringify(state) : 'null');
    }

    function agentName() {
        return state?.agent?.name || label(labels, 'support');
    }

    function emit() {
        try {
            window.dispatchEvent(new CustomEvent('session-replay:assist', { detail: status() }));
        } catch {
            // A listener that throws is the page's own business.
        }
    }

    function status() {
        return state ? { id: state.id, status: state.status, agent: state.agent ? { name: state.agent.name, avatar: state.agent.avatar } : null } : { id: null, status: null, agent: null };
    }

    // ---- polling --------------------------------------------------------------

    function schedulePoll(ms) {
        timers.clear(pollTimer);
        pollTimer = null;

        // Once a call exists, the client's own poll is the request's poll.
        if (!state || client) return;

        pollTimer = timers.set(poll, ms ?? settings().poll?.waiting ?? 3000);
    }

    async function poll() {
        pollTimer = null;

        if (!state || client) return;

        try {
            await signaling.poll({ after: 0, tab, claim: true });
        } catch (error) {
            if (failed(error)) return;
        }

        schedulePoll();
    }

    /** A refusal from the server: true when the request is gone for this tab. */
    function failed(error) {
        const data = error?.data || {};

        if (error?.status === 403 || error?.status === 401) {
            finish(null);

            return true;
        }

        if (error?.status === 409 && data.status && TERMINAL.includes(data.status)) {
            finish(data.status, data.end_reason);

            return true;
        }

        return false;
    }

    /** Every poll answer, the orchestrator's or the client's. */
    function handle(data) {
        if (!state || !data || data.id !== state.id) return;

        lastPoll = data;
        recorder.touch();

        const changed = state.status !== data.status || state.agent?.id !== data.agent?.id;
        const nextAgent = data.agent ? String(data.agent.id) : null;

        // Consent is given to a person: when the request has no agent any more, or another one,
        // what was allowed before is gone (and the log says so), and the next agent asks again.
        if (state.agent && state.agent.id !== nextAgent) {
            forgetGrants();

            // Nothing of the call with the agent who left goes on: the channel and the client go too.
            if (client) {
                Promise.resolve().then(() => {
                    if (!client) return;

                    closeChannel('reconnect');
                    dropClient();
                    schedulePoll(0);
                });
            }
        }

        state.status = data.status;
        state.agent = data.agent ? { id: String(data.agent.id), name: data.agent.name, avatar: data.agent.avatar } : null;
        state.openedBy = data.opened_by;
        save();

        if (TERMINAL.includes(data.status)) return finish(data.status, data.end_reason);

        // Control waiting for this page's channel goes on only into a live assist with the same agent.
        if (resuming && data.status !== 'active') endControl('reconnect');

        if (data.status === 'open') {
            if (data.opened_by === 'agent') offer();
            else ui.show({ text: label(labels, 'waiting'), actions: [{ label: label(labels, 'cancel'), onClick: () => cancel() }] });
        } else if (data.status === 'claimed') {
            ui.cancelPrompts('offer');

            // The agent left the live view: the call is over, the request waits for them to come back. The client
            // goes once this answer is through it (the answer is also the client's own poll).
            if (data.reconnecting && client) {
                Promise.resolve().then(() => {
                    if (!client || state?.status !== 'claimed') return;

                    closeChannel('reconnect');
                    dropClient();
                    schedulePoll(0);
                });
            }

            if (data.reconnecting) ui.show({ text: label(labels, 'rejoining', agentName()), agent: state.agent, actions: [{ label: label(labels, 'end'), kind: 'danger', onClick: () => cancel() }] });
            else if (data.consent === 'watch') consent();
            else if (settings().calls) ui.show({ text: label(labels, 'joining', agentName()), agent: state.agent, actions: [{ label: label(labels, 'cancel'), onClick: () => cancel() }] });
            else ui.show({ text: label(labels, 'queued', agentName()), agent: state.agent });
        } else if (data.status === 'active') {
            ui.cancelPrompts('offer');
            ui.cancelPrompts('consent');

            // Only the agent the request names: signaling already checked the sender, this checks the call.
            if (data.call && data.agent && String(data.call.peer?.id) === String(data.agent.id)) join(data);
            if (changed || !client) activeView();
        }

        if (changed) emit();
    }

    /** The panel while an agent is with the visitor: what they see, the call bar, End (and End remote control). */
    function activeView() {
        if (!state || state.status !== 'active') return;

        const actions = [{ label: label(labels, 'end'), kind: 'danger', onClick: () => end() }];

        if (client?.cameraStream) actions.unshift({ label: label(labels, client.muted ? 'unmute' : 'mute'), onClick: () => mute(!client.muted) });
        if (controlling || resuming) actions.unshift({ label: label(labels, 'endControl'), kind: 'primary', onClick: () => endControl('visitor') });

        // 'waiting': the agent put the assist on hold (Holler's hold: they are on another call and come back).
        const text =
            controlling || resuming ? (now() < stillUntil ? 'stillControlling' : 'controlling') : client?.state === 'waiting' ? 'onHold' : client?.state === 'reconnecting' ? 'reconnecting' : client?.sharing ? 'seesScreen' : 'sees';

        ui.show({ text: label(labels, text, agentName()), agent: state.agent, actions });
    }

    // ---- the request ----------------------------------------------------------

    async function request({ message = '' } = {}) {
        if (state && PENDING.includes(state.status)) return status();

        // Mode on_error: the agent needs a recording to look at.
        recorder.goLive();

        let data;

        try {
            data = await signaling.create(String(message || '').slice(0, 500), recorder.redacted(location.href), recorder.tab);
        } catch (error) {
            ui.notice(label(labels, 'failed'));
            throw error;
        }

        if (!data.secret) {
            // The tab lost what it knew of a request still going (storage cleared): it cannot hold it.
            ui.notice(label(labels, 'failed'));

            return status();
        }

        state = { id: data.id, secret: data.secret, status: data.status, openedBy: 'visitor', agent: null, grants: {} };
        save();
        // The "Get help" button goes while a request is going, also one made with SessionReplay.assist.request().
        launcher();
        handle(data);
        emit();
        schedulePoll(0);

        return status();
    }

    async function cancel() {
        if (!state) return;

        try {
            await signaling.cancel();
        } catch (error) {
            if (failed(error)) return;
        }

        finish('cancelled');
    }

    async function end() {
        if (!state) return;
        if (state.status !== 'active') return cancel();

        try {
            await signaling.hangup();
        } catch (error) {
            if (failed(error)) return;
        }

        finish('ended', 'hung_up');
    }

    /** The agent offered help: Accept / Not now, until the offer expires. */
    async function offer() {
        if (busy || ui.promptOpen) return;

        busy = true;

        const yes = await ui.prompt(label(labels, 'offer', agentName()), { agent: state.agent, allow: label(labels, 'accept'), deny: label(labels, 'notNow'), timeoutMs: 0, tag: 'offer' });

        busy = false;

        if (!state || state.status !== 'open') return;

        yes ? accept() : decline();
    }

    /** assist.watch_consent: the agent who claimed waits for an Accept before joining. */
    async function consent() {
        if (busy || ui.promptOpen) return;

        busy = true;

        const yes = await ui.prompt(label(labels, 'watch', agentName()), { agent: state.agent, allow: label(labels, 'accept'), deny: label(labels, 'notNow'), timeoutMs: 0, tag: 'consent' });

        busy = false;

        if (!state || state.status !== 'claimed') return;

        yes ? accept() : cancel();
    }

    async function accept() {
        recorder.goLive();

        try {
            await signaling.accept(tab);
        } catch (error) {
            if (!failed(error)) ui.notice(label(labels, 'failed'));

            return;
        }

        state.grants = { ...state.grants, watch: true };
        save();
        grant('watch', true);
        schedulePoll(0);
    }

    async function decline() {
        const id = state?.id;

        if (id) declined.add(id);

        try {
            await signaling.declineOffer();
        } catch (error) {
            if (failed(error)) return;
        }

        finish('declined');
    }

    /** The knock: an agent's offer for this tab's recording, from the upload answer or the heartbeat. */
    async function knock(assist) {
        if (!assist || typeof assist.id !== 'string' || declined.has(assist.id)) return;
        if (state && state.id !== assist.id && PENDING.includes(state.status)) return;
        if (state && state.id === assist.id) return;

        // Answered once: the knock comes with every upload while the offer is open.
        declined.add(assist.id);

        let data;

        try {
            data = await signaling.answerKnock(assist.id);
        } catch (error) {
            // A network failure or a busy server: the next knock tries again. A refusal (the secret went to
            // another tab, the offer is over) stays answered.
            if (!error?.status || error.status >= 500 || error.status === 429) declined.delete(assist.id);

            return;
        }

        if (!data.secret) return;

        state = { id: data.id, secret: data.secret, status: data.status, openedBy: 'agent', agent: data.agent, grants: {} };
        save();
        launcher();
        handle(data);
        emit();
        schedulePoll();
    }

    function grant(kind, granted) {
        signaling.grant(kind, granted).catch(() => {});
    }

    /** Audio, video and watching were allowed for the agent who just left the request: revoked. */
    function forgetGrants() {
        for (const kind of ['audio', 'video']) {
            if (state.grants?.[kind]) grant(kind, false);
        }

        state.grants = {};
        agentMuted = false;
    }

    // ---- the call -------------------------------------------------------------

    function join(data) {
        if (client) return;

        timers.clear(pollTimer);
        pollTimer = null;

        client = new Client(
            {
                signaling,
                media: 'none',
                video: false,
                channels: [CHANNEL_LABEL],
                iceServers: () => signaling.rtc?.iceServers ?? [],
                iceTransportPolicy: data.rtc?.iceTransportPolicy === 'relay' ? 'relay' : 'all',
                poll: { idle: settings().poll?.active ?? 2000, active: settings().poll?.active ?? 2000, hidden: settings().poll?.active ?? 2000 },
            },
            timers,
        );
        client.tab = tab;

        client.addEventListener('datachannel', ({ detail }) => open(detail.channel, detail.peer));
        client.addEventListener('remotestream', ({ detail }) => ui.media(detail.stream, !!(state?.grants?.audio || state?.grants?.video)));
        client.addEventListener('statechange', () => activeView());
        client.addEventListener('ended', () => {
            closeChannel('ended');
            dropClient();
            // What became of the request: the next poll says.
            schedulePoll(0);
        });
        client.addEventListener('error', ({ detail }) => {
            if (detail?.error?.status) failed(detail.error);
        });

        client.resumeCall(data.call.id);
    }

    function dropClient() {
        if (!client) return;

        const old = client;

        client = null;

        try {
            old.destroy();
        } catch {
            // Already down.
        }

        ui.clearMedia();
    }

    function open(channel, peer) {
        if (!state?.agent || String(peer.id) !== String(state.agent.id) || channel.label !== CHANNEL_LABEL) return;

        // The first channel of the page leaves control handed over from the page before waiting for it.
        if (endpoint) closeChannel('replaced');

        endpoint = new Endpoint({
            channel,
            role: 'visitor',
            schema: FROM_AGENT,
            context: { viewport: () => ({ width: window.innerWidth, height: window.innerHeight }), knows: (id) => !!recorder.node(id) },
            // Optional message types this build takes: the agent's stage sends one only to a page that names it.
            can: [FEATURE_POINT],
            drain,
            onReady() {
                liveStyles = new LiveStyles();
                streaming = true;
                // The agent's mirror starts from a fresh snapshot (it goes into the stored recording too).
                const mirrored = recorder.snapshot();

                // Control from the page before goes on only once the agent sees this one, and says so before the
                // first state message (the stage settles a control it lost with the channel on that message).
                if (resuming) {
                    if (!mirrored) endControl('not_recorded');
                    else if (document.visibilityState === 'hidden') endControl('hidden');
                    else resume();
                }

                if (untold && !controlling) endpoint.send({ t: 'control.released', reason: untold });

                untold = null;

                sendState();

                // Audio and video allowed before a reload are not asked again.
                if (state?.grants?.audio || state?.grants?.video) startMedia(state.grants.video ? 'video' : 'audio', false);
            },
            onMessage: receive,
            onVersion: () => ui.notice(label(labels, 'update')),
            onClose: () => {
                streaming = false;
                ui.pointer.hide();
                endControl('reconnect');
            },
        }).start();
    }

    function closeChannel(reason) {
        streaming = false;
        ui.pointer.hide();
        endControl(reason);
        endpoint?.close(reason);
        endpoint = null;
    }

    function sendState() {
        // Not before the channel's own first one (onReady): the stage settles control from the page before on it.
        if (!endpoint?.ready) return;

        endpoint.send({ t: 'state', hidden: document.visibilityState === 'hidden', url: recorder.redacted(location.href), width: window.innerWidth, height: window.innerHeight });
    }

    /**
     * The recorder's tap: one event, after every privacy step, before the asynchronous stylesheet dedupe. One channel
     * message per event, sent at once: rrweb already emits one event per batch of mutations and groups pointer
     * positions into one event, and the endpoint sends behind backpressure (pointer samples dropped first), so
     * batching events per task would save little framing; it would need a message type both sides know, so it waits
     * for a protocol version that needs one.
     */
    function tap(event) {
        if (!streaming || !endpoint) return;

        try {
            let json;

            if (liveStyles.holds(event)) {
                // A copy: the recorder's own event goes on to the dedupe and the upload as it is.
                const copy = JSON.parse(JSON.stringify(event));

                for (const css of liveStyles.strip(copy)) endpoint.send(css);
                json = JSON.stringify(copy);
            } else {
                // Now, not later: the upload renames and deletes parts of the same object.
                json = JSON.stringify(event);
            }

            endpoint.sendRaw(encodeEvent(json), { droppable: isPointerSample(event) });
        } catch {
            // An event that cannot be serialized is left out of the live view only.
        }
    }

    function receive(message) {
        switch (message.t) {
            case 'control.request':
                return askControl();
            case 'control.release':
                return endControl('agent');
            case 'media.request':
                return askMedia(message.kind);
            case 'media.stop':
                agentMuted = true;

                return applyMute();
            case 'screen.request':
                return askScreen();
            case 'screen.stop':
                return stopScreen();
            case 'annot.start':
            case 'annot.move':
            case 'annot.stop':
                // A stroke never covers a question.
                if (ui.promptOpen) return;
                if (message.t === 'annot.start') ui.ink.start(message.x, message.y);
                else if (message.t === 'annot.move') ui.ink.move(message.x, message.y);
                else ui.ink.stop();

                return;
            case 'point':
                // Where the agent points while they watch: drawn, never acted on (no consent, like a stroke).
                // With control, the control cursor is the one; never over a question; not in a tab nobody sees.
                if (controlling || ui.promptOpen || document.visibilityState === 'hidden') return;

                return ui.pointer.move(agentName(), message.x, message.y);
            case 'point.hide':
                return ui.pointer.hide();
            case 'bye':
                return ui.pointer.hide();
            default:
                return act(message);
        }
    }

    // ---- consent and grants -----------------------------------------------------

    /**
     * One consent question of a kind: `{ yes }`, or `{ refused }` without asking: 'pending' (one of that kind is up
     * or queued: a repeat is dropped) or 'cooldown' (the visitor said no to that kind less than ASK_COOLDOWN_MS ago).
     */
    async function ask(kind, text, options = {}) {
        if (asking.has(kind)) return { refused: 'pending' };
        if (saidNoAt.has(kind) && now() - saidNoAt.get(kind) < ASK_COOLDOWN_MS) return { refused: 'cooldown' };

        asking.add(kind);

        let yes = false;

        try {
            yes = await ui.prompt(text, { agent: state?.agent, timeoutMs: GRANT_TIMEOUT_MS, tag: kind, ...options });
        } finally {
            asking.delete(kind);
        }

        if (yes) saidNoAt.delete(kind);
        else saidNoAt.set(kind, now());

        return { yes };
    }

    async function askControl() {
        if (controlling || resuming || document.visibilityState === 'hidden') return;

        const { yes, refused } = await ask('control', label(labels, 'askControl', agentName()));

        if (refused === 'pending') return;
        if (refused) return endpoint?.send({ t: 'control.refused', reason: refused });
        if (!endpoint || !state) return;

        grant('control', yes);

        if (!yes) return endpoint.send({ t: 'control.refused', reason: 'declined' });

        startControl();
    }

    /** Control starts: after the visitor's Allow, or taken over from the page before (`continued`, with its masked-typing consent). */
    function startControl({ continued = false, masked = false } = {}) {
        const config = recorder.config();

        controlling = true;
        suspended = false;
        untold = null;
        maskedGranted = masked;
        control = new RemoteControl({
            win: window,
            doc: document,
            isOwn: (element) => ui.contains(element),
            node: recorder.node,
            isMasked: (element) => maskedField(element, { maskAllInputs: config.privacy?.maskAllInputs !== false, ignoreSelector: config.privacy?.ignoreSelector || null, blockSelector: config.privacy?.blockSelector || null }),
            blockSelector: config.privacy?.blockSelector || null,
            maskedAllowed: () => maskedGranted,
            onCursor: (x, y) => ui.cursor.move(x, y),
            now,
        });
        ui.outline(true);
        ui.cursor.show(agentName());
        activeView();
        endpoint?.send(continued ? { t: 'control.granted', continued: true } : { t: 'control.granted' });
    }

    /** The control handed over from the page before goes on here: no new question, no new grant in the log. */
    function resume() {
        const carry = carried;

        resuming = false;
        carried = null;
        timers.clear(resumeTimer);
        resumeTimer = null;
        startControl({ continued: true, masked: !!carry?.masked });
    }

    /** "… still controls this page. Press Escape to stop.": on a page that took control over, for STILL_MS. */
    function remind() {
        stillUntil = now() + STILL_MS;
        timers.clear(stillTimer);
        stillTimer = timers.set(() => {
            stillTimer = null;
            activeView();
        }, STILL_MS);
        activeView();
    }

    /** Control ends: by either side, a hidden tab, a navigation, a lost channel. */
    function endControl(reason) {
        ui.cancelPrompts('control');
        ui.cancelPrompts('masked');

        if (resuming) return dropCarry(reason);
        if (!controlling) return;

        controlling = false;
        suspended = false;
        dropDeferred();
        control?.release();
        control = null;
        ui.outline(false);
        ui.cursor.hide();

        // Leaving with control handed over: the next page logs the end if it does not take it.
        if (!leaving) {
            grant('control', false);

            if (maskedGranted) grant('control_masked', false);
        }

        maskedGranted = false;
        stillUntil = 0;
        released(reason);
        activeView();
    }

    /** Control handed over from the page before does not go on here: it ends, logged once. */
    function dropCarry(reason) {
        const carry = carried;

        resuming = false;
        carried = null;
        timers.clear(resumeTimer);
        resumeTimer = null;
        ui.outline(false);
        ui.cursor.hide();

        if (carry && !leaving) logEnd(carry);

        maskedGranted = false;
        stillUntil = 0;
        released(reason);
        activeView();
    }

    /**
     * Tell the agent control ended, now or on the next channel's first message: a stage that lost the channel with
     * control on keeps it for a next page until then. Not while the page is being left with control handed over.
     */
    function released(reason) {
        if (endpoint?.ready && !endpoint.closed) return endpoint.send({ t: 'control.released', reason });
        if (!leaving) untold = reason;
    }

    /** The grants log: the control period (and its masked-typing consent) that began on an earlier page is over. */
    function logEnd(carry) {
        grant('control', false);

        if (carry.masked) grant('control_masked', false);
    }

    /**
     * The page is being left (pagehide): control goes on to the tab's next page, which takes it if it continues()
     * from here. Also control this page was still waiting to take over (left before its channel came).
     */
    function handOver() {
        if (!state || !settings().controlAcrossPages || document.visibilityState === 'hidden') return;
        if (!controlling && !resuming) return;

        state.carry = { from: location.href, at: now(), masked: controlling ? maskedGranted : !!carried?.masked };
        leaving = true;
        save();
    }

    /** Control the page before handed over, if it goes on here; removed from storage either way, and its end logged if not. */
    function takeCarry() {
        const carry = state?.carry;

        if (!carry) return null;

        delete state.carry;
        save();

        if (settings().controlAcrossPages && state.status === 'active' && document.visibilityState !== 'hidden' && continues(carry, (env.arrival || arrival)(), now())) return carry;

        logEnd(carry);
        untold = 'navigation';

        return null;
    }

    /** A wire:navigate swap under control: control pauses (the agent is told), nothing is acted on until afterSwap(). */
    function beforeSwap() {
        if (!controlling || !settings().controlAcrossPages) return endControl('navigation');

        suspended = true;
        identityBefore = recorder.config().identity ?? null;
        dropDeferred();
        control?.release();
        ui.cancelPrompts('masked');
        endpoint?.send({ t: 'control.released', reason: 'navigation', paused: true });
    }

    /**
     * The new page is in place. The recorder's own listener ran first and restarted rrweb, so a recorded page's full
     * snapshot already went to the agent: control goes on, after it on the channel. Not on a page the app does not
     * record, nor when the page was signed for another person or workspace.
     */
    function afterSwap() {
        if (!suspended) return;

        suspended = false;

        if (!controlling) return;
        if ((recorder.config().identity ?? null) !== identityBefore) return endControl('navigation');
        if (!recorder.mirrors?.()) return endControl('not_recorded');

        remind();
        endpoint?.send({ t: 'control.granted', continued: true });
    }

    /** One control message, only while control is granted now, on a page the agent sees, and no question is up. */
    async function act(message) {
        if (!controlling || suspended || !control || ui.promptOpen) return;

        const run = () => {
            switch (message.t) {
                case 'move':
                    return control.move(message.x, message.y);
                case 'click':
                    return control.click(message.x, message.y);
                case 'scroll':
                    return control.scroll(message.dx, message.dy);
                case 'drag.start':
                    return control.dragStart(message.x, message.y);
                case 'drag':
                    return control.drag(message.x, message.y);
                case 'drag.stop':
                    return control.dragStop();
                case 'focus':
                    return control.focus(message.id);
                case 'input':
                    return control.input(message.id, message.value);
                case 'select':
                    return control.select(message.id, message.value);
                default:
                    return { ok: true };
            }
        };

        const result = run();

        // Typing that came while the visitor's own mouse or keys paused the agent: applied once the pause is over.
        if (result.ignored === 'visitor' && (message.t === 'input' || message.t === 'select')) return defer(message);

        if (result.refused === 'masked' && settings().controlMasked && !maskedGranted) {
            // A stage that takes it hears where the question stands: asking, then granted or declined (and why).
            const tell = !!endpoint?.peerCan(FEATURE_MASKED);

            // Asked already: the stage waits for the answer, and this action goes with the question.
            if (asking.has('masked')) return;
            if (tell && !(saidNoAt.has('masked') && now() - saidNoAt.get('masked') < ASK_COOLDOWN_MS)) endpoint.send({ t: 'masked.asking' });

            const started = now();
            const { yes, refused } = await ask('masked', label(labels, 'askMasked', agentName()));

            if (refused === 'pending') return;
            if (refused) return tell ? endpoint?.send({ t: 'masked.declined', reason: refused }) : reply(result);
            if (!controlling) return;

            grant('control_masked', yes);
            maskedGranted = yes;

            if (yes) {
                if (tell) endpoint?.send({ t: 'masked.granted' });

                return reply(run());
            }

            // A question left unanswered counts as no; the stage says which it was.
            if (tell) return endpoint?.send({ t: 'masked.declined', reason: now() - started >= GRANT_TIMEOUT_MS ? 'timeout' : 'declined' });
        }

        reply(result);
    }

    /** Keep the latest typing for a field until the visitor's pause is over, then act on it. */
    function defer(message) {
        deferred.set(message.id, message);
        timers.clear(deferTimer);
        deferTimer = timers.set(
            () => {
                deferTimer = null;

                const due = [...deferred.values()];

                deferred.clear();
                for (const next of due) act(next);
            },
            Math.max(0, (control?.pausedUntil ?? 0) - now()) + 50,
        );
    }

    function dropDeferred() {
        deferred.clear();
        timers.clear(deferTimer);
        deferTimer = null;
    }

    function reply(result) {
        if (result?.refused && result.refused !== 'nothing') endpoint?.send({ t: 'control.refused', reason: result.refused });
    }

    async function askMedia(kind) {
        if (!client || !state) return;

        if (kind === 'video' && !settings().video) return endpoint?.send({ t: 'media.refused', kind });
        // Not asked: an Allow could not open the microphone or the camera here.
        if (!devices().media) return endpoint?.send({ t: 'media.refused', kind, reason: 'unavailable' });

        // Allowed earlier in this request (a reload keeps it): not asked again, unless the agent ended the audio
        // since; then only a fresh Allow brings the microphone back.
        const remembered = !agentMuted && (kind === 'video' ? state.grants?.video : state.grants?.audio || state.grants?.video);
        let yes = !!remembered;

        if (!remembered) {
            const answer = await ask('media', label(labels, kind === 'video' ? 'askVideo' : 'askAudio', agentName()));

            if (answer.refused === 'pending') return;
            if (answer.refused) return endpoint?.send({ t: 'media.refused', kind, reason: answer.refused });

            yes = answer.yes;
        }

        if (!state) return;
        if (!remembered) grant(kind, yes);
        if (!yes) return endpoint?.send({ t: 'media.refused', kind, reason: 'declined' });

        // The visitor's own Allow lifts the agent's mute; never their own (only their button does).
        if (!remembered) agentMuted = false;

        state.grants = { ...state.grants, [kind]: true };
        save();
        startMedia(kind, !remembered);
    }

    async function startMedia(kind, announce) {
        try {
            await client?.startMedia(kind);
        } catch (error) {
            // The browser's own prompt said no (or the site is blocked in its settings), or no device could be opened.
            endpoint?.send({ t: 'media.refused', kind, reason: mediaError(error) });

            if (announce) grant(kind, false);

            return;
        }

        applyMute();
        if (client?.remoteStream) ui.media(client.remoteStream, true);
        endpoint?.send({ t: 'media.granted', kind });
        activeView();
    }

    /** The microphone as the two flags say: muted while either the visitor or the agent muted it. */
    function applyMute() {
        if (!client) return;

        const muted = visitorMuted || agentMuted;

        if (client.muted !== muted) client.setMuted(muted);

        activeView();
    }

    /** The visitor's Mute / Unmute button: the only way their own mute is lifted. */
    function mute(muted) {
        visitorMuted = muted;

        // Unmuting is the visitor's say: the agent's mute goes with it.
        if (!muted) agentMuted = false;
        if (state) {
            state.muted = muted;
            save();
        }

        applyMute();
    }

    async function askScreen() {
        if (!client || client.sharing) return;
        if (!devices().screen) return endpoint?.send({ t: 'screen.refused', reason: 'unavailable' });

        const { yes, refused } = await ask('screen', label(labels, 'askScreen', agentName()));

        if (refused === 'pending') return;
        if (refused) return endpoint?.send({ t: 'screen.refused', reason: refused });

        if (!yes) {
            grant('screen', false);

            return endpoint?.send({ t: 'screen.refused' });
        }

        try {
            await client.shareScreen();
        } catch {
            // The visitor closed the browser's picker.
        }

        if (!client?.sharing) {
            grant('screen', false);

            return endpoint?.send({ t: 'screen.refused' });
        }

        grant('screen', true);
        endpoint?.send({ t: 'screen.granted' });
        activeView();

        // The browser's own "Stop sharing" bar.
        const track = client.screenStream?.getVideoTracks()[0];

        track?.addEventListener('ended', () => {
            grant('screen', false);
            endpoint?.send({ t: 'screen.stopped' });
            activeView();
        });
    }

    /** The agent stopped looking at the visitor's screen: the share ends and the grants log says so. */
    async function stopScreen() {
        if (!client?.sharing) return;

        try {
            await client.stopSharing();
        } catch {
            // Stopped already.
        }

        grant('screen', false);
        endpoint?.send({ t: 'screen.stopped' });
        activeView();
    }

    // ---- the end --------------------------------------------------------------

    /** The request is over (status) or gone for this tab (null). */
    function finish(status, reason = null) {
        if (!state) return;

        timers.clear(pollTimer);
        pollTimer = null;
        closeChannel('ended');
        dropClient();
        ui.cancelPrompts();
        ui.ink.clear();
        ui.hide();

        const by = state.openedBy;

        state = null;
        lastPoll = null;
        visitorMuted = false;
        agentMuted = false;
        saidNoAt.clear();
        save();
        emit();

        if (status === 'ended') ui.notice(label(labels, 'ended'));
        else if (status === 'expired' && by === 'visitor') ui.notice(label(labels, 'expired'));
        else if (status === 'withdrawn') ui.notice(label(labels, 'withdrawn'));
        else if (status === 'cancelled' && reason === 'identity_changed') ui.notice(label(labels, 'ended'));

        launcher();
    }

    // ---- the page -------------------------------------------------------------

    function launcher() {
        ui.launcher(!!settings().button && !state, () => {
            ui.launcher(false);
            ui.form({
                onSubmit: (message) => request({ message }).catch(() => {}),
                onCancel: () => {
                    ui.hide();
                    launcher();
                },
            });
        });
    }

    function visibility() {
        // The heartbeat on coming back is the recorder's (lib/assist-hook.js).
        if (document.visibilityState === 'hidden') {
            endControl('hidden');
            ui.pointer.hide();
        }

        sendState();
    }

    function mountedListeners() {
        const visitor = (event) => {
            if (!event.isTrusted || !(controlling || resuming)) return;
            if (event.type === 'keydown' && event.key === 'Escape') return endControl('visitor');
            // A press in the Assist panel (an Allow, End remote control) is no activity on the page: it never pauses
            // the agent. The panel is a closed shadow root, so the event's target here is its host.
            if (ui.contains(event.target)) return;

            control?.visitorActivity();
        };

        for (const type of ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart']) document.addEventListener(type, visitor, { capture: true, passive: true });

        document.addEventListener('visibilitychange', visibility);
        document.addEventListener('livewire:navigating', () => beforeSwap());
        document.addEventListener('livewire:navigated', () => {
            ui.remount();
            afterSwap();
            sendState();
        });
        window.addEventListener('resize', () => sendState());
        window.addEventListener('pagehide', () => {
            handOver();
            // The guest's side of the call is free for the tab's next page (the leave beacon).
            client?.leave();
            endpoint?.close('unload');
        });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;

            // Back from the browser's back-forward cache: not a next page, so control handed over (by this page or
            // the one after it) ends here. Read from storage: what this page holds in memory is from before.
            leaving = false;

            if (state) delete state.carry;

            const saved = load();

            if (!saved?.carry) return;

            const carry = saved.carry;

            delete saved.carry;
            recorder.write(STATE_KEY, JSON.stringify(saved));
            logEnd(carry);
        });
    }

    ui.mount();
    mountedListeners();
    launcher();

    // Control handed over by the page before: the outline and the panel at once, control once the channel is up.
    carried = takeCarry();

    if (carried) {
        resuming = true;
        maskedGranted = !!carried.masked;
        ui.outline(true);
        resumeTimer = timers.set(() => {
            resumeTimer = null;
            endControl('reconnect');
        }, CARRY_MS);
        remind();
    }

    // A reload in the middle of a request: the request goes on from where it was.
    if (state) schedulePoll(0);

    return {
        tap,
        knock: (assist) => knock(assist),
        api: {
            request: (options) => request(options),
            cancel: () => cancel(),
            end: () => end(),
            status,
        },
    };
}
