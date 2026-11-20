/**
 * The agent's stage, the part that needs no DOM (tested with node --test):
 * the live feed into rrweb's Replayer (the clock offset, the stylesheets sent
 * apart, a fresh Replayer per channel), the pointer on the live iframe in the
 * visitor's viewport pixels, gestures to control messages, and what the
 * visitor's messages mean for the agent's controls.
 */

import { mapPoint, restoreLiveStyles } from './assist-protocol.js';
import { quietLinks } from './process.js';

// rrweb event types: what the stage looks at.
const FULL_SNAPSHOT = 2;
const META = 4;

/** How far behind the visitor the mirror plays, so a burst arriving late still plays in order. */
export const LIVE_BUFFER_MS = 300;

/** Stylesheets kept per channel: at most this many keys and characters in all; past either, a new one is dropped. */
export const MAX_LIVE_CSS_KEYS = 1000;
export const MAX_LIVE_CSS_CHARS = 32 * 1024 * 1024;

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
 * - Stylesheets are capped per channel (MAX_LIVE_CSS_KEYS, MAX_LIVE_CSS_CHARS):
 *   past the cap a new one is dropped (its events show an empty sheet).
 * - A <link> that is not a stylesheet loses its `rel` (quietLinks()): a
 *   preconnect or a prefetch would reach a host of the visitor's choosing
 *   past the stage's Content-Security-Policy.
 */
export class LiveFeed {
    /**
     * @param {object} options
     * @param {() => { startLive(baseline: number): void, addEvent(event: object): void, destroy(): void }} options.createReplayer
     * @param {() => number} [options.now]
     * @param {number} [options.bufferMs]
     * @param {() => void} [options.onStart]  the first full snapshot is in the Replayer
     */
    constructor({ createReplayer, now = () => Date.now(), bufferMs = LIVE_BUFFER_MS, onStart = () => {}, maxCssKeys = MAX_LIVE_CSS_KEYS, maxCssChars = MAX_LIVE_CSS_CHARS }) {
        this.createReplayer = createReplayer;
        this.maxCssKeys = maxCssKeys;
        this.maxCssChars = maxCssChars;
        this.cssChars = 0;
        this.now = now;
        this.bufferMs = bufferMs;
        this.onStart = onStart;
        this.replayer = null;
        this.texts = new Map();
        this.offset = null;
        this.meta = null;
        this.events = 0;
        this.links = new Set();
    }

    get started() {
        return this.replayer !== null;
    }

    /** A `css` message: the text the events point at by this key. False when it is past the caps (dropped). */
    css(key, text) {
        const previous = this.texts.get(key);
        const chars = this.cssChars - (previous?.length ?? 0) + text.length;

        if ((previous === undefined && this.texts.size >= this.maxCssKeys) || chars > this.maxCssChars) return false;

        this.texts.set(key, text);
        this.cssChars = chars;

        return true;
    }

    /** An `rr` message's event (validated already, a fresh object: it is mutated). */
    event(event) {
        restoreLiveStyles(event, this.texts);
        quietLinks(event, this.links);

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
        this.cssChars = 0;
        this.offset = null;
        this.meta = null;
        this.events = 0;
        this.links = new Set();
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

/** The agent's pointer goes to the visitor's page at most this often (about 30 a second). */
export const POINT_INTERVAL_MS = 33;

/**
 * The agent's pointer over the mirror while they only watch, as `point`
 * messages for the visitor's page ("look here": drawn there as the agent's
 * named cursor, never acted on). at(point, now) returns the messages to send
 * as [message, options] pairs: the position, at most one every `intervalMs`
 * and droppable (the first thing the channel sheds when it falls behind), or
 * `point.hide` once when the pointer leaves the page (`point` null: off the
 * mirror, the pointer turned off, control granted, the visitor's tab hidden).
 * A position held back for the interval is kept in `waiting`: ask again on the
 * next frame, so the place the pointer stopped at is the one the visitor sees.
 */
export class Pointer {
    constructor({ intervalMs = POINT_INTERVAL_MS } = {}) {
        this.intervalMs = intervalMs;
        this.reset();
    }

    at(point, now) {
        if (!point) {
            this.waiting = null;

            if (!this.shown) return [];

            this.shown = false;

            return [[{ t: 'point.hide' }, {}]];
        }

        if (now - this.sentAt < this.intervalMs) {
            this.waiting = point;

            return [];
        }

        this.waiting = null;
        this.sentAt = now;
        this.shown = true;

        return [[{ t: 'point', x: point.x, y: point.y }, { droppable: true }]];
    }

    hide() {
        return this.at(null, 0);
    }

    /** A new channel (or none): the visitor's page shows no pointer of this one. */
    reset() {
        this.sentAt = -Infinity;
        this.shown = false;
        this.waiting = null;
    }
}

/**
 * Why "Show my pointer" cannot be used now, or null: the page view's own reason (`why`: not connected, their screen
 * instead of the page…), else `no_pointer` when the live view is up and the visitor's page did not name `point` in
 * its hello (a page still on a build from before the pointer): the button must not look on while nothing is sent.
 */
export function pointerWhy({ why = null, connected = false, peerCan = false } = {}) {
    if (why !== null) return why;

    return connected && !peerCan ? 'no_pointer' : null;
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
 * version, hidden, screen_mode, asking, ended, no_video (and on_hold, which the
 * stage puts in place of connecting while the assist is on hold, and no_pointer,
 * see pointerWhy()).
 */
/** Why the visitor's microphone or camera did not start, when it was not their no (media.refused `reason`). */
const VISITOR_MEDIA_NOTES = {
    cooldown: 'cooldown',
    unavailable: 'visitor_media_unavailable',
    blocked: 'visitor_media_blocked',
    failed: 'visitor_media_failed',
};

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
        // Control the agent had when the visitor's page went (a page load, a wire:navigate swap): the next page may
        // take it over (assist.control_across_pages) with a `control.granted` that is `continued`. Until it says,
        // nothing is sent, and the agent can still let go of it.
        this.continuing = false;
        // A new channel: its first `state` settles a control still continuing (the page sends the grant before it).
        this.settling = false;
        this.audio = 'off';
        this.video = 'off';
        this.screen = 'off';
        this.mode = 'mirror';
    }

    /** The agent has control, or had it when the page went and the next one may take it over. */
    get hasControl() {
        return this.control === 'on' || this.continuing;
    }

    /** The live view is up (the peer's hello came). Control never comes with a new channel; the page grants it again or goes on with it. */
    open() {
        this.connected = true;
        this.version = false;
        this.control = 'off';
        this.settling = this.continuing;

        if (this.screen === 'asking') this.screen = 'off';
    }

    /** The channel is gone (reconnecting, the visitor's page loading the next one, the visitor left). */
    close() {
        this.connected = false;

        if (this.control === 'on') this.continuing = true;

        this.control = 'off';
    }

    /** The other side speaks another protocol version: nothing goes either way. */
    mismatch() {
        this.version = true;
        this.connected = false;
        this.control = 'off';
        this.continuing = false;
    }

    end() {
        this.ended = true;
        this.connected = false;
        this.control = 'off';
        this.continuing = false;
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
                if (this.hasControl) return null;

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
        if (this.control !== 'off' || this.continuing || this.why('control')) return null;

        this.control = 'asking';

        return { t: 'control.request' };
    }

    releaseControl() {
        // Let go of while the next page loads: nothing to send now, and a page that takes it over is told at once.
        if (this.control === 'off') {
            this.continuing = false;

            return null;
        }

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
     * page state changed), { stopControl: true } (control ended or paused),
     * { continued: true } (control went on to a new page: wait for its
     * mirror), { release: true } (send control.release: the agent let go of
     * it before the new page took it over).
     */
    receive(message) {
        switch (message.t) {
            case 'state': {
                const wasHidden = this.hidden;
                const effects = [];

                this.hidden = message.hidden;
                this.url = message.url;
                this.width = message.width;
                this.height = message.height;

                // The new page said nothing of the control the agent had before its first state: it did not go on.
                if (this.settling) {
                    this.settling = false;

                    if (this.continuing) {
                        this.continuing = false;
                        effects.push({ note: 'control_released', reason: 'navigation' });
                    }
                }

                effects.push({ state: true });

                return wasHidden === message.hidden ? effects : [...effects, { note: message.hidden ? 'visitor_hidden' : 'visitor_back' }];
            }
            case 'control.granted':
                if (message.continued) {
                    // The agent let go of it while the page loaded: given back at once.
                    if (this.control !== 'on' && !this.continuing) return [{ release: true }];

                    this.control = 'on';
                    this.continuing = false;
                    this.settling = false;

                    return [{ continued: true }, { note: 'control_continued' }];
                }

                this.control = 'on';
                this.continuing = false;

                return [{ note: 'control_granted' }];
            case 'control.released':
                // A wire:navigate swap: nothing goes to the page until it is known; then granted (continued) or released.
                if (message.paused) {
                    if (this.control !== 'on') return [];

                    this.control = 'off';
                    this.continuing = true;

                    return [{ stopControl: true }];
                }

                if (this.control === 'off') {
                    if (!this.continuing) return [];

                    this.continuing = false;

                    return [{ note: 'control_released', reason: message.reason }];
                }

                this.control = 'off';

                return [{ stopControl: true }, { note: 'control_released', reason: message.reason }];
            case 'control.refused':
                if (this.control === 'asking') {
                    this.control = 'off';

                    return [{ stopControl: true }, message.reason === 'cooldown' ? { note: 'cooldown' } : { note: 'control_refused', reason: message.reason }];
                }

                // Control stays: the visitor's page refused one action (a masked field, a password).
                return [{ note: 'action_refused', reason: message.reason }];
            case 'media.granted':
                this[message.kind] = 'on';

                return [{ media: message.kind }, { note: message.kind === 'video' ? 'video_granted' : 'audio_granted' }];
            case 'media.refused':
                this[message.kind] = 'off';

                return [{ note: Object.hasOwn(VISITOR_MEDIA_NOTES, message.reason ?? '') ? VISITOR_MEDIA_NOTES[message.reason] : (message.kind === 'video' ? 'video_refused' : 'audio_refused') }];
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

                return [{ mode: 'mirror' }, { note: message.t === 'screen.stopped' ? 'screen_stopped' : message.reason === 'cooldown' ? 'cooldown' : message.reason === 'unavailable' ? 'visitor_screen_unavailable' : 'screen_refused' }];
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
export const REASONS = ['declined', 'cooldown', 'password', 'masked', 'blocked', 'not_visible', 'readonly', 'unsupported', 'own_ui', 'unknown_node', 'unknown_option', 'visitor', 'agent', 'hidden', 'navigation', 'not_recorded', 'reconnect', 'replaced', 'ended', 'unload'];

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
 * Why the stage stops after a status poll, or null while it goes on: `finished` (the request is over) or `not_mine`
 * (the agent no longer holds it: released, claimed by someone else, or another person under the same id).
 */
export function stageStop(data) {
    if (!data || FINISHED.includes(data.status)) return 'finished';
    if (data.mine !== true) return 'not_mine';

    return null;
}

/**
 * One word for where the stage is, for a host that colours its own status badge (the
 * `session-replay:stage` event carries it): `ended`, `unavailable` (no Holler, no routes or
 * another protocol version), `waiting` (an offer or a claim the visitor's page has not taken up
 * yet), `connecting`, `reconnecting` (also while the agent is back on the live view after
 * leaving it and the new call is on its way), `on_hold` (the agent put the assist on hold, or resumed
 * another call of theirs in its place), `hidden` (live, the visitor's tab in the background) or `live`.
 */
export function stagePhase({ finished = false, status = null, reconnecting = false, calls = true, routes = true, version = false, client = null, connected = false, hidden = false, held = false } = {}) {
    if (finished || FINISHED.includes(status)) return 'ended';
    if (!calls || !routes || version) return 'unavailable';
    // Claimed again after the agent left the live view: a new call comes on the visitor's next poll.
    if (status === 'claimed' && reconnecting) return 'reconnecting';
    if (status === 'open' || status === 'claimed') return 'waiting';
    if (status !== 'active') return 'ended';
    if (held) return 'on_hold';
    if (client === 'reconnecting') return 'reconnecting';
    if (!client || ['idle', 'connecting', 'calling'].includes(client) || !connected) return 'connecting';

    return hidden ? 'hidden' : 'live';
}
