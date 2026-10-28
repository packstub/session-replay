/**
 * The Assist channel protocol, shared by the visitor's page (the recorder) and
 * the agent's stage. One ordered data channel, label "assist", JSON text
 * messages `{ t, ... }`. Pure: no DOM, so it runs in Node for the tests.
 *
 * - First message each way: `{ t: 'hello', v, role }`. A side that gets another
 *   major version stops (onVersion) and sends nothing else; nothing but a hello
 *   is taken before the peer's hello.
 * - A message above MAX_MESSAGE_BYTES (browsers disagree above about 256 KB, a
 *   full snapshot is often more) goes as `{ t: 'part', id, i, n, d }` pieces,
 *   joined in order on the other side.
 * - The sender waits on the channel's `bufferedamountlow` (drain()) instead of
 *   pushing blindly; while it waits, queued pointer samples are dropped first.
 * - Every message is validated against the schema of the side it comes from;
 *   anything else is dropped and counted, and past BAD_MESSAGE_BUDGET the
 *   channel is closed. Nothing on the channel is trusted beyond the peer's
 *   identity, which signaling checked.
 *
 * From the visitor: hello, rr (one rrweb event in `e`, already masked), css
 * (`key`, `text`: a stylesheet the events point at with liveStylePlaceholder),
 * state (`hidden`, `url`, `width`, `height`), control.granted,
 * control.released (`reason`), control.refused (`reason`), media.granted /
 * media.refused / media.stopped (`kind`; refused also `reason`),
 * screen.granted / screen.refused (`reason`) / screen.stopped, annot.ack, bye.
 *
 * From the agent: hello, control.request, control.release, move / click /
 * drag.start / drag (`x`, `y` in the visitor's viewport pixels), scroll
 * (`dx`, `dy`, at the cursor), drag.stop, focus (`id`, an rrweb node id),
 * input / select (`id`, `value`), annot.start / annot.move (`x`, `y`),
 * annot.stop, media.request / media.stop (`kind`: audio or video),
 * screen.request, screen.stop, bye.
 */

import { styleSlots } from './process.js';

export const PROTOCOL_VERSION = 1;
export const CHANNEL_LABEL = 'assist';
export const MAX_MESSAGE_BYTES = 64 * 1024;
export const MAX_VALUE_CHARS = 4096;
export const BAD_MESSAGE_BUDGET = 20;
// A message put back together from parts may be large (a full snapshot), not unbounded.
export const MAX_ASSEMBLED_CHARS = 32 * 1024 * 1024;
export const MAX_PARTS = 2048;
// Stylesheets shorter than this stay inline in the events.
export const LIVE_STYLE_MIN_CHARS = 2048;
/**
 * What the agent's stage takes from the visitor's page per channel: a token bucket over the characters received,
 * refilled at `bytesPerSecond` up to `burstBytes` (room for a full snapshot of MAX_ASSEMBLED_CHARS and its
 * stylesheets). A page past it is closed with reason `too_fast`; the replay would only grow without end.
 */
export const AGENT_RATE = { bytesPerSecond: 4 * 1024 * 1024, burstBytes: 48 * 1024 * 1024 };

const LIVE_STYLE_PREFIX = '/*sr-live:';
const LIVE_STYLE_SUFFIX = '*/';
const LIVE_STYLE_PATTERN = /^\/\*sr-live:([A-Za-z0-9_-]{1,64})\*\/$/;
const KEY_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
const REASON_PATTERN = /^[a-z_]{1,32}$/;
const MAX_CSS_CHARS = 8 * 1024 * 1024;
const MAX_URL_CHARS = 2048;
const MAX_DELTA = 100_000;

// ---- encoding ---------------------------------------------------------------

/** UTF-8 length of a string, without encoding it. */
export function utf8Length(text) {
    let bytes = 0;

    for (let i = 0; i < text.length; i++) {
        const code = text.charCodeAt(i);

        if (code < 0x80) bytes += 1;
        else if (code < 0x800) bytes += 2;
        else if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length && (text.charCodeAt(i + 1) & 0xfc00) === 0xdc00) {
            bytes += 4;
            i++;
        } else bytes += 3;
    }

    return bytes;
}

export function encode(message) {
    return JSON.stringify(message);
}

/**
 * An rrweb event that was stringified at the tap, as an `rr` message, without
 * escaping it a second time.
 */
export function encodeEvent(eventJson) {
    return '{"t":"rr","e":' + eventJson + '}';
}

/**
 * The frames a message goes out as: itself, or `part` frames each under
 * `maxBytes` once encoded (escapes included), never splitting a surrogate pair.
 */
export function split(text, id, maxBytes = MAX_MESSAGE_BYTES) {
    if (text.length * 3 <= maxBytes || utf8Length(text) <= maxBytes) return [text];

    // Room for the frame around the piece: {"t":"part","id":…,"i":…,"n":…,"d":"…"}.
    const budget = maxBytes - 96;
    const pieces = [];
    let start = 0;

    while (start < text.length) {
        let size = Math.min(16384, text.length - start);

        for (;;) {
            let end = start + size;

            if (end < text.length && (text.charCodeAt(end - 1) & 0xfc00) === 0xd800) end--;

            const piece = text.slice(start, end);

            if (utf8Length(JSON.stringify(piece)) <= budget || size <= 1) {
                pieces.push(piece);
                start = end;
                break;
            }

            size = Math.max(1, Math.floor(size / 2));
        }
    }

    return pieces.map((d, i) => JSON.stringify({ t: 'part', id, i, n: pieces.length, d }));
}

/**
 * Puts `part` frames back together. Parts of one message arrive in order and
 * together (one ordered channel, one sender at a time); anything else is bad.
 */
export class Reassembler {
    constructor({ maxChars = MAX_ASSEMBLED_CHARS, maxParts = MAX_PARTS } = {}) {
        this.maxChars = maxChars;
        this.maxParts = maxParts;
        this.current = null;
    }

    /** The whole text once the last part is in, null while more are due, false for a bad part. */
    push(part) {
        const { id, i, n, d } = part;

        if (!Number.isSafeInteger(id) || !Number.isInteger(i) || !Number.isInteger(n) || n < 2 || n > this.maxParts || i < 0 || i >= n || typeof d !== 'string') {
            this.current = null;

            return false;
        }

        if (i === 0) {
            this.current = { id, n, pieces: [d], chars: d.length };
        } else if (!this.current || this.current.id !== id || this.current.n !== n || this.current.pieces.length !== i) {
            this.current = null;

            return false;
        } else {
            this.current.pieces.push(d);
            this.current.chars += d.length;
        }

        if (this.current.chars > this.maxChars) {
            this.current = null;

            return false;
        }

        if (this.current.pieces.length < n) return null;

        const whole = this.current.pieces.join('');

        this.current = null;

        return whole;
    }
}

// ---- live stylesheets -------------------------------------------------------

export function liveStylePlaceholder(key) {
    return LIVE_STYLE_PREFIX + key + LIVE_STYLE_SUFFIX;
}

export function liveStyleKey(text) {
    const match = typeof text === 'string' && text.length <= 80 ? LIVE_STYLE_PATTERN.exec(text) : null;

    return match ? match[1] : null;
}

/**
 * The visitor's side: a stylesheet goes over the channel once (a `css`
 * message), the events point at it by a key that is unique for this channel.
 * Keyed by the text itself, synchronously: hashing is asynchronous and the
 * tap must not reorder events. A new channel starts a new LiveStyles.
 */
export class LiveStyles {
    constructor(minChars = LIVE_STYLE_MIN_CHARS) {
        this.minChars = minChars;
        this.keys = new Map();
    }

    /** Whether the event holds a stylesheet long enough to send apart. */
    holds(event) {
        return styleSlots(event, this.minChars).length > 0;
    }

    /** Swap the stylesheets of `event` (a copy: it is mutated) for placeholders; returns the css messages to send first. */
    strip(event) {
        const messages = [];

        for (const slot of styleSlots(event, this.minChars)) {
            let key = this.keys.get(slot.text);

            if (key === undefined) {
                key = (this.keys.size + 1).toString(36);
                this.keys.set(slot.text, key);
                messages.push({ t: 'css', key, text: slot.text });
            }

            slot.holder[slot.key] = liveStylePlaceholder(key);
        }

        return messages;
    }
}

/** The agent's side: put the stylesheets back (Map key → text); an unknown key becomes an empty sheet. Mutates. */
export function restoreLiveStyles(event, texts) {
    for (const slot of styleSlots(event)) {
        const key = liveStyleKey(slot.text);

        if (key !== null) slot.holder[slot.key] = texts.get(key) ?? '';
    }

    return event;
}

/**
 * The agent's side: a point on the live replay iframe in the visitor's
 * viewport pixels (rrweb scales the iframe from the snapshot's dimensions).
 */
export function mapPoint(clientX, clientY, rect, width, height) {
    return {
        x: Math.round(((clientX - rect.left) * width) / rect.width),
        y: Math.round(((clientY - rect.top) * height) / rect.height),
    };
}

// ---- validation -------------------------------------------------------------

const ok = (message) => ({ ok: true, message });
// `bad: false`: dropped, but plausible (a point just outside after a resize), so not counted.
const drop = (bad = true) => ({ ok: false, bad });

const finite = (value) => typeof value === 'number' && Number.isFinite(value);
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const reason = (value) => (typeof value === 'string' && REASON_PATTERN.test(value) ? value : null);

function point(message, ctx) {
    if (!finite(message.x) || !finite(message.y)) return drop();

    const { width, height } = ctx.viewport();

    if (message.x < 0 || message.y < 0 || message.x > width || message.y > height) return drop(false);

    return ok({ t: message.t, x: message.x, y: message.y });
}

function node(message, ctx) {
    if (!Number.isSafeInteger(message.id) || message.id < 0) return drop();

    return ctx.knows(message.id) ? ok({ t: message.t, id: message.id }) : drop(false);
}

function value(message, ctx) {
    const known = node(message, ctx);

    if (!known.ok) return known;
    if (typeof message.value !== 'string' || message.value.length > MAX_VALUE_CHARS) return drop();

    return ok({ ...known.message, value: message.value });
}

const bare = (message) => ok({ t: message.t });

function mediaKind(message) {
    return message.kind === 'audio' || message.kind === 'video' ? ok({ t: message.t, kind: message.kind }) : drop();
}

/** What the visitor's page takes from the agent. ctx: { viewport() → { width, height }, knows(id) → bool }. */
export const FROM_AGENT = {
    'control.request': bare,
    'control.release': bare,
    move: point,
    click: point,
    'drag.start': point,
    drag: point,
    'drag.stop': bare,
    scroll: (message) => (finite(message.dx) && finite(message.dy) && Math.abs(message.dx) <= MAX_DELTA && Math.abs(message.dy) <= MAX_DELTA ? ok({ t: 'scroll', dx: message.dx, dy: message.dy }) : drop()),
    focus: node,
    input: value,
    select: value,
    'annot.start': point,
    'annot.move': point,
    'annot.stop': bare,
    'media.request': mediaKind,
    'media.stop': mediaKind,
    'screen.request': bare,
    'screen.stop': bare,
    bye: bare,
};

/** What the agent's stage takes from the visitor. ctx is not needed. */
export const FROM_VISITOR = {
    rr: (message) => {
        const event = message.e;

        return isObject(event) && Number.isInteger(event.type) && event.type >= 0 && event.type <= 7 && finite(event.timestamp) ? ok({ t: 'rr', e: event }) : drop();
    },
    css: (message) => (typeof message.key === 'string' && KEY_PATTERN.test(message.key) && typeof message.text === 'string' && message.text.length <= MAX_CSS_CHARS ? ok({ t: 'css', key: message.key, text: message.text }) : drop()),
    state: (message) => {
        if (typeof message.hidden !== 'boolean' || !finite(message.width) || !finite(message.height) || message.width < 0 || message.height < 0 || message.width > MAX_DELTA || message.height > MAX_DELTA) return drop();
        if (message.url !== null && message.url !== undefined && (typeof message.url !== 'string' || message.url.length > MAX_URL_CHARS)) return drop();

        return ok({ t: 'state', hidden: message.hidden, url: message.url ?? null, width: message.width, height: message.height });
    },
    'control.granted': bare,
    'control.released': (message) => ok({ t: message.t, reason: reason(message.reason) }),
    'control.refused': (message) => ok({ t: message.t, reason: reason(message.reason) }),
    'media.granted': mediaKind,
    // `reason`: 'declined', or 'cooldown' when the visitor said no to the same a moment ago (asked again too soon).
    'media.refused': (message) => {
        const result = mediaKind(message);

        return result.ok ? ok({ ...result.message, reason: reason(message.reason) }) : result;
    },
    'media.stopped': mediaKind,
    'screen.granted': bare,
    'screen.refused': (message) => ok({ t: message.t, reason: reason(message.reason) }),
    'screen.stopped': bare,
    'annot.ack': bare,
    bye: bare,
};

/**
 * Check one parsed message against a schema (FROM_AGENT or FROM_VISITOR):
 * `{ ok: true, message }` with only the known fields, or `{ ok: false, bad }`.
 */
export function validate(message, schema, ctx = {}) {
    if (!isObject(message) || typeof message.t !== 'string' || !Object.prototype.hasOwnProperty.call(schema, message.t)) return drop();

    return schema[message.t](message, ctx);
}

// ---- the endpoint -----------------------------------------------------------

/**
 * One side of the channel: the hello, framing, backpressure and validation
 * around an RTCDataChannel (or anything with send, readyState,
 * bufferedAmount, bufferedAmountLowThreshold, addEventListener, close).
 *
 * Callbacks: onReady() once the peer's hello came, onMessage(message) for
 * every valid message after it, onVersion(v) for a peer on another major
 * version, onClose(reason) once.
 *
 * `rate` ({ bytesPerSecond, burstBytes }, see AGENT_RATE): a byte budget for
 * what comes in; past it the channel is closed with reason `too_fast`.
 */
export class Endpoint {
    constructor({ channel, role, schema, context = {}, drain, onReady = () => {}, onMessage = () => {}, onVersion = () => {}, onClose = () => {}, budget = BAD_MESSAGE_BUDGET, maxBytes = MAX_MESSAGE_BYTES, rate = null, now = () => Date.now() }) {
        this.channel = channel;
        this.role = role;
        this.schema = schema;
        this.context = context;
        this.drain = drain;
        this.onReady = onReady;
        this.onMessage = onMessage;
        this.onVersion = onVersion;
        this.onClose = onClose;
        this.budget = budget;
        this.maxBytes = maxBytes;
        this.queue = [];
        this.parts = new Reassembler();
        this.nextPart = 1;
        this.bad = 0;
        this.ready = false;
        this.blocked = false;
        this.closed = false;
        this.pumping = false;
        this.peerVersion = null;
        this.rate = rate;
        this.now = now;
        this.allowance = rate ? rate.burstBytes : 0;
        this.refilledAt = null;
        this.listeners = {
            message: (event) => this.receive(event.data),
            close: () => this.close('closed', false),
        };
    }

    start() {
        this.channel.addEventListener('message', this.listeners.message);
        this.channel.addEventListener('close', this.listeners.close);
        this.write(encode({ t: 'hello', v: PROTOCOL_VERSION, role: this.role }));

        return this;
    }

    /** Queue a message; `droppable` ones (pointer samples) go first when the channel falls behind. */
    send(message, options = {}) {
        this.sendRaw(encode(message), options);
    }

    sendRaw(text, { droppable = false } = {}) {
        if (this.closed || this.blocked) return;

        this.queue.push({ text, droppable });
        this.pump();
    }

    get behind() {
        return this.channel.bufferedAmount > (this.channel.bufferedAmountLowThreshold || 0);
    }

    /** Keep every message that matters and the newest pointer sample. */
    shed() {
        let last = -1;

        this.queue.forEach((item, index) => {
            if (item.droppable) last = index;
        });

        this.queue = this.queue.filter((item, index) => !item.droppable || index === last);
    }

    async pump() {
        if (this.pumping) return;

        this.pumping = true;

        try {
            while (this.queue.length && this.ready && !this.closed && !this.blocked) {
                if (this.behind) {
                    this.shed();
                    await this.drain(this.channel);
                    // What piled up while waiting: still only the newest pointer sample.
                    this.shed();
                    continue;
                }

                const item = this.queue.shift();
                const frames = split(item.text, this.nextPart, this.maxBytes);

                if (frames.length > 1) this.nextPart++;

                for (const frame of frames) {
                    if (this.closed) return;
                    if (this.behind) await this.drain(this.channel);
                    if (!this.write(frame)) return;
                }
            }
        } finally {
            this.pumping = false;
        }
    }

    write(frame) {
        if (this.closed || this.channel.readyState !== 'open') return false;

        try {
            this.channel.send(frame);

            return true;
        } catch {
            this.close('send_failed');

            return false;
        }
    }

    receive(data) {
        if (this.closed || this.blocked) return;

        // Every frame is under maxBytes on the wire; a string longer than that in characters is longer in bytes too.
        if (typeof data !== 'string' || data.length > this.maxBytes + 256) return this.count();
        if (this.rate && !this.spend(data.length)) return this.close('too_fast');

        let message;

        try {
            message = JSON.parse(data);
        } catch {
            return this.count();
        }

        if (message && message.t === 'part') {
            const whole = this.parts.push(message);

            if (whole === false) return this.count();
            if (whole === null) return;

            try {
                message = JSON.parse(whole);
            } catch {
                return this.count();
            }

            if (message && message.t === 'part') return this.count();
        }

        if (message && message.t === 'hello') return this.hello(message);

        if (!this.ready) return this.count();

        const result = validate(message, this.schema, this.context);

        if (!result.ok) {
            if (result.bad) this.count();

            return;
        }

        try {
            this.onMessage(result.message);
        } catch {
            // A handler that throws is a bug on this side, not a bad message.
        }
    }

    hello(message) {
        if (this.ready || !Number.isInteger(message.v)) return this.count();

        this.peerVersion = message.v;

        if (message.v !== PROTOCOL_VERSION) {
            // "Update the other side": nothing else goes either way.
            this.blocked = true;
            this.queue = [];
            this.onVersion(message.v);

            return;
        }

        this.ready = true;
        this.onReady();
        this.pump();
    }

    count() {
        if (++this.bad > this.budget) this.close('bad_messages');
    }

    /** The rate budget: false once the peer sent more than it allows. */
    spend(size) {
        const now = this.now();

        if (this.refilledAt !== null) this.allowance = Math.min(this.rate.burstBytes, this.allowance + ((now - this.refilledAt) / 1000) * this.rate.bytesPerSecond);

        this.refilledAt = now;
        this.allowance -= size;

        return this.allowance >= 0;
    }

    close(why = 'closed', closeChannel = true) {
        if (this.closed) return;

        this.closed = true;
        this.queue = [];
        this.channel.removeEventListener?.('message', this.listeners.message);
        this.channel.removeEventListener?.('close', this.listeners.close);

        if (closeChannel) {
            try {
                this.channel.close();
            } catch {
                // Already closed.
            }
        }

        this.onClose(why);
    }
}
