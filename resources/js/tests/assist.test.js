import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    BAD_MESSAGE_BUDGET,
    Endpoint,
    FROM_AGENT,
    FROM_VISITOR,
    LiveStyles,
    MAX_MESSAGE_BYTES,
    PROTOCOL_VERSION,
    Reassembler,
    encodeEvent,
    liveStyleKey,
    mapPoint,
    restoreLiveStyles,
    split,
    utf8Length,
    validate,
} from '../lib/assist-protocol.js';
import { AssistSignaling } from '../lib/assist-signaling.js';
import { RemoteControl, maskedField } from '../lib/assist-control.js';

// ---- a fake data channel pair ------------------------------------------------

class FakeChannel {
    constructor() {
        this.readyState = 'open';
        this.bufferedAmount = 0;
        this.bufferedAmountLowThreshold = 1024 * 1024;
        this.sent = [];
        this.listeners = { message: [], close: [], bufferedamountlow: [] };
        this.peer = null;
    }

    removeEventListener(type, fn) {
        this.listeners[type] = this.listeners[type].filter((entry) => entry !== fn);
    }

    send(data) {
        this.sent.push(data);
        this.peer?.deliver(data);
    }

    deliver(data) {
        // Like a channel whose page has not attached its handler yet: kept until one listens.
        if (this.listeners.message.length === 0) return (this.backlog ||= []).push(data);

        for (const fn of [...this.listeners.message]) fn({ data });
    }

    addEventListener(type, fn) {
        this.listeners[type].push(fn);

        if (type === 'message' && this.backlog) for (const data of this.backlog.splice(0)) fn({ data });
    }

    close() {
        this.readyState = 'closed';
        for (const fn of [...this.listeners.close]) fn();
    }
}

function pair() {
    const a = new FakeChannel();
    const b = new FakeChannel();

    a.peer = b;
    b.peer = a;

    return [a, b];
}

const viewport = { viewport: () => ({ width: 1280, height: 800 }), knows: (id) => id === 42 || id === 7 };
const tick = () => new Promise((resolve) => setImmediate(resolve));

// ---- encoding and chunking ------------------------------------------------------

test('utf8Length counts bytes without encoding', () => {
    assert.equal(utf8Length('abc'), 3);
    assert.equal(utf8Length('ă'), 2);
    assert.equal(utf8Length('€'), 3);
    assert.equal(utf8Length('😀'), 4);
    assert.equal(utf8Length('😀'), new TextEncoder().encode('😀').length);
});

test('split leaves a small message alone and cuts a large one into parts that join back', () => {
    assert.deepEqual(split('{"t":"bye"}', 1), ['{"t":"bye"}']);

    // Quotes, emoji and control characters: escapes make the encoded part bigger than the piece.
    const big = JSON.stringify({ t: 'rr', e: { type: 2, timestamp: 1, text: 'a"b\\c😀\u0001ă'.repeat(30000) } });
    const frames = split(big, 7);

    assert.ok(frames.length > 2);

    const assembler = new Reassembler();
    let whole = null;

    for (const frame of frames) {
        assert.ok(utf8Length(frame) <= MAX_MESSAGE_BYTES, `a frame of ${utf8Length(frame)} bytes`);
        const part = JSON.parse(frame);

        assert.equal(part.t, 'part');
        assert.equal(part.id, 7);
        whole = assembler.push(part);
    }

    assert.equal(whole, big);
});

test('the reassembler refuses parts out of order, from another message or too many', () => {
    const assembler = new Reassembler({ maxParts: 4 });

    assert.equal(assembler.push({ t: 'part', id: 1, i: 0, n: 3, d: 'a' }), null);
    assert.equal(assembler.push({ t: 'part', id: 1, i: 2, n: 3, d: 'c' }), false);
    assert.equal(assembler.push({ t: 'part', id: 2, i: 1, n: 3, d: 'b' }), false);
    assert.equal(assembler.push({ t: 'part', id: 3, i: 0, n: 9, d: 'x' }), false);
    assert.equal(assembler.push({ t: 'part', id: 3, i: 0, n: 2, d: 5 }), false);

    const small = new Reassembler({ maxChars: 3 });

    assert.equal(small.push({ t: 'part', id: 1, i: 0, n: 2, d: 'ab' }), null);
    assert.equal(small.push({ t: 'part', id: 1, i: 1, n: 2, d: 'cd' }), false);
});

test('an rr message carries the event as JSON, not as an escaped string', () => {
    const message = JSON.parse(encodeEvent(JSON.stringify({ type: 3, timestamp: 5, data: { source: 1 } })));

    assert.deepEqual(message, { t: 'rr', e: { type: 3, timestamp: 5, data: { source: 1 } } });
    assert.deepEqual(validate(message, FROM_VISITOR), { ok: true, message });
});

// ---- validation -----------------------------------------------------------------

test('the visitor takes only known agent messages, inside its viewport, about nodes it knows', () => {
    const check = (message) => validate(message, FROM_AGENT, viewport);

    assert.deepEqual(check({ t: 'click', x: 10, y: 20, extra: 'dropped' }), { ok: true, message: { t: 'click', x: 10, y: 20 } });
    assert.deepEqual(check({ t: 'input', id: 42, value: 'hello' }), { ok: true, message: { t: 'input', id: 42, value: 'hello' } });
    assert.deepEqual(check({ t: 'media.request', kind: 'audio' }), { ok: true, message: { t: 'media.request', kind: 'audio' } });

    // Bad: counted.
    for (const message of [
        null,
        [],
        'click',
        { t: 'eval', code: 'alert(1)' },
        { t: 'rr', e: {} },
        { t: 'click', x: '10', y: 20 },
        { t: 'click', x: Infinity, y: 20 },
        { t: 'move', x: NaN, y: 1 },
        { t: 'input', id: 42, value: 'x'.repeat(4097) },
        { t: 'input', id: 42.5, value: 'x' },
        { t: 'input', id: -1, value: 'x' },
        { t: 'select', id: 42, value: 3 },
        { t: 'scroll', dx: 1e9, dy: 0 },
        { t: 'media.request', kind: 'screen' },
        { t: '__proto__' },
        { t: 'toString' },
    ]) {
        assert.deepEqual(check(message), { ok: false, bad: true }, JSON.stringify(message));
    }

    // Plausible (a resize, a node removed meanwhile): dropped, not counted.
    assert.deepEqual(check({ t: 'click', x: 1281, y: 20 }), { ok: false, bad: false });
    assert.deepEqual(check({ t: 'focus', id: 99 }), { ok: false, bad: false });
});

test('the agent takes only known visitor messages', () => {
    const check = (message) => validate(message, FROM_VISITOR);

    assert.equal(check({ t: 'css', key: 'a1', text: 'body{}' }).ok, true);
    assert.equal(check({ t: 'css', key: '../x', text: 'body{}' }).ok, false);
    assert.deepEqual(check({ t: 'state', hidden: false, url: 'https://shop.test/a', width: 800, height: 600 }).message, { t: 'state', hidden: false, url: 'https://shop.test/a', width: 800, height: 600 });
    assert.equal(check({ t: 'state', hidden: 'no', width: 1, height: 1 }).ok, false);
    assert.deepEqual(check({ t: 'control.refused', reason: 'password' }).message, { t: 'control.refused', reason: 'password' });
    assert.deepEqual(check({ t: 'control.refused', reason: '<b>x</b>' }).message, { t: 'control.refused', reason: null });
    assert.equal(check({ t: 'rr', e: { type: 9, timestamp: 1 } }).ok, false);
    assert.equal(check({ t: 'click', x: 1, y: 1 }).ok, false);
});

// ---- live stylesheets -------------------------------------------------------------

test('a stylesheet goes over the channel once, and the agent puts it back', () => {
    const css = 'body{color:red}'.repeat(200);
    const snapshot = () => ({ type: 2, timestamp: 1, data: { node: { type: 0, childNodes: [{ type: 2, tagName: 'link', attributes: { _cssText: css }, childNodes: [] }] } } });
    const styles = new LiveStyles();
    const first = snapshot();
    const second = snapshot();

    assert.equal(styles.holds(first), true);

    const messages = styles.strip(first);

    assert.equal(messages.length, 1);
    assert.equal(messages[0].text, css);
    assert.equal(liveStyleKey(first.data.node.childNodes[0].attributes._cssText), messages[0].key);
    assert.deepEqual(styles.strip(second), []);

    restoreLiveStyles(second, new Map([[messages[0].key, css]]));
    assert.equal(second.data.node.childNodes[0].attributes._cssText, css);

    // Short ones stay inline.
    assert.equal(styles.holds({ type: 2, data: { node: { type: 2, tagName: 'link', attributes: { _cssText: 'a{}' }, childNodes: [] } } }), false);
});

test('mapPoint turns a point on the scaled replay into visitor pixels', () => {
    assert.deepEqual(mapPoint(150, 120, { left: 50, top: 20, width: 640, height: 400 }, 1280, 800), { x: 200, y: 200 });
});

// ---- the endpoint -------------------------------------------------------------------

function endpoints({ visitor = {}, agent = {} } = {}) {
    const [a, b] = pair();
    const log = { visitor: [], agent: [], closed: [], versions: [] };
    const drain = () => Promise.resolve();
    const v = new Endpoint({ channel: a, role: 'visitor', schema: FROM_AGENT, context: viewport, drain, onMessage: (m) => log.visitor.push(m), onClose: (why) => log.closed.push(['visitor', why]), onVersion: (version) => log.versions.push(version), ...visitor });
    const g = new Endpoint({ channel: b, role: 'agent', schema: FROM_VISITOR, drain, onMessage: (m) => log.agent.push(m), onClose: (why) => log.closed.push(['agent', why]), ...agent });

    return { a, b, v, g, log };
}

test('both sides say hello first, then messages flow and large ones arrive whole', async () => {
    const { v, g, a, log } = endpoints();

    v.start();
    // Nothing but the hello goes before the peer's hello.
    v.send({ t: 'state', hidden: false, url: null, width: 1, height: 1 });
    await tick();
    assert.deepEqual(a.sent.map((frame) => JSON.parse(frame).t), ['hello']);

    g.start();
    await tick();

    assert.equal(v.ready && g.ready, true);
    assert.deepEqual(JSON.parse(a.sent[0]), { t: 'hello', v: PROTOCOL_VERSION, role: 'visitor' });
    assert.equal(log.agent[0].t, 'state');

    const huge = { type: 2, timestamp: 2, data: { text: 'x'.repeat(300_000) } };

    v.sendRaw(encodeEvent(JSON.stringify(huge)));
    g.send({ t: 'click', x: 5, y: 5 });
    await tick();
    await tick();

    assert.deepEqual(log.agent[1], { t: 'rr', e: huge });
    assert.deepEqual(log.visitor, [{ t: 'click', x: 5, y: 5 }]);
});

test('a peer on another major version stops the conversation', async () => {
    const { a, b, v, log } = endpoints();

    v.start();
    b.send(JSON.stringify({ t: 'hello', v: 2, role: 'agent' }));
    v.send({ t: 'bye' });
    b.send(JSON.stringify({ t: 'click', x: 1, y: 1 }));
    await tick();

    assert.deepEqual(log.versions, [2]);
    assert.deepEqual(log.visitor, []);
    assert.deepEqual(a.sent.map((frame) => JSON.parse(frame).t), ['hello']);
});

test('too many bad messages close the channel', async () => {
    const { b, v, log } = endpoints();

    v.start();
    b.send(JSON.stringify({ t: 'hello', v: 1, role: 'agent' }));

    for (let i = 0; i <= BAD_MESSAGE_BUDGET; i++) b.send(i % 2 ? 'not json' : JSON.stringify({ t: 'eval' }));

    assert.deepEqual(log.closed, [['visitor', 'bad_messages']]);
    assert.equal(v.closed, true);
});

test('a message before the hello counts as bad, and a plausible drop does not', async () => {
    const { b, v } = endpoints();

    v.start();
    b.send(JSON.stringify({ t: 'click', x: 1, y: 1 }));
    assert.equal(v.bad, 1);

    b.send(JSON.stringify({ t: 'hello', v: 1, role: 'agent' }));
    b.send(JSON.stringify({ t: 'click', x: 99999, y: 1 }));
    assert.equal(v.bad, 1);
});

test('when the channel falls behind, queued pointer samples go first and the rest waits', async () => {
    const [a, b] = pair();
    let release;
    const drain = () => new Promise((resolve) => (release = resolve));
    const v = new Endpoint({ channel: a, role: 'visitor', schema: FROM_AGENT, context: viewport, drain });

    v.start();
    b.deliver = () => {};
    a.listeners.message[0]({ data: JSON.stringify({ t: 'hello', v: 1, role: 'agent' }) });
    a.sent = [];
    a.bufferedAmount = 5 * 1024 * 1024;

    v.sendRaw('{"t":"rr","e":{"type":3,"timestamp":1,"data":{"source":1}}}', { droppable: true });
    v.sendRaw('{"t":"rr","e":{"type":3,"timestamp":2,"data":{"source":0}}}');
    v.sendRaw('{"t":"rr","e":{"type":3,"timestamp":3,"data":{"source":1}}}', { droppable: true });
    v.sendRaw('{"t":"rr","e":{"type":3,"timestamp":4,"data":{"source":1}}}', { droppable: true });
    await tick();

    assert.deepEqual(a.sent, []);

    a.bufferedAmount = 0;
    release();
    await tick();

    assert.deepEqual(
        a.sent.map((frame) => JSON.parse(frame).e.timestamp),
        [2, 4],
    );
});

// ---- the signaling adapter ------------------------------------------------------------

function fakeFetch(answers) {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, init, body: init.body instanceof FormData ? Object.fromEntries(init.body) : JSON.parse(init.body) });
        const answer = answers.shift() ?? { status: 200, body: {} };

        return { ok: answer.status < 300, status: answer.status, json: async () => answer.body };
    };

    return { fetch, calls };
}

const routes = Object.fromEntries(['poll', 'accept', 'decline', 'cancel', 'hangup', 'leave', 'signal', 'grants'].map((name) => [name, `/sr/assist/requests/__ID__${name === 'poll' ? '' : '/' + (name === 'signal' ? 'signals' : name)}`]));

routes.store = '/sr/assist/requests';
routes.heartbeat = '/sr/assist/heartbeat';

test('the adapter polls with a POST, the secret and the token in the body, never the URL', async () => {
    const { fetch, calls } = fakeFetch([{ status: 200, body: { id: 'r1', status: 'active', call: { id: 9 }, signals: [{ id: 3, type: 'offer' }], cursor: 3, rtc: { iceServers: [{ urls: 'stun:x' }], iceTransportPolicy: 'all' } } }]);
    const polls = [];
    const signaling = new AssistSignaling({ routes, fetch, credentials: () => ({ id: 'r1', secret: 's3cret', token: 'tok', session: 'rec-1', key: 'k', rebind: true }), onPoll: (data) => polls.push(data) });

    const answer = await signaling.poll({ after: 2, tab: 'tab-1', hold: true, kind: 'assist' });

    assert.deepEqual(answer, { call: { id: 9 }, incoming: null, signals: [{ id: 3, type: 'offer' }], cursor: 3 });
    assert.equal(calls[0].url, '/sr/assist/requests/r1');
    assert.equal(calls[0].init.method, 'POST');
    assert.ok(!calls[0].url.includes('s3cret') && !calls[0].url.includes('tok'));
    assert.deepEqual(calls[0].body, { token: 'tok', after: 2, tab: 'tab-1', hold: true, secret: 's3cret', session: 'rec-1', key: 'k' });
    assert.equal(polls[0].status, 'active');
    assert.deepEqual(signaling.rtc.iceServers, [{ urls: 'stun:x' }]);
});

test('the adapter sends signals, hangs up and leaves as the guest, and refuses what a guest never does', async () => {
    const { fetch, calls } = fakeFetch([{ status: 201, body: { ok: true } }, { status: 200, body: {} }, { status: 200, body: {} }]);
    const beacons = [];
    const signaling = new AssistSignaling({ routes, fetch, credentials: () => ({ id: 'r1', secret: 's', token: 't' }), beacon: (url, body) => beacons.push([url, Object.fromEntries(body)]) && true });

    await signaling.send(9, 'offer', { sdp: 'v=0\r\n' }, '12');
    await signaling.send(9, 'ice', { candidate: 'c' });
    await signaling.hangup(9);
    signaling.leave(9, 'tab-1');

    assert.equal(calls[0].url, '/sr/assist/requests/r1/signals');
    assert.deepEqual(calls[0].body, { token: 't', type: 'offer', payload: { sdp: 'v=0\r\n' }, to: '12', secret: 's' });
    assert.equal('to' in calls[1].body, false);
    assert.equal(calls[2].url, '/sr/assist/requests/r1/hangup');
    assert.deepEqual(beacons, [['/sr/assist/requests/r1/leave', { secret: 's', token: 't', tab: 'tab-1' }]]);

    await assert.rejects(signaling.start('12', 'tab'));
    await assert.rejects(signaling.answer(9, 'tab'));
    await assert.rejects(signaling.decline(9));
});

test('the adapter creates a request and answers a knock with the triple, and turns refusals into errors', async () => {
    const { fetch, calls } = fakeFetch([
        { status: 201, body: { id: 'r2', secret: 'abc', status: 'open' } },
        { status: 200, body: { id: 'r3', secret: 'def', status: 'open', opened_by: 'agent' } },
        { status: 409, body: { ok: false, message: 'The page is signed in as someone else now.', status: 'cancelled', end_reason: 'identity_changed' } },
    ]);
    const credentials = { id: null, secret: null, token: 't', session: 'rec-1', key: 'k' };
    const signaling = new AssistSignaling({ routes, fetch, credentials: () => credentials });

    // The tab's own id goes with it: one request per tab without a recording, whatever nonce a cached page carries.
    await signaling.create('Help', 'https://shop.test/', 'tab-a1b2c3d4');
    assert.equal(calls[0].url, '/sr/assist/requests');
    assert.deepEqual(calls[0].body, { token: 't', message: 'Help', url: 'https://shop.test/', tab: 'tab-a1b2c3d4', session: 'rec-1', key: 'k' });

    await signaling.answerKnock('r3');
    assert.equal(calls[1].url, '/sr/assist/requests/r3');
    assert.equal('secret' in calls[1].body, false);
    assert.equal(calls[1].body.session, 'rec-1');

    credentials.id = 'r3';
    credentials.secret = 'def';
    await assert.rejects(signaling.grant('control', true), (error) => error.status === 409 && error.data.end_reason === 'identity_changed');
    assert.deepEqual(calls[2].body, { token: 't', kind: 'control', granted: true, secret: 'def' });
});

// ---- the remote control executor (minimal DOM fakes) ------------------------------------

class FakeEvent {
    constructor(type, init = {}) {
        this.type = type;
        Object.assign(this, init);
    }
}

class FakeElement {
    constructor(tagName, props = {}) {
        this.nodeType = 1;
        this.tagName = tagName.toUpperCase();
        this.events = [];
        this.parentElement = null;
        this.style = {};
        this.attributes = {};
        this.classes = [];
        this.rect = { left: 0, top: 0, right: 20, bottom: 20, width: 20, height: 20 };
        this.scrollHeight = 0;
        this.clientHeight = 0;
        this.scrollWidth = 0;
        this.clientWidth = 0;
        this.scrolled = [0, 0];
        Object.assign(this, props);
    }

    dispatchEvent(event) {
        this.events.push(event);
    }

    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name);
    }

    getAttribute(name) {
        return this.hasAttribute(name) ? this.attributes[name] : null;
    }

    /** Enough of a selector engine for the control: tags, `a[href]`, `[attr]`, `.class`, comma lists, up the parents. */
    matches(selector) {
        return selector.split(',').some((part) => {
            const one = part.trim();

            if (one.startsWith('[')) return this.hasAttribute(one.slice(1, -1));
            if (one.startsWith('.')) return this.classes.includes(one.slice(1));

            return one.replace(/\[.*\]$/, '') === this.tagName.toLowerCase();
        });
    }

    closest(selector) {
        for (let element = this; element; element = element.parentElement) if (element.matches(selector)) return element;

        return null;
    }

    getBoundingClientRect() {
        return this.rect;
    }

    focus() {
        this.focused = true;
    }

    scrollBy({ left, top }) {
        this.scrolled = [this.scrolled[0] + left, this.scrolled[1] + top];
    }
}

class FakeInput extends FakeElement {}

const valueSets = [];

Object.defineProperty(FakeInput.prototype, 'value', {
    get() {
        return this._value ?? '';
    },
    set(value) {
        valueSets.push(value);
        this._value = value;
    },
});

function controlEnv({ at = null, nodes = {}, masked = () => false, maskedAllowed = () => false, blockSelector = '[data-replay-block]', now = () => 1000 } = {}) {
    const own = new FakeElement('div');
    const body = new FakeElement('body');
    const html = new FakeElement('html');
    const doc = { body, documentElement: html, scrollingElement: html, elementFromPoint: () => at };
    const win = {
        MouseEvent: FakeEvent,
        PointerEvent: FakeEvent,
        Event: FakeEvent,
        HTMLInputElement: FakeInput,
        HTMLTextAreaElement: FakeInput,
        HTMLSelectElement: FakeInput,
        getComputedStyle: (element) => element.style,
        innerWidth: 1280,
        innerHeight: 800,
    };
    const cursor = [];
    const control = new RemoteControl({ win, doc, isOwn: (element) => element === own, node: (id) => nodes[id] ?? null, isMasked: masked, blockSelector, maskedAllowed, onCursor: (x, y) => cursor.push([x, y]), now });

    return { control, own, body, html, doc, cursor };
}

test('a click lands on the element under the point, with the whole mouse sequence', () => {
    const button = new FakeElement('button');
    const { control, cursor } = controlEnv({ at: button });

    assert.deepEqual(control.click(30, 40), { ok: true });
    assert.deepEqual(
        button.events.map((event) => event.type),
        ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'],
    );
    assert.equal(button.events[4].clientX, 30);
    assert.equal(button.events[4].bubbles, true);
    assert.equal(button.focused, true);
    assert.deepEqual(cursor, [[30, 40]]);
});

test('the Assist UI is never clicked, and the visitor always wins', () => {
    let now = 1000;
    const env = controlEnv({ now: () => now });

    env.doc.elementFromPoint = () => env.own;
    assert.deepEqual(env.control.click(1, 1), { refused: 'own_ui' });

    const link = new FakeElement('a');

    env.doc.elementFromPoint = () => link;
    env.control.visitorActivity();
    assert.deepEqual(env.control.click(1, 1), { ignored: 'visitor' });
    assert.equal(link.events.length, 0);

    now += 1001;
    assert.deepEqual(env.control.click(1, 1), { ok: true });
});

test('typing uses the native setter and an input event, and refuses passwords and masked fields', () => {
    const name = new FakeInput('input', { type: 'text', maxLength: 5 });
    const password = new FakeInput('input', { type: 'password' });
    const masked = new FakeInput('textarea');
    const file = new FakeInput('input', { type: 'file' });
    const readonly = new FakeInput('input', { type: 'text', readOnly: true });
    const div = new FakeElement('div');
    let allowed = false;
    const { control } = controlEnv({ nodes: { 1: name, 2: password, 3: masked, 4: file, 5: readonly, 6: div }, masked: (element) => element === masked || element === password, maskedAllowed: () => allowed });

    valueSets.length = 0;
    assert.deepEqual(control.input(1, 'Ana Maria'), { ok: true });
    // Through the prototype's setter (what React and Livewire watch), cut at maxlength.
    assert.deepEqual(valueSets, ['Ana M']);
    assert.deepEqual(name.events.map((event) => event.type), ['input']);

    assert.deepEqual(control.input(2, 'hunter2'), { refused: 'password' });
    allowed = true;
    // Never a password, whatever the visitor allowed.
    assert.deepEqual(control.input(2, 'hunter2'), { refused: 'password' });
    allowed = false;
    assert.deepEqual(control.input(3, 'note'), { refused: 'masked' });
    allowed = true;
    assert.deepEqual(control.input(3, 'note'), { ok: true });
    assert.deepEqual(control.input(4, 'x'), { refused: 'unsupported' });
    assert.deepEqual(control.input(5, 'x'), { refused: 'readonly' });
    assert.deepEqual(control.input(6, 'x'), { refused: 'unsupported' });
    assert.deepEqual(control.input(99, 'x'), { refused: 'unknown_node' });

    // Moving on to another field settles the first one with a change event.
    assert.deepEqual(name.events.map((event) => event.type), ['input', 'change']);
});

test('select picks an existing option only', () => {
    const select = new FakeInput('select', { options: [{ value: 'ro' }, { value: 'de' }] });
    const { control } = controlEnv({ nodes: { 8: select } });

    assert.deepEqual(control.select(8, 'fr'), { refused: 'unknown_option' });
    assert.deepEqual(control.select(8, 'de'), { ok: true });
    assert.equal(select.value, 'de');
    assert.deepEqual(select.events.map((event) => event.type), ['input', 'change']);
});

test('scroll moves the first scrollable ancestor under the cursor, or the page', () => {
    const list = new FakeElement('ul', { style: { overflowY: 'auto', overflowX: 'visible' }, scrollHeight: 900, clientHeight: 300 });
    const item = new FakeElement('li', { style: { overflowY: 'visible', overflowX: 'visible' } });

    item.parentElement = list;
    list.parentElement = null;

    const env = controlEnv({ at: item });

    env.control.move(10, 10);
    assert.deepEqual(env.control.scroll(0, 120), { ok: true });
    assert.deepEqual(list.scrolled, [0, 120]);

    // Sideways: the list does not scroll that way, the page does.
    env.control.scroll(50, 0);
    assert.deepEqual(env.html.scrolled, [50, 0]);
});

test('a drag presses, moves with the button down and lets go; release ends one in progress', () => {
    const handle = new FakeElement('div');
    const { control } = controlEnv({ at: handle });

    assert.deepEqual(control.drag(1, 1), { refused: 'not_dragging' });
    control.dragStart(1, 1);
    control.drag(5, 5);
    assert.deepEqual(control.dragStop(), { ok: true });
    assert.deepEqual(
        handle.events.map((event) => event.type),
        ['pointerdown', 'mousedown', 'pointerover', 'mouseover', 'pointermove', 'mousemove', 'pointerup', 'mouseup'],
    );
    assert.equal(handle.events[5].buttons, 1);

    control.dragStart(1, 1);
    control.release();
    assert.deepEqual(handle.events.slice(-2).map((event) => event.type), ['pointerup', 'mouseup']);
});

test('maskedField follows the recorder: every field with mask_all_inputs, passwords always, ignored fields', () => {
    const input = (type, inside = false) => ({ tagName: 'INPUT', type, closest: (selector) => (inside && selector === '[data-replay-ignore]' ? {} : null) });

    assert.equal(maskedField(input('text')), true);
    assert.equal(maskedField(input('text'), { maskAllInputs: false }), false);
    assert.equal(maskedField(input('password'), { maskAllInputs: false }), true);
    assert.equal(maskedField(input('text', true), { maskAllInputs: false, ignoreSelector: '[data-replay-ignore]' }), true);
    assert.equal(maskedField({ tagName: 'TEXTAREA', type: '' }), true);
});

test('a password field stays one after a show-password toggle: never filled, always masked', () => {
    const toggled = new FakeInput('input', { type: 'text', attributes: { 'data-rr-is-password': '' } });
    const login = new FakeInput('input', { type: 'text', attributes: { autocomplete: 'current-password' } });
    const signup = new FakeInput('input', { type: 'text', attributes: { autocomplete: 'section-signup new-password' } });
    const email = new FakeInput('input', { type: 'text', attributes: { autocomplete: 'email' } });
    // The visitor allowed typing into masked fields and the field is not masked: a password is still refused.
    const { control } = controlEnv({ nodes: { 1: toggled, 2: login, 3: signup, 4: email }, masked: () => false, maskedAllowed: () => true });

    assert.deepEqual(control.input(1, 'hunter2'), { refused: 'password' });
    assert.deepEqual(control.input(2, 'hunter2'), { refused: 'password' });
    assert.deepEqual(control.input(3, 'hunter2'), { refused: 'password' });
    assert.deepEqual(control.input(4, 'ana@shop.test'), { ok: true });

    for (const field of [toggled, login, signup]) assert.equal(maskedField(field, { maskAllInputs: false }), true);
    assert.equal(maskedField(email, { maskAllInputs: false }), false);
});

test('nothing the agent cannot see is acted on: off-screen, hidden, skipped or blocked', () => {
    const offscreen = new FakeInput('input', { type: 'text', rect: { left: 0, top: 900, right: 100, bottom: 920, width: 100, height: 20 } });
    const empty = new FakeInput('input', { type: 'text', rect: { left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0 } });
    const invisible = new FakeInput('input', { type: 'text', checkVisibility: () => false });
    const skipRegion = new FakeElement('div', { attributes: { 'data-replay-skip': '' } });
    const blockRegion = new FakeElement('section', { attributes: { 'data-replay-block': '' } });
    const rrBlock = new FakeElement('div', { classes: ['rr-block'] });
    const skipped = new FakeInput('input', { type: 'text', parentElement: skipRegion });
    const blocked = new FakeInput('input', { type: 'text', parentElement: blockRegion });
    const rrBlocked = new FakeInput('input', { type: 'text', parentElement: rrBlock });
    const visible = new FakeInput('input', { type: 'text', checkVisibility: () => true });
    const { control, doc } = controlEnv({ nodes: { 1: offscreen, 2: empty, 3: invisible, 4: skipped, 5: blocked, 6: rrBlocked, 7: visible } });

    assert.deepEqual(control.focus(1), { refused: 'not_visible' });
    assert.deepEqual(control.input(2, 'x'), { refused: 'not_visible' });
    assert.deepEqual(control.select(3, 'x'), { refused: 'not_visible' });
    assert.deepEqual(control.input(4, 'x'), { refused: 'blocked' });
    assert.deepEqual(control.focus(5), { refused: 'blocked' });
    assert.deepEqual(control.input(6, 'x'), { refused: 'blocked' });
    assert.deepEqual(control.input(7, 'x'), { ok: true });
    assert.equal(offscreen.focused, undefined);
    assert.equal(skipped.value, '');

    // The pointer too: a click on a blocked box does nothing; hovering over it is quiet.
    const button = new FakeElement('button', { parentElement: blockRegion });

    doc.elementFromPoint = () => button;
    assert.deepEqual(control.click(5, 5), { refused: 'blocked' });
    assert.deepEqual(control.move(5, 5), { ok: true });
    assert.equal(button.events.length, 0);

    // Fields the agent never sees count as masked, whatever mask_all_inputs says.
    assert.equal(maskedField(skipped, { maskAllInputs: false }), true);
    assert.equal(maskedField(blocked, { maskAllInputs: false, blockSelector: '[data-replay-block]' }), true);
    assert.equal(maskedField(rrBlocked, { maskAllInputs: false }), true);
    assert.equal(maskedField(visible, { maskAllInputs: false, blockSelector: '[data-replay-block]' }), false);
});

test('the agent\'s side closes a channel that sends more than its byte budget', async () => {
    const [a, b] = pair();
    let now = 0;
    const closed = [];
    const g = new Endpoint({ channel: b, role: 'agent', schema: FROM_VISITOR, drain: () => Promise.resolve(), rate: { bytesPerSecond: 1000, burstBytes: 3000 }, now: () => now, onClose: (why) => closed.push(why) });

    g.start();
    a.send(JSON.stringify({ t: 'hello', v: 1, role: 'visitor' }));

    const css = (n) => JSON.stringify({ t: 'css', key: 'k' + n, text: 'x'.repeat(900) });

    // A burst within the budget, then the budget refills with time.
    a.send(css(1));
    a.send(css(2));
    a.send(css(3));
    now += 1000;
    a.send(css(4));
    assert.deepEqual(closed, []);

    // Faster than it refills: let go.
    a.send(css(5));
    assert.deepEqual(closed, ['too_fast']);
    assert.equal(g.closed, true);

    // The visitor's side and validation alone keep no budget.
    const [c, d] = pair();
    const v = new Endpoint({ channel: c, role: 'visitor', schema: FROM_AGENT, context: viewport, drain: () => Promise.resolve() });

    v.start();
    d.send(JSON.stringify({ t: 'hello', v: 1, role: 'agent' }));
    for (let i = 0; i < 100; i++) d.send(JSON.stringify({ t: 'move', x: 1, y: 1 }));
    assert.equal(v.closed, false);
});

test('a refusal carries its reason to the agent', () => {
    assert.deepEqual(validate({ t: 'media.refused', kind: 'audio', reason: 'cooldown' }, FROM_VISITOR).message, { t: 'media.refused', kind: 'audio', reason: 'cooldown' });
    assert.deepEqual(validate({ t: 'screen.refused', reason: 'cooldown' }, FROM_VISITOR).message, { t: 'screen.refused', reason: 'cooldown' });
    assert.deepEqual(validate({ t: 'screen.refused' }, FROM_VISITOR).message, { t: 'screen.refused', reason: null });
    assert.equal(validate({ t: 'media.refused', kind: 'screen', reason: 'cooldown' }, FROM_VISITOR).ok, false);
});
