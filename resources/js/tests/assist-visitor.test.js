import assert from 'node:assert/strict';
import { test } from 'node:test';
import { installAssist } from '../lib/assist-visitor.js';
import { Endpoint, FROM_VISITOR } from '../lib/assist-protocol.js';

/*
 * The visitor's page (assist.js) with an agent on the other end of the channel: a fake engine client, a fake
 * panel, fake timers and a fake fetch for the visitor routes; the protocol endpoints are the real ones.
 */

const tick = () => new Promise((resolve) => setImmediate(resolve));
const settle = async () => {
    for (let i = 0; i < 5; i++) await tick();
};

class FakeChannel {
    constructor() {
        this.label = 'assist';
        this.readyState = 'open';
        this.bufferedAmount = 0;
        this.bufferedAmountLowThreshold = 1024 * 1024;
        this.listeners = { message: [], close: [] };
        this.peer = null;
    }

    send(data) {
        this.peer?.deliver(data);
    }

    deliver(data) {
        if (this.listeners.message.length === 0) return (this.backlog ||= []).push(data);

        for (const fn of [...this.listeners.message]) fn({ data });
    }

    addEventListener(type, fn) {
        (this.listeners[type] ||= []).push(fn);

        if (type === 'message' && this.backlog) for (const data of this.backlog.splice(0)) fn({ data });
    }

    removeEventListener(type, fn) {
        this.listeners[type] = (this.listeners[type] || []).filter((entry) => entry !== fn);
    }

    close() {
        this.readyState = 'closed';
        for (const fn of [...this.listeners.close]) fn();
    }
}

class FakeClient extends EventTarget {
    static last = null;

    constructor(options) {
        super();
        this.options = options;
        this.muted = false;
        this.mutes = [];
        this.cameraStream = null;
        this.sharing = false;
        this.screenStream = null;
        this.remoteStream = null;
        this.state = 'connected';
        FakeClient.last = this;
    }

    resumeCall(id) {
        this.callId = id;
    }

    async startMedia(kind) {
        if (FakeClient.mediaError) throw FakeClient.mediaError;

        this.cameraStream ||= { kind };
    }

    setMuted(muted) {
        this.muted = muted;
        this.mutes.push(muted);
    }

    async shareScreen() {
        this.sharing = true;
        this.screenStream = { getVideoTracks: () => [{ addEventListener() {} }] };
    }

    async stopSharing() {
        this.sharing = false;
        this.screenStream = null;
    }

    leave() {}

    destroy() {
        this.destroyed = true;
    }
}

/** The panel: prompts the test answers, the status panel's buttons it can press. */
function fakeUi() {
    const prompts = [];
    let shown = null;
    // The one cursor: 'control' while the agent has control, 'point' while they point, null hidden (as assist-ui.js).
    const cursor = { mode: null, name: null, at: null };

    return {
        prompts,
        get shown() {
            return shown;
        },
        /** A button of the status panel by its label key (the labels are empty, so a label is its key). */
        press(key) {
            const action = shown?.actions?.find((entry) => entry.label === key);

            assert.ok(action, `no "${key}" button`);
            action.onClick();
        },
        open: () => prompts.filter((prompt) => !prompt.done),
        mount() {},
        remount() {},
        launcher() {},
        show(view) {
            shown = view;
        },
        hide() {
            shown = null;
        },
        notice() {},
        form() {},
        prompt(text, { tag = null } = {}) {
            return new Promise((resolve) => {
                const prompt = {
                    text,
                    tag,
                    done: false,
                    answer(yes) {
                        prompt.done = true;
                        resolve(yes);
                    },
                };

                prompts.push(prompt);
            });
        },
        cancelPrompts(tag = null) {
            for (const prompt of prompts) if (!prompt.done && (tag === null || prompt.tag === tag)) prompt.answer(false);
        },
        get promptOpen() {
            return prompts.some((prompt) => !prompt.done);
        },
        contains: () => false,
        cursorState: cursor,
        outlined: false,
        cursor: {
            show(name) {
                Object.assign(cursor, { mode: 'control', name });
            },
            move(x, y) {
                cursor.at = [x, y];
            },
            hide() {
                if (cursor.mode === 'control') cursor.mode = null;
            },
        },
        pointer: {
            move(name, x, y) {
                if (cursor.mode === 'control') return;

                Object.assign(cursor, { mode: 'point', name, at: [x, y] });
            },
            hide() {
                if (cursor.mode === 'point') cursor.mode = null;
            },
        },
        outline(on) {
            this.outlined = on;
        },
        ink: { start() {}, move() {}, stop() {}, clear() {} },
        media() {},
        clearMedia() {},
    };
}

function fakeTimers() {
    const pending = new Map();
    let next = 1;

    return {
        set(fn, ms) {
            pending.set(next, { fn, ms });

            return next++;
        },
        clear(id) {
            pending.delete(id);
        },
        /** Every timer due within `within` ms (the polls; not the hand-over's or the reminder's, unless asked). */
        async run(within = 5000) {
            const due = [...pending.entries()].filter(([, timer]) => (timer.ms ?? 0) <= within);

            for (const [id] of due) pending.delete(id);
            for (const [, timer] of due) await timer.fn();
        },
    };
}

const routes = Object.fromEntries(['poll', 'accept', 'decline', 'cancel', 'hangup', 'leave', 'signal', 'grants'].map((name) => [name, `/sr/assist/requests/__ID__/${name}`]));

/** A visitor whose request is live with Ana (agent 5), the channel open and both hellos said. */
async function live({ stored = {}, video = false, devices = { media: true, screen: true }, assist = {}, store: carriedStore = null, clock: startAt = 1_000_000, arrival = null, href = 'https://shop.test/checkout', mirrored = true, onInstall = null } = {}) {
    let clock = startAt;
    // A store from a page before (the same tab's sessionStorage), or a fresh request.
    const store = carriedStore ?? new Map([['sr:assist', JSON.stringify({ id: 'r1', secret: 's3cret', status: 'active', openedBy: 'visitor', agent: { id: '5', name: 'Ana' }, grants: {}, ...stored })]]);
    // What the recorder says of the page: identity, whether rrweb runs on it (a test flips them for a wire:navigate).
    const page = { identity: null, mirrors: true };
    const grants = [];
    const ui = fakeUi();
    const timers = fakeTimers();
    // What the poll route answers; a test changes it to move the request.
    const server = { poll: { id: 'r1', status: 'active', opened_by: 'visitor', agent: { id: '5', name: 'Ana' }, call: { id: 'c1', peer: { id: '5' } }, signals: [], cursor: 0 } };

    globalThis.window = Object.assign(new EventTarget(), { innerWidth: 1280, innerHeight: 800 });
    globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    globalThis.location = { href, origin: 'https://shop.test' };

    const fetch = async (url, init) => {
        const body = JSON.parse(init.body);
        const answer = (data) => ({ ok: true, status: 200, json: async () => data });

        if (url.endsWith('/grants')) {
            grants.push([body.kind, body.granted]);

            return answer({ ok: true });
        }

        if (url.endsWith('/poll')) {
            return answer(server.poll);
        }

        return answer({});
    };

    const recorder = {
        config: () => ({ token: 'tok', identity: page.identity, privacy: { maskAllInputs: true }, assist: { routes, calls: true, video, labels: {}, poll: { waiting: 3000, active: 2000 }, ...assist } }),
        recording: () => ({ id: 'rec-1', key: 'k1' }),
        snapshot: () => mirrored,
        mirrors: () => page.mirrors,
        node: () => null,
        goLive() {},
        touch() {},
        redacted: (url) => url,
        read: (key) => store.get(key) ?? null,
        write: (key, value) => store.set(key, value),
        tab: 'tab-1',
    };

    installAssist(recorder, { ui, timers, Client: FakeClient, drain: async () => {}, fetch, now: () => clock, devices: () => devices, ...(arrival ? { arrival: () => arrival } : {}) });
    await onInstall?.({ ui, grants, store, timers });

    // The reload's first poll finds the call; the client joins it.
    await timers.run();
    await settle();

    const client = FakeClient.last;
    const visitorSide = new FakeChannel();
    const agentSide = new FakeChannel();

    visitorSide.peer = agentSide;
    agentSide.peer = visitorSide;

    const received = [];

    client.dispatchEvent(Object.assign(new Event('datachannel'), { detail: { channel: visitorSide, peer: { id: '5' } } }));

    const agent = new Endpoint({ channel: agentSide, role: 'agent', schema: FROM_VISITOR, drain: async () => {}, onMessage: (message) => received.push(message) }).start();

    await settle();

    return {
        agent,
        /** The visitor's end of the data channel. */
        channel: visitorSide,
        ui,
        client,
        timers,
        server,
        grants,
        store,
        page,
        /** What the visitor's page sent the agent, apart from the mirror. */
        said: () => received.filter((message) => !['rr', 'css', 'state'].includes(message.t)),
        /** Everything it sent, in order. */
        all: () => received,
        now: () => clock,
        async send(message) {
            agent.send(message);
            await settle();
        },
        async answer(yes) {
            const open = ui.open();

            assert.equal(open.length, 1, `${open.length} prompts open`);
            open[0].answer(yes);
            await settle();
        },
        advance: (ms) => (clock += ms),
    };
}

test('a microphone the visitor muted stays muted: a remembered grant never unmutes it', async () => {
    const page = await live();

    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(true);
    assert.equal(page.client.muted, false);
    assert.deepEqual(page.said().at(-1), { t: 'media.granted', kind: 'audio' });

    // The visitor mutes themselves; the agent asks to talk again, and audio is remembered for this request.
    page.ui.press('mute');
    assert.equal(page.client.muted, true);

    await page.send({ t: 'media.request', kind: 'audio' });
    assert.equal(page.ui.open().length, 0);
    assert.equal(page.client.muted, true);
    assert.equal(page.client.mutes.includes(false), false);

    // The agent ends the audio and asks again: asked again, and an Allow still leaves the visitor's own mute.
    await page.send({ t: 'media.stop', kind: 'audio' });
    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(true);
    assert.equal(page.client.muted, true);

    // Only the visitor's own button unmutes.
    page.ui.press('unmute');
    assert.equal(page.client.muted, false);
});

test('the agent ending the audio mutes it until the visitor allows it again', async () => {
    const page = await live();

    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(true);
    await page.send({ t: 'media.stop', kind: 'audio' });
    assert.equal(page.client.muted, true);

    // Remembered, but the agent stopped it: the visitor is asked, and a no keeps it off.
    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(false);
    assert.equal(page.client.muted, true);
    assert.deepEqual(page.said().at(-1), { t: 'media.refused', kind: 'audio', reason: 'declined' });

    page.advance(10_000);
    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(true);
    assert.equal(page.client.muted, false);
});

test('a reload keeps the visitor\'s mute', async () => {
    const page = await live({ stored: { grants: { audio: true }, muted: true } });

    await page.send({ t: 'media.request', kind: 'audio' });
    assert.equal(page.ui.open().length, 0);
    assert.equal(page.client.muted, true);
});

test('one question of a kind at a time: the agent\'s repeats are dropped', async () => {
    const page = await live();

    await page.send({ t: 'control.request' });
    await page.send({ t: 'control.request' });
    await page.send({ t: 'control.request' });
    await page.send({ t: 'media.request', kind: 'audio' });
    await page.send({ t: 'media.request', kind: 'audio' });
    await page.send({ t: 'screen.request' });
    await page.send({ t: 'screen.request' });

    assert.deepEqual(
        page.ui.open().map((prompt) => prompt.tag),
        ['control', 'media', 'screen'],
    );
});

test('after a no, the same question waits ten seconds and the agent is told why', async () => {
    const page = await live();

    await page.send({ t: 'control.request' });
    await page.answer(false);
    assert.deepEqual(page.said().at(-1), { t: 'control.refused', reason: 'declined' });

    await page.send({ t: 'control.request' });
    assert.equal(page.ui.open().length, 0);
    assert.deepEqual(page.said().at(-1), { t: 'control.refused', reason: 'cooldown' });

    await page.send({ t: 'screen.request' });
    await page.answer(false);
    await page.send({ t: 'screen.request' });
    assert.equal(page.ui.open().length, 0);
    assert.deepEqual(page.said().at(-1), { t: 'screen.refused', reason: 'cooldown' });

    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(false);
    await page.send({ t: 'media.request', kind: 'video' });
    assert.equal(page.ui.open().length, 0);

    page.advance(10_000);
    await page.send({ t: 'control.request' });
    assert.deepEqual(
        page.ui.open().map((prompt) => prompt.tag),
        ['control'],
    );
});

test('the agent stopping the visitor\'s screen share is logged as the screen grant ending', async () => {
    const page = await live();

    await page.send({ t: 'screen.request' });
    await page.answer(true);
    assert.equal(page.client.sharing, true);
    assert.deepEqual(page.grants.filter(([kind]) => kind === 'screen'), [['screen', true]]);

    await page.send({ t: 'screen.stop' });
    assert.equal(page.client.sharing, false);
    assert.deepEqual(page.grants.filter(([kind]) => kind === 'screen'), [['screen', true], ['screen', false]]);
    assert.deepEqual(page.said().at(-1), { t: 'screen.stopped' });
});

test('the visitor code takes the engine by injection and refuses to run without one', () => {
    assert.throws(() => installAssist({ config: () => ({}) }, {}), /calls engine/);
    assert.throws(() => installAssist({ config: () => ({}) }), /calls engine/);
});

test('when the agent leaves the live view the page says they are reconnecting, and joins the new call once they are back', async () => {
    const page = await live();
    const first = page.client;

    // The client's own poll hears the request went back to claimed: the call is over, the agent is away.
    page.server.poll = { id: 'r1', status: 'claimed', opened_by: 'visitor', agent: { id: '5', name: 'Ana' }, reconnecting: true, call: null, signals: [], cursor: 0 };
    await first.options.signaling.poll({ after: 0, tab: 'x', claim: true });
    await settle();

    assert.equal(first.destroyed, true);
    assert.equal(page.ui.shown.text, 'rejoining');
    assert.equal(JSON.parse(page.store.get('sr:assist')).status, 'claimed');

    // Waiting polls go on; nothing new while the agent is away.
    await page.timers.run();
    await settle();
    assert.equal(FakeClient.last, first);

    // The agent is back: the next poll brings a new call, and the page joins it.
    page.server.poll = { id: 'r1', status: 'active', opened_by: 'visitor', agent: { id: '5', name: 'Ana' }, reconnecting: false, call: { id: 'c2', peer: { id: '5' } }, signals: [], cursor: 0 };
    await page.timers.run();
    await settle();

    assert.notEqual(FakeClient.last, first);
    assert.equal(FakeClient.last.callId, 'c2');
    assert.equal(page.ui.shown.text, 'sees');
});

test('what the visitor allowed one agent is not carried over to the next one', async () => {
    const page = await live();

    await page.send({ t: 'media.request', kind: 'audio' });
    await page.answer(true);
    assert.equal(JSON.parse(page.store.get('sr:assist')).grants.audio, true);

    // Ana leaves, the claim lapses back to the list, and Ion takes it.
    page.server.poll = { id: 'r1', status: 'open', opened_by: 'visitor', agent: null, call: null, signals: [], cursor: 0 };
    await page.client.options.signaling.poll({ after: 0, tab: 'x', claim: true });
    await settle();

    assert.deepEqual(JSON.parse(page.store.get('sr:assist')).grants, {});
    assert.deepEqual(page.grants.filter(([kind]) => kind === 'audio'), [['audio', true], ['audio', false]]);

    page.server.poll = { id: 'r1', status: 'active', opened_by: 'visitor', agent: { id: '9', name: 'Ion' }, call: { id: 'c3', peer: { id: '9' } }, signals: [], cursor: 0 };
    await page.timers.run();
    await settle();

    // Nothing starts by itself for Ion: no microphone until the visitor is asked.
    assert.equal(page.client.destroyed, true);
    assert.notEqual(FakeClient.last, page.client);
    assert.equal(FakeClient.last.callId, 'c3');
    assert.equal(FakeClient.last.cameraStream, null);
});

// ---- the agent's pointer while they only watch -----------------------------------------

test('the page tells the stage it takes the pointer, and draws it as the agent\'s named cursor without asking and without the control frame', async () => {
    const page = await live();

    assert.equal(page.agent.peerCan('point'), true);

    await page.send({ t: 'point', x: 300, y: 120 });

    assert.deepEqual(page.ui.cursorState, { mode: 'point', name: 'Ana', at: [300, 120] });
    // Nothing was asked, nothing was granted or logged, no dashed frame, and the page said nothing back.
    assert.equal(page.ui.prompts.length, 0);
    assert.deepEqual(page.grants, []);
    assert.equal(page.ui.outlined, false);
    assert.deepEqual(page.said(), []);

    await page.send({ t: 'point', x: 310, y: 125 });
    assert.deepEqual(page.ui.cursorState.at, [310, 125]);

    await page.send({ t: 'point.hide' });
    assert.equal(page.ui.cursorState.mode, null);
});

test('a control message still does nothing without control: only the pointer was let through', async () => {
    const page = await live();
    // Everything a control message could do to the page: look an element up, dispatch an event, scroll.
    const reached = [];

    globalThis.document.elementFromPoint = (x, y) => (reached.push(`elementFromPoint ${x},${y}`), null);
    globalThis.document.elementsFromPoint = (x, y) => (reached.push(`elementsFromPoint ${x},${y}`), []);
    globalThis.window.scrollBy = (...args) => reached.push(`scrollBy ${args}`);

    for (const target of [globalThis.document, globalThis.window]) {
        const dispatch = target.dispatchEvent.bind(target);

        target.dispatchEvent = (event) => (reached.push(`event ${event.type}`), dispatch(event));
    }

    await page.send({ t: 'move', x: 300, y: 120 });
    await page.send({ t: 'click', x: 300, y: 120 });
    await page.send({ t: 'drag.start', x: 300, y: 120 });
    await page.send({ t: 'drag', x: 310, y: 130 });
    await page.send({ t: 'drag.stop' });
    await page.send({ t: 'scroll', dx: 0, dy: 400 });

    assert.deepEqual(reached, []);
    assert.equal(page.ui.cursorState.mode, null);
    assert.deepEqual(page.said(), []);

    // The pointer beside them is drawn and still reaches nothing on the page.
    await page.send({ t: 'point', x: 300, y: 120 });
    assert.equal(page.ui.cursorState.mode, 'point');
    assert.deepEqual(reached, []);
});

test('the pointer never sits on a question, and not in a tab nobody sees', async () => {
    const page = await live();

    await page.send({ t: 'media.request', kind: 'audio' });
    assert.equal(page.ui.open().length, 1);

    await page.send({ t: 'point', x: 300, y: 120 });
    assert.equal(page.ui.cursorState.mode, null);

    await page.answer(false);
    await page.send({ t: 'point', x: 300, y: 120 });
    assert.equal(page.ui.cursorState.mode, 'point');

    await page.send({ t: 'point.hide' });
    globalThis.document.visibilityState = 'hidden';
    await page.send({ t: 'point', x: 300, y: 120 });
    assert.equal(page.ui.cursorState.mode, null);
});

test('with control the control cursor takes over: one cursor, and the pointer is ignored until control ends', async () => {
    const page = await live();

    await page.send({ t: 'point', x: 300, y: 120 });
    assert.equal(page.ui.cursorState.mode, 'point');

    await page.send({ t: 'control.request' });
    await page.answer(true);

    assert.equal(page.ui.cursorState.mode, 'control');
    assert.equal(page.ui.outlined, true);

    // A stage that still pointed: nothing changes for the control cursor.
    await page.send({ t: 'point', x: 5, y: 5 });
    await page.send({ t: 'point.hide' });
    assert.equal(page.ui.cursorState.mode, 'control');

    await page.send({ t: 'control.release' });
    assert.equal(page.ui.cursorState.mode, null);
    assert.equal(page.ui.outlined, false);

    await page.send({ t: 'point', x: 7, y: 9 });
    assert.deepEqual(page.ui.cursorState, { mode: 'point', name: 'Ana', at: [7, 9] });
});

test('the pointer goes with the channel', async () => {
    const page = await live();

    await page.send({ t: 'point', x: 300, y: 120 });
    // The connection dropped under it.
    page.channel.close();
    await settle();

    assert.equal(page.ui.cursorState.mode, null);
});

test('while the agent has the assist on hold the page says they will be right back', async () => {
    const page = await live();

    // Holler's hold: the engine has nobody to be connected to and nothing is wrong ('waiting').
    page.client.state = 'waiting';
    page.client.dispatchEvent(new Event('statechange'));

    assert.equal(page.ui.shown.text, 'onHold');

    page.client.state = 'active';
    page.client.dispatchEvent(new Event('statechange'));

    assert.equal(page.ui.shown.text, 'sees');
});

test('on a page that cannot share media (plain http), the visitor is not asked and the agent is told why', async () => {
    const page = await live({ devices: { media: false, screen: false } });
    const prompts = page.ui.prompts.length;

    await page.send({ t: 'media.request', kind: 'audio' });
    assert.deepEqual(page.said().at(-1), { t: 'media.refused', kind: 'audio', reason: 'unavailable' });

    await page.send({ t: 'screen.request' });
    assert.deepEqual(page.said().at(-1), { t: 'screen.refused', reason: 'unavailable' });
    assert.equal(page.ui.prompts.length, prompts);
});

test('a microphone the browser refuses after the visitor\'s Allow is reported as blocked, not as their no', async () => {
    const page = await live();

    FakeClient.mediaError = Object.assign(new Error('denied'), { name: 'NotAllowedError' });

    try {
        await page.send({ t: 'media.request', kind: 'audio' });
        await page.answer(true);
        assert.deepEqual(page.said().at(-1), { t: 'media.refused', kind: 'audio', reason: 'blocked' });
    } finally {
        FakeClient.mediaError = null;
    }
});

// ---- remote control across pages (assist.control_across_pages) --------------------------------

/** The page acts on a control message: it looked an element up for it. */
function watchActions() {
    const reached = [];

    globalThis.document.elementFromPoint = (x, y) => (reached.push(`${x},${y}`), null);
    globalThis.document.elementsFromPoint = (x, y) => (reached.push(`${x},${y}`), []);

    return reached;
}

/** A page with control granted, left for the next one (pagehide). */
async function handedOver({ assist = { controlAcrossPages: true } } = {}) {
    const first = await live({ assist });

    await first.send({ t: 'control.request' });
    await first.answer(true);

    globalThis.window.dispatchEvent(new Event('pagehide'));
    await settle();

    return first;
}

test('control goes on to the next page of the tab: no new question, the outline and the reminder at once, one grant period', async () => {
    const first = await handedOver();
    const carry = JSON.parse(first.store.get('sr:assist')).carry;

    assert.equal(carry.from, 'https://shop.test/checkout');
    // Left with control handed over: no end of control in the log.
    assert.deepEqual(first.grants, [['control', true]]);

    let before = null;
    const next = await live({
        store: first.store,
        clock: first.now() + 1500,
        href: 'https://shop.test/checkout/address',
        arrival: { type: 'navigate', referrer: 'https://shop.test/checkout', href: 'https://shop.test/checkout/address' },
        assist: { controlAcrossPages: true },
        onInstall: ({ ui }) => {
            before = { outlined: ui.outlined, text: ui.shown?.text, buttons: ui.shown?.actions.map((action) => action.label) };
        },
    });

    // Before the channel: the frame and "… still controls this page" with End remote control, no control yet.
    assert.deepEqual(before, { outlined: true, text: 'stillControlling', buttons: ['endControl', 'end'] });
    // Taken from storage: a later page never sees it.
    assert.equal(JSON.parse(next.store.get('sr:assist')).carry, undefined);

    // On the channel: the grant (continued) before the first state, nothing asked, nothing logged.
    const order = next.all().map((message) => message.t);

    assert.ok(order.indexOf('control.granted') < order.indexOf('state'));
    assert.deepEqual(next.said(), [{ t: 'control.granted', continued: true }]);
    assert.equal(next.ui.open().length, 0);
    assert.deepEqual(next.grants, []);
    assert.equal(next.ui.cursorState.mode, 'control');

    const reached = watchActions();

    await next.send({ t: 'click', x: 40, y: 50 });
    assert.ok(reached.length > 0);

    // End remote control ends it, logged once.
    next.ui.press('endControl');
    await settle();
    assert.deepEqual(next.said().at(-1), { t: 'control.released', reason: 'visitor' });
    assert.deepEqual(next.grants, [['control', false]]);
});

test('control does not go on past a page the assist did not run on, the back button, or a late page: ended and logged once', async () => {
    const cases = [
        // A page in between (not recorded, another site): the referrer is that page.
        { type: 'navigate', referrer: 'https://shop.test/excluded', later: 1500 },
        { type: 'navigate', referrer: '', later: 1500 },
        { type: 'back_forward', referrer: 'https://shop.test/checkout', later: 1500 },
        { type: 'navigate', referrer: 'https://shop.test/checkout', later: 31_000 },
    ];

    for (const { type, referrer, later } of cases) {
        const first = await handedOver();
        const next = await live({ store: first.store, clock: first.now() + later, href: 'https://shop.test/account', arrival: { type, referrer, href: 'https://shop.test/account' }, assist: { controlAcrossPages: true } });

        assert.equal(next.ui.outlined, false, `${type} ${referrer} ${later}`);
        assert.deepEqual(next.grants, [['control', false]]);
        // The agent's stage hears it ended, before the new page's first state.
        assert.deepEqual(next.said(), [{ t: 'control.released', reason: 'navigation' }]);
        assert.ok(next.all().findIndex((message) => message.t === 'control.released') < next.all().findIndex((message) => message.t === 'state'));
        assert.equal(JSON.parse(next.store.get('sr:assist')).carry, undefined);

        const reached = watchActions();

        await next.send({ t: 'click', x: 40, y: 50 });
        assert.deepEqual(reached, []);
    }
});

test('a reload of the same page keeps control; its fragment does not matter', async () => {
    const first = await handedOver();
    const next = await live({ store: first.store, clock: first.now() + 900, href: 'https://shop.test/checkout#top', arrival: { type: 'reload', referrer: '', href: 'https://shop.test/checkout#top' }, assist: { controlAcrossPages: true } });

    assert.deepEqual(next.said(), [{ t: 'control.granted', continued: true }]);
});

test('a new page the agent does not see (no snapshot) ends control instead of taking it over', async () => {
    const first = await handedOver();
    const next = await live({ store: first.store, clock: first.now() + 900, mirrored: false, arrival: { type: 'navigate', referrer: 'https://shop.test/checkout', href: 'https://shop.test/checkout' }, assist: { controlAcrossPages: true } });

    assert.deepEqual(next.said(), [{ t: 'control.released', reason: 'not_recorded' }]);
    assert.equal(next.ui.outlined, false);
    assert.deepEqual(next.grants, [['control', false]]);
});

test('a channel that does not come back in time ends the control handed over, logged once', async () => {
    const first = await handedOver();
    const next = await live({
        store: first.store,
        clock: first.now() + 900,
        arrival: { type: 'navigate', referrer: 'https://shop.test/checkout', href: 'https://shop.test/checkout' },
        assist: { controlAcrossPages: true },
        // Every timer runs before the channel comes: the hand-over's runs out.
        onInstall: async ({ timers }) => {
            await timers.run(Infinity);
            await settle();
        },
    });

    assert.equal(next.ui.outlined, false);
    assert.deepEqual(next.grants, [['control', false]]);
    assert.deepEqual(next.said(), [{ t: 'control.released', reason: 'reconnect' }]);
    assert.equal(next.ui.cursorState.mode, null);
});

test('the masked-typing consent goes on with control and ends with it', async () => {
    const first = await handedOver();
    const stored = JSON.parse(first.store.get('sr:assist'));

    // As a page left with the visitor's yes to typing into masked fields.
    stored.carry.masked = true;
    first.store.set('sr:assist', JSON.stringify(stored));

    const next = await live({ store: first.store, clock: first.now() + 900, arrival: { type: 'navigate', referrer: 'https://shop.test/checkout', href: 'https://shop.test/checkout' }, assist: { controlAcrossPages: true, controlMasked: true } });

    assert.deepEqual(next.said(), [{ t: 'control.granted', continued: true }]);

    await next.send({ t: 'control.release' });
    assert.deepEqual(next.grants, [['control', false], ['control_masked', false]]);
});

test('with control_across_pages off, leaving the page ends control as before', async () => {
    const first = await handedOver({ assist: { controlAcrossPages: false } });

    assert.equal(JSON.parse(first.store.get('sr:assist')).carry, undefined);
    assert.deepEqual(first.grants, [['control', true], ['control', false]]);
});

test('wire:navigate: control pauses during the swap, nothing is acted on, and goes on once the new page\'s snapshot went out', async () => {
    const page = await live({ assist: { controlAcrossPages: true } });

    await page.send({ t: 'control.request' });
    await page.answer(true);

    const reached = watchActions();

    globalThis.document.dispatchEvent(new Event('livewire:navigating'));
    await settle();
    assert.deepEqual(page.said().at(-1), { t: 'control.released', reason: 'navigation', paused: true });

    await page.send({ t: 'click', x: 40, y: 50 });
    assert.deepEqual(reached, []);

    globalThis.document.dispatchEvent(new Event('livewire:navigated'));
    await settle();
    assert.deepEqual(page.said().at(-1), { t: 'control.granted', continued: true });
    assert.equal(page.ui.shown.text, 'stillControlling');
    assert.equal(page.ui.outlined, true);

    await page.send({ t: 'click', x: 40, y: 50 });
    assert.ok(reached.length > 0);
    // One grant period.
    assert.deepEqual(page.grants, [['control', true]]);
});

test('wire:navigate to a page the app does not record, or signed for someone else, ends control', async () => {
    for (const change of [(page) => (page.mirrors = false), (page) => (page.identity = 'other')]) {
        const page = await live({ assist: { controlAcrossPages: true } });

        await page.send({ t: 'control.request' });
        await page.answer(true);

        globalThis.document.dispatchEvent(new Event('livewire:navigating'));
        change(page.page);
        globalThis.document.dispatchEvent(new Event('livewire:navigated'));
        await settle();

        assert.equal(page.said().at(-1).t, 'control.released');
        assert.equal(page.ui.outlined, false);
        assert.deepEqual(page.grants, [['control', true], ['control', false]]);
    }
});

test('wire:navigate with control_across_pages off ends control at once', async () => {
    const page = await live();

    await page.send({ t: 'control.request' });
    await page.answer(true);

    globalThis.document.dispatchEvent(new Event('livewire:navigating'));
    await settle();

    assert.deepEqual(page.said().at(-1), { t: 'control.released', reason: 'navigation' });
});
