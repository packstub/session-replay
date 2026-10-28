import { Replayer } from '@rrweb/replay';
import '@rrweb/replay/dist/style.css';
import './stage.css';
import { HttpSignaling, drain } from '@packstub/calls';
import { AssistClient, workerTimers } from './lib/assist-client.js';
import { CHANNEL_LABEL, Endpoint, FROM_VISITOR } from './lib/assist-protocol.js';
import { AgentState, FINISHED, Gesture, LiveFeed, ago, editable, handleVisitorMessage, reasonKey, stageDelta, stagePhase, stagePoint } from './lib/assist-stage.js';

/**
 * The agent's live stage in the built-in viewer ({path}/assist/requests/{id}/stage):
 * the visitor's page mirrored from the recorder's own events (rrweb's Replayer in
 * live mode, its controller hidden, the iframe with rrweb's default sandbox, no
 * scripts), remote control through an overlay, annotations, audio (and video
 * where Calls allows it), screen share either way, and the end of the assist.
 *
 * The stage runs its own CallClient over Calls' own routes (kind=assist polls,
 * the ICE servers from the `ice` route) with media 'none' and the `assist`
 * data channel; the protocol Endpoint is attached synchronously in the
 * `datachannel` event so the visitor's hello is never missed. The request's
 * status is polled from Session Replay's agent route; when it ends, everything
 * stops. Mounted on [data-session-replay-stage]; its data-config is the JSON
 * the Blade view writes.
 *
 * A host page may bring its own markup (the Filament package renders its
 * buttons with the panel's components): the script only looks for the
 * data-ref, data-action and data-why attributes inside the root, writes a
 * button's label into its [data-label] element when it has one (else the whole
 * button), and dispatches `session-replay:stage` on the root after every
 * render with the state and a one-word `phase`. In a panel that navigates with
 * wire:navigate the stage leaves the call on `livewire:navigating` (like a page
 * unload: the request stays the agent's) and mounts again on `livewire:navigated`.
 */

const STATUS_POLL_MS = 2000;
const INK_HOLD_MS = 3000;
const INK_FADE_MS = 1000;
const INPUT_DEBOUNCE_MS = 150;
const NOTES = 6;

function fill(text, values = {}) {
    return String(text ?? '').replace(/:([a-z_]+)/g, (match, key) => (key in values ? String(values[key]) : match));
}

/** A button's label: its [data-label] element when the host's markup has one (an icon beside the text), else the button. */
function setLabel(element, text) {
    (element.querySelector('[data-label]') ?? element).textContent = text;
}

function mountStage(root) {
    let config;

    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch {
        return;
    }

    const labels = config.labels || {};
    const find = (path) => path.split('.').reduce((node, key) => (node && typeof node === 'object' ? node[key] : undefined), labels);
    const t = (path, values) => {
        const value = find(path);

        return fill(typeof value === 'string' ? value : path, values);
    };
    const endReason = (reason) => t(`end_reasons.${reason && typeof find(`end_reasons.${reason}`) === 'string' ? reason : 'other'}`);

    const ref = (name) => root.querySelector(`[data-ref="${name}"]`);
    const els = {
        status: ref('status'),
        seen: ref('seen'),
        screen: ref('screen'),
        mirror: ref('mirror'),
        video: ref('video'),
        ink: ref('ink'),
        overlay: ref('overlay'),
        placeholder: ref('placeholder'),
        editor: ref('editor'),
        editorText: ref('editor-text'),
        editorSelect: ref('editor-select'),
        notes: ref('notes'),
        url: ref('url'),
        audiobar: ref('audiobar'),
        audio: ref('audio'),
        face: ref('face'),
        mute: ref('mute'),
        endAudio: ref('end-audio'),
    };
    const buttons = {};

    for (const button of root.querySelectorAll('[data-action]')) buttons[button.dataset.action] = button;

    let request = config.request;
    const state = new AgentState({ calls: !!config.calls?.available, routes: !!config.calls?.routes, video: !!config.video });
    const gesture = new Gesture();
    const timers = workerTimers();
    let client = null;
    let endpoint = null;
    let replayer = null;
    let drawing = false;
    let finished = false;
    let destroyed = false;
    let statusTimer = null;
    let editing = null;
    const ink = { strokes: [], current: null, frame: null };

    // ---- the mirror -----------------------------------------------------------

    const feed = new LiveFeed({
        createReplayer() {
            els.mirror.replaceChildren();
            replayer = new Replayer([], {
                root: els.mirror,
                liveMode: true,
                mouseTail: false,
                showWarning: false,
                triggerFocus: false,
                pauseAnimation: false,
                UNSAFE_replayCanvas: false,
            });

            // Firefox swaps a new iframe's first document a moment after insertion; opening it cancels the swap (see player.js).
            replayer.iframe?.contentDocument?.open();
            replayer.iframe?.contentDocument?.close();
            replayer.on('resize', () => fit());

            return replayer;
        },
        onStart() {
            els.placeholder.hidden = true;
            fit();
            render();
        },
    });

    function frame() {
        const iframe = replayer?.iframe;

        if (!iframe) return null;

        return { rect: iframe.getBoundingClientRect(), width: parseFloat(iframe.getAttribute('width')) || state.width, height: parseFloat(iframe.getAttribute('height')) || state.height };
    }

    /** Scale the replayed viewport into the stage: rrweb sizes its iframe to the visitor's viewport. */
    function fit() {
        const iframe = replayer?.iframe;
        const wrapper = replayer?.wrapper;

        if (!iframe || !wrapper) return;

        const width = parseFloat(iframe.getAttribute('width')) || state.width || 1280;
        const height = parseFloat(iframe.getAttribute('height')) || state.height || 800;
        const room = els.screen.clientWidth || width;
        const tall = Math.max(240, window.innerHeight - 180);
        const scale = Math.min(1, room / width, tall / height);

        wrapper.style.transform = `scale(${scale})`;
        wrapper.style.transformOrigin = '0 0';
        els.mirror.style.width = `${Math.round(width * scale)}px`;
        els.mirror.style.height = `${Math.round(height * scale)}px`;
        sizeInk();
    }

    // ---- notes, status, buttons -----------------------------------------------

    function note(key, reason = null) {
        const item = document.createElement('li');
        const time = document.createElement('time');
        const now = new Date();

        time.dateTime = now.toISOString();
        time.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        item.append(time, document.createTextNode(' ' + t(`notes.${key}`, { reason: reason === null ? '' : t(`reasons.${reasonKey(reason)}`) })));
        els.notes.prepend(item);

        while (els.notes.children.length > NOTES) els.notes.lastElementChild.remove();
    }

    function statusText() {
        if (finished) return t('status.ended', { reason: endReason(request.end_reason) });
        if (!state.calls) return t('status.no_calls');
        if (!state.routes) return t('status.no_routes');
        if (state.version) return t('status.version');

        switch (request.status) {
            case 'open':
                return t('status.offered');
            case 'claimed':
                return t('status.claimed');
            case 'active':
                if (!client || client.state === 'connecting' || client.state === 'idle' || client.state === 'calling') return t('status.connecting');
                if (client.state === 'reconnecting') return t('status.reconnecting');
                if (!state.connected) return t('status.connecting');

                return state.hidden ? t('status.hidden') : t('status.live');
            default:
                return t('status.ended', { reason: endReason(request.end_reason) });
        }
    }

    function setButton(name, { why = null, pressed = null, label = null, hidden = false } = {}) {
        const button = buttons[name];

        if (!button) return;

        button.hidden = hidden;
        button.disabled = why !== null;

        if (label !== null) setLabel(button, label);
        if (pressed !== null) button.setAttribute('aria-pressed', String(pressed));

        const hint = root.querySelector(`[data-why="${name}"]`);

        if (hint) {
            hint.textContent = why === null ? '' : t(`why.${why}`);
            hint.hidden = why === null;
        }
    }

    function render() {
        if (state.why('draw') !== null) drawing = false;

        els.status.textContent = statusText();

        const seen = ago(request.visitor_seen_ago);

        els.seen.textContent = finished || seen === null ? '' : t(seen.unit === 's' ? 'seen_seconds' : 'seen_minutes', { value: seen.value });

        if (state.url) els.url.textContent = state.url;

        const live = request.status === 'active' && !finished;
        const control = state.why('control');

        setButton('mirror', { why: live ? null : 'connecting', pressed: state.mode === 'mirror' });
        setButton('screen', {
            why: state.screen === 'on' ? null : state.why('screen'),
            pressed: state.mode === 'screen',
            label: state.screen === 'on' ? t('buttons.screen') : state.screen === 'asking' ? t('buttons.screen_asking') : t('buttons.ask_screen'),
        });
        setButton('control', {
            why: control,
            pressed: state.control === 'on',
            label: state.control === 'on' ? t('buttons.release') : state.control === 'asking' ? t('buttons.control_asking') : t('buttons.control'),
        });
        setButton('draw', { why: state.why('draw'), pressed: drawing });
        setButton('audio', {
            why: state.audio === 'on' ? 'audio_on' : state.why('audio'),
            label: state.audio === 'asking' ? t('buttons.audio_asking') : t('buttons.audio'),
        });
        setButton('video', {
            hidden: !config.video,
            why: state.video === 'on' ? 'video_on' : state.why('video'),
            label: state.video === 'asking' ? t('buttons.video_asking') : t('buttons.video'),
        });
        setButton('share', { why: state.why('share'), pressed: !!client?.sharing, label: client?.sharing ? t('buttons.stop_share') : t('buttons.share') });
        setButton('withdraw', { hidden: finished || !['open', 'claimed'].includes(request.status) });
        setButton('end', { hidden: finished || !['claimed', 'active'].includes(request.status) });

        const talking = state.audio === 'on' || state.video === 'on';

        els.audiobar.hidden = !talking || finished;
        setLabel(els.mute, client?.muted ? t('buttons.unmute') : t('buttons.mute'));
        els.mute.setAttribute('aria-pressed', String(!!client?.muted));

        // The view: the mirror, or the visitor's screen as incoming video.
        const screen = state.mode === 'screen';

        els.video.hidden = !screen;
        els.mirror.hidden = screen;
        els.placeholder.hidden = screen || feed.started;

        if (!feed.started && !screen) els.placeholder.textContent = live && state.connected ? t('placeholder.snapshot') : live ? t('placeholder.connecting') : t('placeholder.waiting');

        // Control and drawing only on the mirror.
        const capturing = !finished && !screen && (drawing || state.control === 'on');

        els.overlay.classList.toggle('is-active', capturing);
        els.overlay.classList.toggle('is-drawing', capturing && drawing);
        els.overlay.tabIndex = capturing ? 0 : -1;
        els.screen.classList.toggle('is-controlling', state.control === 'on' && !screen);

        if (state.control !== 'on') closeEditor();

        root.dispatchEvent(new CustomEvent('session-replay:stage', { detail: snapshot() }));
    }

    function phase() {
        return stagePhase({ finished, status: request.status, calls: state.calls, routes: state.routes, version: state.version, client: client?.state ?? null, connected: state.connected, hidden: state.hidden });
    }

    function snapshot() {
        return { phase: phase(), request: request.status, call: client?.state ?? null, connected: state.connected, control: state.control, audio: state.audio, video: state.video, screen: state.screen, mode: state.mode, drawing, events: feed.events, finished };
    }

    // ---- the agent's routes -----------------------------------------------------

    async function post(url, body = {}) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) throw Object.assign(new Error(data.message || `HTTP ${response.status}`), { status: response.status, data });

        return data;
    }

    /** The agent asked the visitor for something: logged next to the visitor's answer in the grants log. */
    function logAsk(what) {
        post(config.routes.asks, { what }).catch(() => {});
    }

    async function pollStatus() {
        timers.clear(statusTimer);
        statusTimer = null;

        if (finished) return;

        try {
            const response = await fetch(config.routes.status, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

            if (response.status === 401 || response.status === 403 || response.status === 419) {
                note('not_allowed');

                return finish();
            }

            if (response.ok) update(await response.json());
        } catch {
            // The next poll tries again.
        }

        if (!finished) statusTimer = timers.set(pollStatus, STATUS_POLL_MS);
    }

    function update(data) {
        // A poll that was under way when the page went away (wire:navigate) changes nothing.
        if (destroyed) return;

        const before = request.status;

        request = data;

        if (FINISHED.includes(data.status)) return finish();

        if (data.status === 'active' && data.call && state.calls && state.routes) startClient(data.call);
        if (before !== data.status && data.status === 'active') note('started');

        render();
    }

    function finish() {
        if (finished) return;

        finished = true;
        timers.clear(statusTimer);
        closeChannel('ended');
        dropClient();
        state.end();
        drawing = false;
        note('ended');
        render();
    }

    // ---- the call ---------------------------------------------------------------

    function startClient(callId) {
        if (client) return;

        const calls = config.calls;
        const signaling = new HttpSignaling({
            routes: calls.routes,
            headers: { 'X-CSRF-TOKEN': config.csrf },
            beaconFields: { _token: config.csrf },
            kind: 'assist',
        });

        client = new AssistClient(
            {
                signaling,
                media: 'none',
                video: false,
                channels: [CHANNEL_LABEL],
                iceServers: () => signaling.configuration().then((rtc) => rtc.iceServers),
                iceTransportPolicy: calls.iceTransportPolicy === 'relay' ? 'relay' : 'all',
                poll: { idle: calls.pollMs, active: calls.pollMs, hidden: calls.pollMs },
                reconnectTimeout: calls.reconnectTimeout,
                maxParticipants: 2,
            },
            timers,
        );

        // Synchronously: the visitor's hello may be the channel's first message.
        client.addEventListener('datachannel', ({ detail }) => attach(detail.channel, detail.peer));
        client.addEventListener('remotestream', ({ detail }) => showStream(detail.stream));
        client.addEventListener('localstream', () => render());
        client.addEventListener('statechange', () => render());
        client.addEventListener('peerstate', () => render());
        client.addEventListener('ended', () => {
            closeChannel('ended');
            dropClient();
            // What became of the request: the status says.
            pollStatus();
        });
        client.addEventListener('error', ({ detail }) => {
            if (detail?.media) note('media_failed');
        });

        client.resumeCall(callId);
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

        els.audio.srcObject = null;
        els.video.srcObject = null;
        els.face.srcObject = null;
    }

    function showStream(stream) {
        els.audio.srcObject = stream;
        els.video.srcObject = stream;

        const hasVideo = stream.getVideoTracks().some((track) => track.readyState === 'live');

        els.face.srcObject = hasVideo ? stream : null;
        els.face.hidden = !hasVideo || state.mode === 'screen';

        for (const media of [els.audio, els.video, els.face]) media.play?.().catch(() => {});
    }

    function attach(channel, peer) {
        if (channel.label !== CHANNEL_LABEL || String(peer?.id) !== `guest:${request.id}`) return;

        closeChannel('replaced');
        feed.reset();
        replayer = null;
        els.placeholder.hidden = false;

        endpoint = new Endpoint({
            channel,
            role: 'agent',
            schema: FROM_VISITOR,
            drain,
            onReady() {
                state.open();
                note('connected');
                render();
            },
            onMessage: receive,
            onVersion() {
                state.mismatch();
                note('version');
                render();
            },
            onClose() {
                if (endpoint?.channel !== channel) return;

                endpoint = null;
                gesture.cancel();
                state.close();
                render();
            },
        }).start();
    }

    function closeChannel(reason) {
        const old = endpoint;

        endpoint = null;
        old?.close(reason);
        state.close();
    }

    function send(message, options = {}) {
        if (message) endpoint?.send(message, options);
    }

    function receive(message) {
        const effects = handleVisitorMessage(message, { feed, state });

        // The mirror's events need no render; everything else may change the controls.
        if (message.t === 'rr' || message.t === 'css') return;

        for (const effect of effects) {
            if (effect.note) note(effect.note, effect.reason ?? null);
            if (effect.media) startMedia(effect.media);
            if (effect.stopControl) {
                // The visitor's page let go already: nothing to tell it.
                gesture.cancel();
                closeEditor();
            }
            if (effect.note === 'action_refused') closeEditor();
            if (effect.state && feed.started) fit();
        }

        render();
    }

    /** The visitor allowed audio (or video): the agent's own microphone (and camera) joins, one renegotiation. */
    async function startMedia(kind) {
        if (!client) return;

        try {
            await client.startMedia(kind);
            client.setMuted(false);
        } catch {
            note('media_failed');
        }

        if (client?.remoteStream) showStream(client.remoteStream);
        render();
    }

    // ---- controls ---------------------------------------------------------------

    buttons.control?.addEventListener('click', () => {
        if (state.control === 'on') {
            send(state.releaseControl());
            note('control_released', 'agent');
        } else {
            const message = state.askControl();

            if (message) {
                send(message);
                logAsk('control');
                note('control_asked');
            }
        }

        render();
    });

    buttons.draw?.addEventListener('click', () => {
        drawing = !drawing && state.why('draw') === null;
        render();
    });

    buttons.audio?.addEventListener('click', () => {
        const message = state.askMedia('audio');

        if (!message) return;

        send(message);
        logAsk('audio');
        note('audio_asked');
        render();
    });

    buttons.video?.addEventListener('click', () => {
        const message = state.askMedia('video');

        if (!message) return;

        send(message);
        logAsk('video');
        note('video_asked');
        render();
    });

    els.mute?.addEventListener('click', () => {
        client?.setMuted(!client.muted);
        render();
    });

    els.endAudio?.addEventListener('click', () => {
        send(state.stopMedia('video'));
        send(state.stopMedia('audio'));
        client?.setMuted(true);
        note('audio_ended');
        render();
    });

    buttons.mirror?.addEventListener('click', () => {
        state.setMode('mirror');
        render();
    });

    buttons.screen?.addEventListener('click', () => {
        if (state.screen === 'on') {
            state.setMode(state.mode === 'screen' ? 'mirror' : 'screen');
        } else {
            const message = state.askScreen();

            if (message) {
                send(message);
                logAsk('screen');
                note('screen_asked');
            }
        }

        render();
    });

    buttons.share?.addEventListener('click', async () => {
        if (!client) return;

        try {
            if (client.sharing) await client.stopSharing();
            else await client.shareScreen();
        } catch {
            // The agent closed the browser's picker.
        }

        render();
    });

    buttons.end?.addEventListener('click', async () => {
        buttons.end.disabled = true;

        try {
            const data = await post(config.routes.end);

            request = data;
        } catch (error) {
            if (error.data?.status) request = { ...request, ...error.data };
        }

        finish();
    });

    buttons.withdraw?.addEventListener('click', async () => {
        buttons.withdraw.disabled = true;

        try {
            await post(config.routes.withdraw);
        } catch {
            // Gone already: the list says what it is now.
        }

        finished = true;
        dropClient();
        location.href = config.routes.list;
    });

    // ---- the overlay: remote control and annotations ------------------------------

    let pendingMove = null;
    let moveFrame = null;

    function flushMove() {
        moveFrame = null;

        const point = pendingMove;

        pendingMove = null;

        if (!point) return;

        for (const [message, options] of gesture.move(point)) send(message, options);
    }

    function sendAll(pairs) {
        for (const [message, options] of pairs) send(message, options);
    }

    els.overlay.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;

        const point = stagePoint(event.clientX, event.clientY, frame());

        els.overlay.focus({ preventScroll: true });
        els.overlay.setPointerCapture?.(event.pointerId);
        event.preventDefault();

        if (drawing) {
            if (!point) return;

            inkStart(event);
            send({ t: 'annot.start', x: point.x, y: point.y });

            return;
        }

        if (state.control !== 'on') return;

        closeEditor();
        sendAll(gesture.down(point));
    });

    els.overlay.addEventListener('pointermove', (event) => {
        const point = stagePoint(event.clientX, event.clientY, frame());

        if (drawing) {
            if (!ink.current || !point) return;

            inkMove(event);
            send({ t: 'annot.move', x: point.x, y: point.y }, { droppable: true });

            return;
        }

        if (state.control !== 'on' || !point) return;

        pendingMove = point;
        moveFrame ??= requestAnimationFrame(flushMove);
    });

    const release = (event) => {
        if (drawing) {
            if (!ink.current) return;

            inkStop();
            send({ t: 'annot.stop' });

            return;
        }

        if (state.control !== 'on') return;

        if (moveFrame !== null) {
            cancelAnimationFrame(moveFrame);
            flushMove();
        }

        const pairs = event.type === 'pointercancel' ? gesture.cancel() : gesture.up(stagePoint(event.clientX, event.clientY, frame()));

        sendAll(pairs);

        const click = pairs.find(([message]) => message.t === 'click');

        if (click) openEditor(click[0]);
    };

    els.overlay.addEventListener('pointerup', release);
    els.overlay.addEventListener('pointercancel', release);

    els.overlay.addEventListener(
        'wheel',
        (event) => {
            if (state.control !== 'on' || drawing) return;

            event.preventDefault();

            const delta = stageDelta(event.deltaX, event.deltaY, frame());

            if (delta.dx || delta.dy) send({ t: 'scroll', dx: delta.dx, dy: delta.dy }, { droppable: false });
        },
        { passive: false },
    );

    // The keyboard scrolls the visitor's page while the agent has control; Escape lets go of the drawing tool.
    els.overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawing) {
            drawing = false;
            render();

            return;
        }

        if (state.control !== 'on' || drawing) return;

        const page = (frame()?.height || 800) * 0.8;
        const steps = { ArrowDown: [0, 60], ArrowUp: [0, -60], ArrowRight: [60, 0], ArrowLeft: [-60, 0], PageDown: [0, page], PageUp: [0, -page], ' ': [0, page] };
        const step = steps[event.key];

        if (!step) return;

        event.preventDefault();
        send({ t: 'scroll', dx: Math.round(step[0]), dy: Math.round(step[1]) });
    });

    // ---- typing: an editor over the mirrored field ---------------------------------

    function openEditor(point) {
        closeEditor();

        const doc = replayer?.iframe?.contentDocument;
        const element = doc?.elementFromPoint?.(point.x, point.y);
        const kind = editable(element);
        const id = kind ? replayer.getMirror().getId(element) : -1;

        if (!kind || !(id > 0)) return;

        const box = element.getBoundingClientRect();
        const outer = frame();
        const screen = els.screen.getBoundingClientRect();
        const scale = outer.rect.width / outer.width;

        els.editor.style.left = `${outer.rect.left - screen.left + box.left * scale}px`;
        els.editor.style.top = `${outer.rect.top - screen.top + (box.top + box.height) * scale + 4}px`;
        els.editor.style.minWidth = `${Math.max(160, box.width * scale)}px`;
        els.editorText.hidden = kind !== 'text';
        els.editorSelect.hidden = kind !== 'select';

        if (kind === 'select') {
            els.editorSelect.replaceChildren(
                ...[...(element.options || [])].map((option) => {
                    const copy = document.createElement('option');

                    copy.value = option.value;
                    copy.textContent = option.textContent;
                    copy.selected = option.selected;

                    return copy;
                }),
            );
        } else {
            // A masked field shows asterisks in the mirror: start empty rather than send them back.
            const value = typeof element.value === 'string' ? element.value : '';

            els.editorText.value = /^\*+$/.test(value) ? '' : value;
        }

        editing = { id, kind, timer: null };
        els.editor.hidden = false;
        send({ t: 'focus', id });
        (kind === 'select' ? els.editorSelect : els.editorText).focus();
    }

    function closeEditor() {
        if (!editing) return;

        clearTimeout(editing.timer);
        editing = null;
        els.editor.hidden = true;
    }

    els.editorText?.addEventListener('input', () => {
        if (!editing) return;

        clearTimeout(editing.timer);

        const { id } = editing;

        editing.timer = setTimeout(() => send({ t: 'input', id, value: els.editorText.value.slice(0, 4096) }), INPUT_DEBOUNCE_MS);
    });

    els.editorText?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== 'Escape') return;

        event.preventDefault();

        if (editing && event.key === 'Enter') {
            clearTimeout(editing.timer);
            send({ t: 'input', id: editing.id, value: els.editorText.value.slice(0, 4096) });
        }

        closeEditor();
        els.overlay.focus({ preventScroll: true });
    });

    els.editorSelect?.addEventListener('change', () => {
        if (editing) send({ t: 'select', id: editing.id, value: els.editorSelect.value });
    });

    els.editorSelect?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeEditor();
    });

    // ---- annotations: strokes drawn here and on the visitor's page, fading ------------

    function sizeInk() {
        const box = els.screen.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;

        els.ink.width = Math.round(box.width * ratio);
        els.ink.height = Math.round(box.height * ratio);
        els.ink.style.width = `${box.width}px`;
        els.ink.style.height = `${box.height}px`;
    }

    function inkPoint(event) {
        const box = els.screen.getBoundingClientRect();

        return { x: event.clientX - box.left, y: event.clientY - box.top };
    }

    function inkStart(event) {
        ink.current = { points: [inkPoint(event)], done: null };
        ink.strokes.push(ink.current);
        inkDraw();
    }

    function inkMove(event) {
        ink.current?.points.push(inkPoint(event));
        inkDraw();
    }

    function inkStop() {
        if (ink.current) ink.current.done = performance.now();

        ink.current = null;
        inkDraw();
    }

    function inkDraw() {
        ink.frame ??= requestAnimationFrame(() => {
            ink.frame = null;

            const context = els.ink.getContext('2d');

            if (!context) return;

            const ratio = window.devicePixelRatio || 1;
            const now = performance.now();

            ink.strokes = ink.strokes.filter((stroke) => stroke.done === null || now - stroke.done < INK_HOLD_MS + INK_FADE_MS);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.clearRect(0, 0, els.ink.width, els.ink.height);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = 4;
            context.strokeStyle = getComputedStyle(root).getPropertyValue('--sr-ink').trim() || '#ef4444';

            for (const stroke of ink.strokes) {
                const age = stroke.done === null ? 0 : now - stroke.done;

                context.globalAlpha = age <= INK_HOLD_MS ? 1 : Math.max(0, 1 - (age - INK_HOLD_MS) / INK_FADE_MS);
                context.beginPath();
                stroke.points.forEach((point, index) => (index ? context.lineTo(point.x, point.y) : context.moveTo(point.x, point.y)));
                context.stroke();
            }

            context.globalAlpha = 1;

            if (ink.strokes.length) inkDraw();
        });
    }

    // ---- the page ---------------------------------------------------------------

    const onResize = () => fit();
    // The agent's side of the call is free for the next page (a reload picks it up again).
    const onPageHide = () => client?.leave();

    window.addEventListener('resize', onResize);
    window.addEventListener('pagehide', onPageHide);

    /** The page goes away without unloading (wire:navigate): leave the call as on pagehide and stop; the request stays the agent's. */
    function destroy() {
        if (destroyed) return;

        destroyed = true;
        finished = true;
        timers.clear(statusTimer);
        closeChannel('unload');
        // destroy() sends the leave beacon, never a hang-up.
        dropClient();
        window.removeEventListener('resize', onResize);
        window.removeEventListener('pagehide', onPageHide);
        root.sessionReplayStageMounted = false;
    }


    sizeInk();
    render();

    if (!state.calls) note('no_calls');
    else if (!state.routes) note('no_routes');

    if (FINISHED.includes(request.status)) finish();
    else pollStatus();

    // For an app's own scripts and the end-to-end test: what the stage is doing.
    root.sessionReplayStage = {
        get state() {
            return snapshot();
        },
        get client() {
            return client;
        },
        destroy,
    };
}

function mountAll() {
    document.querySelectorAll('[data-session-replay-stage]').forEach((root) => {
        if (root.sessionReplayStageMounted) return;

        root.sessionReplayStageMounted = true;
        mountStage(root);
    });
}

function destroyAll() {
    document.querySelectorAll('[data-session-replay-stage]').forEach((root) => root.sessionReplayStage?.destroy());
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', mountAll) : mountAll();

// Panels that navigate with wire:navigate swap the page without unloading it.
document.addEventListener('livewire:navigating', destroyAll);
document.addEventListener('livewire:navigated', mountAll);
