import assert from 'node:assert/strict';
import { test } from 'node:test';
import { installAssist } from '../assist.js';
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

    destroy() {}
}

/** The panel: prompts the test answers, the status panel's buttons it can press. */
function fakeUi() {
    const prompts = [];
    let shown = null;

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
        cursor: { show() {}, move() {}, hide() {} },
        outline() {},
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
            pending.set(next, fn);

            return next++;
        },
        clear(id) {
            pending.delete(id);
        },
        async run() {
            const due = [...pending.values()];

            pending.clear();
            for (const fn of due) await fn();
        },
    };
}

const routes = Object.fromEntries(['poll', 'accept', 'decline', 'cancel', 'hangup', 'leave', 'signal', 'grants'].map((name) => [name, `/sr/assist/requests/__ID__/${name}`]));

/** A visitor whose request is live with Ana (agent 5), the channel open and both hellos said. */
async function live({ stored = {}, video = false } = {}) {
    let clock = 1_000_000;
    const store = new Map([['sr:assist', JSON.stringify({ id: 'r1', secret: 's3cret', status: 'active', openedBy: 'visitor', agent: { id: '5', name: 'Ana' }, grants: {}, ...stored })]]);
    const grants = [];
    const ui = fakeUi();
    const timers = fakeTimers();

    globalThis.window = Object.assign(new EventTarget(), { innerWidth: 1280, innerHeight: 800 });
    globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    globalThis.location = { href: 'https://shop.test/checkout', origin: 'https://shop.test' };

    const fetch = async (url, init) => {
        const body = JSON.parse(init.body);
        const answer = (data) => ({ ok: true, status: 200, json: async () => data });

        if (url.endsWith('/grants')) {
            grants.push([body.kind, body.granted]);

            return answer({ ok: true });
        }

        if (url.endsWith('/poll')) {
            return answer({ id: 'r1', status: 'active', opened_by: 'visitor', agent: { id: '5', name: 'Ana' }, call: { id: 'c1', peer: { id: '5' } }, signals: [], cursor: 0 });
        }

        return answer({});
    };

    const recorder = {
        config: () => ({ token: 'tok', privacy: { maskAllInputs: true }, assist: { routes, calls: true, video, labels: {}, poll: { waiting: 3000, active: 2000 } } }),
        recording: () => ({ id: 'rec-1', key: 'k1' }),
        snapshot: () => true,
        node: () => null,
        goLive() {},
        touch() {},
        redacted: (url) => url,
        read: (key) => store.get(key) ?? null,
        write: (key, value) => store.set(key, value),
        tab: 'tab-1',
    };

    installAssist(recorder, { ui, timers, Client: FakeClient, fetch, now: () => clock });

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
        ui,
        client,
        grants,
        store,
        /** What the visitor's page sent the agent, apart from the mirror. */
        said: () => received.filter((message) => !['rr', 'css', 'state'].includes(message.t)),
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
