/**
 * The agent's stage, the part that needs no DOM (tested with node --test):
 * the live feed into rrweb's Replayer (the clock offset, the stylesheets sent
 * apart, a fresh Replayer per channel), the pointer on the live iframe in the
 * visitor's viewport pixels, gestures to control messages, and what the
 * visitor's messages mean for the agent's controls.
 */

import { mapPoint, restoreLiveStyles } from './assist-protocol.js';

// rrweb event types: what the stage looks at.
const FULL_SNAPSHOT = 2;
const META = 4;

/** How far behind the visitor the mirror plays, so a burst arriving late still plays in order. */
export const LIVE_BUFFER_MS = 300;

/**
 * The live feed of one data channel into a Replayer made for it.
 *
 * - The clock offset (the agent's Date.now() minus the first event's
 *   timestamp) is measured on the first event and added to every event:
 *   a visitor's clock a minute ahead would otherwise make the Replayer wait
 *   a minute.
 * - Stylesheets come as `css` messages before the events that point at them
 *   (`/*sr-live:<key>*\/`); they are put back before the event is played.
 * - Nothing is drawn before a full snapshot: the Meta event in front of it is
 *   kept, anything else before it is dropped (the visitor sends a fresh
 *   snapshot when the channel opens).
 * - reset() for a new channel: the visitor's page may have reloaded, its node
 *   ids start again, so the Replayer starts again too.
 */
export class LiveFeed {
    /**
     * @param {object} options
     * @param {() => { startLive(baseline: number): void, addEvent(event: object): void, destroy(): void }} options.createReplayer
     * @param {() => number} [options.now]
     * @param {number} [options.bufferMs]
     * @param {() => void} [options.onStart]  the first full snapshot is in the Replayer
     */
    constructor({ createReplayer, now = () => Date.now(), bufferMs = LIVE_BUFFER_MS, onStart = () => {} }) {
        this.createReplayer = createReplayer;
        this.now = now;
        this.bufferMs = bufferMs;
        this.onStart = onStart;
        this.replayer = null;
        this.texts = new Map();
        this.offset = null;
        this.meta = null;
        this.events = 0;
    }

    get started() {
        return this.replayer !== null;
    }

    /** A `css` message: the text the events point at by this key. */
    css(key, text) {
        this.texts.set(key, text);
    }

    /** An `rr` message's event (validated already, a fresh object: it is mutated). */
    event(event) {
        restoreLiveStyles(event, this.texts);

        if (this.offset === null) this.offset = this.now() - event.timestamp;

        event.timestamp += this.offset;

        if (!this.replayer) {
            if (event.type === META) {
                this.meta = event;

                return;
            }

            if (event.type !== FULL_SNAPSHOT) return;

            this.replayer = this.createReplayer();
            this.replayer.startLive((this.meta ?? event).timestamp - this.bufferMs);

            if (this.meta) this.replayer.addEvent(this.meta);

            this.meta = null;
            this.replayer.addEvent(event);
            this.events++;
            this.onStart();

            return;
        }

        this.replayer.addEvent(event);
        this.events++;
    }

    /** A new channel: a new Replayer from its first snapshot, a new offset, its own stylesheets. */
    reset() {
        try {
            this.replayer?.destroy();
        } catch {
            // Half built: let go of it all the same.
        }

        this.replayer = null;
        this.texts = new Map();
        this.offset = null;
        this.meta = null;
        this.events = 0;
    }
}

/**
 * A point on the stage in the visitor's viewport pixels, or null outside the
 * page. `frame` is the live replay iframe as { rect, width, height }: its box
 * on screen (scaled) and the viewport it replays (rrweb sets the iframe's
 * width and height from the snapshot's Meta event). Never the recording's
 * viewport columns: those are the first upload's only.
 */
export function stagePoint(clientX, clientY, frame) {
    if (!frame || !frame.rect || !(frame.width > 0) || !(frame.height > 0) || !(frame.rect.width > 0) || !(frame.rect.height > 0)) return null;

    const point = mapPoint(clientX, clientY, frame.rect, frame.width, frame.height);

    if (point.x < 0 || point.y < 0 || point.x > frame.width || point.y > frame.height) return null;

    return point;
}

/** A wheel's deltas in the visitor's pixels (the stage is scaled). */
export function stageDelta(dx, dy, frame) {
    const scale = frame && frame.rect?.width > 0 && frame.width > 0 ? frame.width / frame.rect.width : 1;

    return { dx: Math.round(dx * scale), dy: Math.round(dy * scale) };
}

/**
 * Pointer events on the overlay as control messages: a press that does not
 * move is a click where it was pressed, one that moves past `threshold`
 * visitor pixels is a drag (drag.start where it began, drag along the way,
 * drag.stop at the end), and moving without a press is `move` (droppable:
 * the channel sheds the oldest pointer samples when it falls behind).
 * Each method returns the messages to send, as [message, options] pairs.
 */
export class Gesture {
    constructor({ threshold = 4 } = {}) {
        this.threshold = threshold;
        this.start = null;
        this.dragging = false;
    }

    down(point) {
        this.start = point;
        this.dragging = false;

        return point ? [[{ t: 'move', x: point.x, y: point.y }, { droppable: true }]] : [];
    }

    move(point) {
        if (!point) return [];
        if (!this.start) return [[{ t: 'move', x: point.x, y: point.y }, { droppable: true }]];

        if (!this.dragging) {
            if (Math.hypot(point.x - this.start.x, point.y - this.start.y) < this.threshold) return [];

            this.dragging = true;

            return [
                [{ t: 'drag.start', x: this.start.x, y: this.start.y }, {}],
                [{ t: 'drag', x: point.x, y: point.y }, {}],
            ];
        }

        return [[{ t: 'drag', x: point.x, y: point.y }, { droppable: true }]];
    }

    up(point) {
        const start = this.start;
        const dragging = this.dragging;

        this.start = null;
        this.dragging = false;

        if (dragging) return [[{ t: 'drag.stop' }, {}]];
        if (!start) return [];

        // Released outside the page: still a click where it was pressed.
        return [[{ t: 'click', x: start.x, y: start.y }, {}]];
    }

    cancel() {
        const dragging = this.dragging;

        this.start = null;
        this.dragging = false;

        return dragging ? [[{ t: 'drag.stop' }, {}]] : [];
    }
}

/** What a mirrored element accepts from the agent: 'text', 'select' or null. Works on any element-like object. */
export function editable(element) {
    const tag = String(element?.tagName || '').toLowerCase();

    if (tag === 'textarea') return 'text';
    if (tag === 'select') return 'select';
    if (tag !== 'input') return null;

    const type = String(element.type || element.getAttribute?.('type') || 'text').toLowerCase();

    return ['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image', 'range', 'color', 'hidden'].includes(type) ? null : 'text';
}

/**
 * What the agent may do and why not, from what the visitor's page said over
 * the channel. receive() takes every validated message from the visitor and
 * returns the effects for the page (start the agent's own media, switch the
 * view, show a note); the ask*() methods return the message to send, or null
 * when the action is not available now (then `why()` says why).
 *
 * Reasons (label keys under stage.why): no_calls, no_routes, connecting,
 * version, hidden, screen_mode, asking, ended, no_video.
 */
export class AgentState {
    constructor({ calls = true, routes = true, video = false } = {}) {
        this.calls = calls;
        this.routes = routes;
        this.videoAllowed = video;
        this.connected = false;
        this.version = false;
        this.ended = false;
        this.hidden = false;
        this.url = null;
        this.width = 0;
        this.height = 0;
        this.control = 'off';
        this.audio = 'off';
        this.video = 'off';
        this.screen = 'off';
        this.mode = 'mirror';
    }

    /** The live view is up (the peer's hello came). Control never survives a new channel. */
    open() {
        this.connected = true;
        this.version = false;
        this.control = 'off';

        if (this.screen === 'asking') this.screen = 'off';
    }

    /** The channel is gone (reconnecting, the visitor left). */
    close() {
        this.connected = false;
        this.control = 'off';
    }

    /** The other side speaks another protocol version: nothing goes either way. */
    mismatch() {
        this.version = true;
        this.connected = false;
        this.control = 'off';
    }

    end() {
        this.ended = true;
        this.connected = false;
        this.control = 'off';
        this.audio = 'off';
        this.video = 'off';
        this.screen = 'off';
        this.mode = 'mirror';
    }

    /** Why an action is not available now, or null when it is. */
    why(action) {
        if (this.ended) return 'ended';
        if (!this.calls) return 'no_calls';
        if (!this.routes) return 'no_routes';
        if (action === 'share') return this.connected ? null : 'connecting';
        if (this.version) return 'version';
        if (!this.connected) return 'connecting';

        switch (action) {
            case 'control':
                if (this.mode !== 'mirror') return 'screen_mode';
                if (this.control === 'asking') return 'asking';
                if (this.control === 'on') return null;

                return this.hidden ? 'hidden' : null;
            case 'draw':
                return this.mode !== 'mirror' ? 'screen_mode' : null;
            case 'audio':
                return this.audio === 'asking' ? 'asking' : null;
            case 'video':
                if (!this.videoAllowed) return 'no_video';

                return this.video === 'asking' ? 'asking' : null;
            case 'screen':
                if (this.screen === 'asking') return 'asking';

                return this.hidden && this.screen === 'off' ? 'hidden' : null;
            default:
                return null;
        }
    }

    askControl() {
        if (this.control !== 'off' || this.why('control')) return null;

        this.control = 'asking';

        return { t: 'control.request' };
    }

    releaseControl() {
        if (this.control === 'off') return null;

        this.control = 'off';

        return { t: 'control.release' };
    }

    askMedia(kind) {
        if (this[kind] !== 'off' || this.why(kind)) return null;

        this[kind] = 'asking';

        return { t: 'media.request', kind };
    }

    stopMedia(kind) {
        if (this[kind] === 'off') return null;

        this[kind] = 'off';

        return { t: 'media.stop', kind };
    }

    askScreen() {
        if (this.screen !== 'off' || this.why('screen')) return null;

        this.screen = 'asking';

        return { t: 'screen.request' };
    }

    stopScreen() {
        if (this.screen === 'off') return null;

        this.screen = 'off';
        this.mode = 'mirror';

        return { t: 'screen.stop' };
    }

    /** Mirror or the visitor's screen: the screen only while they share it. */
    setMode(mode) {
        this.mode = mode === 'screen' && this.screen === 'on' ? 'screen' : 'mirror';

        return this.mode;
    }

    /**
     * One message from the visitor. Effects: { note: key, reason? } for the
     * agent to read, { media: kind } (start the agent's own media of that
     * kind), { mode } (the view switched), { state: true } (the visitor's
     * page state changed), { stopControl: true } (control ended).
     */
    receive(message) {
        switch (message.t) {
            case 'state': {
                const wasHidden = this.hidden;

                this.hidden = message.hidden;
                this.url = message.url;
                this.width = message.width;
                this.height = message.height;

                return wasHidden === message.hidden ? [{ state: true }] : [{ state: true }, { note: message.hidden ? 'visitor_hidden' : 'visitor_back' }];
            }
            case 'control.granted':
                this.control = 'on';

                return [{ note: 'control_granted' }];
            case 'control.released':
                if (this.control === 'off') return [];

                this.control = 'off';

                return [{ stopControl: true }, { note: 'control_released', reason: message.reason }];
            case 'control.refused':
                if (this.control === 'asking') {
                    this.control = 'off';

                    return [{ stopControl: true }, { note: 'control_refused', reason: message.reason }];
                }

                // Control stays: the visitor's page refused one action (a masked field, a password).
                return [{ note: 'action_refused', reason: message.reason }];
            case 'media.granted':
                this[message.kind] = 'on';

                return [{ media: message.kind }, { note: message.kind === 'video' ? 'video_granted' : 'audio_granted' }];
            case 'media.refused':
                this[message.kind] = 'off';

                return [{ note: message.kind === 'video' ? 'video_refused' : 'audio_refused' }];
            case 'media.stopped':
                this[message.kind] = 'off';

                return [{ note: 'media_stopped' }];
            case 'screen.granted':
                this.screen = 'on';
                this.mode = 'screen';

                return [{ mode: 'screen' }, { note: 'screen_granted' }];
            case 'screen.refused':
            case 'screen.stopped':
                this.screen = 'off';
                this.mode = 'mirror';

                return [{ mode: 'mirror' }, { note: message.t === 'screen.refused' ? 'screen_refused' : 'screen_stopped' }];
            case 'bye':
                return [{ note: 'visitor_left' }];
            default:
                return [];
        }
    }
}

/**
 * One validated message from the visitor's page on the stage: the mirror's
 * events and stylesheets go to the feed, everything else to the agent's
 * state. Returns the state's effects (see AgentState.receive()).
 */
export function handleVisitorMessage(message, { feed, state }) {
    if (message.t === 'rr') {
        feed.event(message.e);

        return [];
    }

    if (message.t === 'css') {
        feed.css(message.key, message.text);

        return [];
    }

    return state.receive(message);
}

/** The label key for a reason the visitor's page gave, or 'other'. */
export const REASONS = ['declined', 'password', 'masked', 'readonly', 'unsupported', 'own_ui', 'unknown_node', 'unknown_option', 'visitor', 'agent', 'hidden', 'navigation', 'reconnect', 'replaced', 'ended', 'unload'];

export function reasonKey(reason) {
    return REASONS.includes(reason) ? reason : 'other';
}

/** "12 s", "3 min" for the visitor's last sign of life; the stage and the list say it plainly instead of "live". */
export function ago(seconds) {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) return null;

    const value = Math.max(0, Math.round(seconds));

    return value < 60 ? { unit: 's', value } : { unit: 'min', value: Math.floor(value / 60) };
}

/** A request that can no longer be helped with. */
export const FINISHED = ['cancelled', 'declined', 'expired', 'withdrawn', 'ended'];

/**
 * One word for where the stage is, for a host that colours its own status badge (the
 * `session-replay:stage` event carries it): `ended`, `unavailable` (no Calls, no routes or
 * another protocol version), `waiting` (an offer or a claim the visitor's page has not taken up
 * yet), `connecting`, `reconnecting`, `hidden` (live, the visitor's tab in the background) or `live`.
 */
export function stagePhase({ finished = false, status = null, calls = true, routes = true, version = false, client = null, connected = false, hidden = false } = {}) {
    if (finished || FINISHED.includes(status)) return 'ended';
    if (!calls || !routes || version) return 'unavailable';
    if (status === 'open' || status === 'claimed') return 'waiting';
    if (status !== 'active') return 'ended';
    if (client === 'reconnecting') return 'reconnecting';
    if (!client || ['idle', 'connecting', 'calling'].includes(client) || !connected) return 'connecting';

    return hidden ? 'hidden' : 'live';
}
