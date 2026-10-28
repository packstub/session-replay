import { CallClient, drain } from '@packstub/calls';
import { AssistSignaling } from './lib/assist-signaling.js';
import { CHANNEL_LABEL, Endpoint, FROM_AGENT, LiveStyles, encodeEvent } from './lib/assist-protocol.js';
import { RemoteControl, maskedField } from './lib/assist-control.js';
import { isPointerSample } from './lib/process.js';
import { createUi, label } from './assist-ui.js';
import { STATE_KEY, randomId, storedRequest } from './lib/assist-hook.js';

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
 * the grants a reload keeps (watch, audio, video; control is asked again
 * after every reload or navigation). Polling runs on a Web Worker timer: a
 * hidden tab's own timers slow to once a minute, which would lose the call.
 *
 * The call is a @packstub/calls CallClient with media 'none' and the channel
 * "assist"; its signaling is AssistSignaling over Session Replay's visitor
 * routes, never Calls' own (they sit behind auth).
 */

const TERMINAL = ['cancelled', 'declined', 'expired', 'withdrawn', 'ended'];
const PENDING = ['open', 'claimed', 'active'];
const GRANT_TIMEOUT_MS = 30_000;

// ---- timers that keep running in a hidden tab -------------------------------

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

/** The engine's client, its poll loop on the worker timer. `schedule` and `stop` are internal to the engine; this copy is bundled. */
class AssistClient extends CallClient {
    constructor(options, timers) {
        super(options);
        this.timers = timers;
    }

    schedule(delay) {
        this.timers?.clear(this.timer);
        this.timer = null;

        if (!this.polling) return;

        this.timer = this.timers.set(() => this.tick(), delay ?? this.pollIntervals.active);
    }

    stop() {
        this.timers?.clear(this.timer);
        this.timer = null;
        super.stop();
    }
}

/**
 * @param {object} recorder  what the recorder lends Assist
 * @param {() => object} recorder.config  the page's config (window.__sessionReplay)
 * @param {() => ({ id: string, key: string|null }|null)} recorder.recording  the recording this tab makes, if any
 * @param {() => boolean} recorder.snapshot  rrweb's takeFullSnapshot(), false while rrweb is not running
 * @param {(id: number) => Node|null} recorder.node  rrweb's mirror
 * @param {() => void} recorder.goLive  mode on_error: start uploading now
 * @param {() => void} recorder.touch  the visitor is here: the idle timeout must not end the recording
 * @param {(url: string) => string} recorder.redacted
 * @param {(key: string) => string|null} recorder.read  sessionStorage
 * @param {(key: string, value: string) => void} recorder.write
 * @param {string} recorder.tab  the tab's own id (sessionStorage), sent with a new request
 */
export function installAssist(recorder) {
    const settings = () => recorder.config().assist || {};
    const labels = settings().labels || {};
    const timers = workerTimers();
    // This page's window on the call (Calls holds a side per window); a reload is a new one.
    const tab = randomId();
    const ui = createUi({ labels });
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
    let lastPoll = null;
    let busy = false;

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
            await signaling.poll({ after: 0, tab, hold: true });
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

        state.status = data.status;
        state.agent = data.agent ? { id: String(data.agent.id), name: data.agent.name, avatar: data.agent.avatar } : null;
        state.openedBy = data.opened_by;
        save();

        if (TERMINAL.includes(data.status)) return finish(data.status, data.end_reason);

        if (data.status === 'open') {
            if (data.opened_by === 'agent') offer();
            else ui.show({ text: label(labels, 'waiting'), actions: [{ label: label(labels, 'cancel'), onClick: () => cancel() }] });
        } else if (data.status === 'claimed') {
            ui.cancelPrompts('offer');

            if (data.consent === 'watch') consent();
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
        if (controlling) actions.unshift({ label: label(labels, 'endControl'), kind: 'primary', onClick: () => endControl('visitor') });

        const text = controlling ? 'controlling' : client?.state === 'reconnecting' ? 'reconnecting' : client?.sharing ? 'seesScreen' : 'sees';

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
        handle(data);
        emit();
        schedulePoll();
    }

    function grant(kind, granted) {
        signaling.grant(kind, granted).catch(() => {});
    }

    // ---- the call -------------------------------------------------------------

    function join(data) {
        if (client) return;

        timers.clear(pollTimer);
        pollTimer = null;

        client = new AssistClient(
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

        closeChannel('replaced');

        endpoint = new Endpoint({
            channel,
            role: 'visitor',
            schema: FROM_AGENT,
            context: { viewport: () => ({ width: window.innerWidth, height: window.innerHeight }), knows: (id) => !!recorder.node(id) },
            drain,
            onReady() {
                liveStyles = new LiveStyles();
                streaming = true;
                // The agent's mirror starts from a fresh snapshot (it goes into the stored recording too).
                recorder.snapshot();
                sendState();

                // Audio and video allowed before a reload are not asked again.
                if (state?.grants?.audio || state?.grants?.video) startMedia(state.grants.video ? 'video' : 'audio', false);
            },
            onMessage: receive,
            onVersion: () => ui.notice(label(labels, 'update')),
            onClose: () => {
                streaming = false;
                endControl('reconnect');
            },
        }).start();
    }

    function closeChannel(reason) {
        streaming = false;
        endControl(reason);
        endpoint?.close(reason);
        endpoint = null;
    }

    function sendState() {
        endpoint?.send({ t: 'state', hidden: document.visibilityState === 'hidden', url: recorder.redacted(location.href), width: window.innerWidth, height: window.innerHeight });
    }

    /** The recorder's tap: one event, after every privacy step, before the asynchronous stylesheet dedupe. */
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
                return client?.setMuted(true);
            case 'screen.request':
                return askScreen();
            case 'screen.stop':
                return client?.stopSharing().then(() => endpoint?.send({ t: 'screen.stopped' }));
            case 'annot.start':
            case 'annot.move':
            case 'annot.stop':
                // A stroke never covers a question.
                if (ui.promptOpen) return;
                if (message.t === 'annot.start') ui.ink.start(message.x, message.y);
                else if (message.t === 'annot.move') ui.ink.move(message.x, message.y);
                else ui.ink.stop();

                return;
            case 'bye':
                return;
            default:
                return act(message);
        }
    }

    // ---- consent and grants -----------------------------------------------------

    async function askControl() {
        if (controlling || document.visibilityState === 'hidden') return;

        const yes = await ui.prompt(label(labels, 'askControl', agentName()), { agent: state?.agent, timeoutMs: GRANT_TIMEOUT_MS, tag: 'control' });

        if (!endpoint || !state) return;

        grant('control', yes);

        if (!yes) return endpoint.send({ t: 'control.refused', reason: 'declined' });

        startControl();
    }

    function startControl() {
        const config = recorder.config();

        controlling = true;
        maskedGranted = false;
        control = new RemoteControl({
            win: window,
            doc: document,
            isOwn: (element) => ui.contains(element),
            node: recorder.node,
            isMasked: (element) => maskedField(element, { maskAllInputs: config.privacy?.maskAllInputs !== false, ignoreSelector: config.privacy?.ignoreSelector || null }),
            maskedAllowed: () => maskedGranted,
            onCursor: (x, y) => ui.cursor.move(x, y),
        });
        ui.outline(true);
        ui.cursor.show(agentName());
        activeView();
        endpoint?.send({ t: 'control.granted' });
    }

    /** Control ends: by either side, a hidden tab, a navigation, a lost channel. */
    function endControl(reason) {
        ui.cancelPrompts('control');
        ui.cancelPrompts('masked');

        if (!controlling) return;

        controlling = false;
        control?.release();
        control = null;
        ui.outline(false);
        ui.cursor.hide();
        grant('control', false);

        if (maskedGranted) grant('control_masked', false);

        maskedGranted = false;
        endpoint?.send({ t: 'control.released', reason });
        activeView();
    }

    /** One control message, only while control is granted now and no question is up. */
    async function act(message) {
        if (!controlling || !control || ui.promptOpen) return;

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

        if (result.refused === 'masked' && settings().controlMasked && !maskedGranted) {
            const yes = await ui.prompt(label(labels, 'askMasked', agentName()), { agent: state?.agent, timeoutMs: GRANT_TIMEOUT_MS, tag: 'masked' });

            if (!controlling) return;

            grant('control_masked', yes);
            maskedGranted = yes;

            if (yes) return reply(run());
        }

        reply(result);
    }

    function reply(result) {
        if (result?.refused && result.refused !== 'nothing') endpoint?.send({ t: 'control.refused', reason: result.refused });
    }

    async function askMedia(kind) {
        if (!client || !state) return;

        if (kind === 'video' && !settings().video) return endpoint?.send({ t: 'media.refused', kind });

        const remembered = kind === 'video' ? state.grants?.video : state.grants?.audio || state.grants?.video;
        const yes = remembered || (await ui.prompt(label(labels, kind === 'video' ? 'askVideo' : 'askAudio', agentName()), { agent: state.agent, timeoutMs: GRANT_TIMEOUT_MS, tag: 'media' }));

        if (!state) return;
        if (!remembered) grant(kind, yes);
        if (!yes) return endpoint?.send({ t: 'media.refused', kind });

        state.grants = { ...state.grants, [kind]: true };
        save();
        startMedia(kind, true);
    }

    async function startMedia(kind, announce) {
        try {
            await client?.startMedia(kind);
        } catch {
            endpoint?.send({ t: 'media.refused', kind });

            if (announce) grant(kind, false);

            return;
        }

        if (client?.remoteStream) ui.media(client.remoteStream, true);
        endpoint?.send({ t: 'media.granted', kind });
        activeView();
    }

    function mute(muted) {
        client?.setMuted(muted);
        activeView();
    }

    async function askScreen() {
        if (!client || client.sharing) return;

        const yes = await ui.prompt(label(labels, 'askScreen', agentName()), { agent: state?.agent, timeoutMs: GRANT_TIMEOUT_MS, tag: 'screen' });

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
        if (document.visibilityState === 'hidden') endControl('hidden');

        sendState();
    }

    function mountedListeners() {
        const visitor = (event) => {
            if (!event.isTrusted || !controlling) return;
            if (event.type === 'keydown' && event.key === 'Escape') return endControl('visitor');

            control?.visitorActivity();
        };

        for (const type of ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart']) document.addEventListener(type, visitor, { capture: true, passive: true });

        document.addEventListener('visibilitychange', visibility);
        document.addEventListener('livewire:navigating', () => endControl('navigation'));
        document.addEventListener('livewire:navigated', () => {
            ui.remount();
            sendState();
        });
        window.addEventListener('resize', () => sendState());
        window.addEventListener('pagehide', () => {
            // The guest's side of the call is free for the tab's next page (the leave beacon).
            client?.leave();
            endpoint?.close('unload');
        });
    }

    ui.mount();
    mountedListeners();
    launcher();

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
