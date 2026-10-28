import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Endpoint, FROM_AGENT, FROM_VISITOR, LiveStyles, encodeEvent } from '../lib/assist-protocol.js';
import { AgentState, Gesture, LIVE_BUFFER_MS, LiveFeed, ago, editable, handleVisitorMessage, reasonKey, stageDelta, stagePoint } from '../lib/assist-stage.js';

// ---- fakes ---------------------------------------------------------------------

class FakeReplayer {
    constructor() {
        this.baseline = null;
        this.events = [];
        this.destroyed = false;
    }

    startLive(baseline) {
        this.baseline = baseline;
    }

    addEvent(event) {
        this.events.push(event);
    }

    destroy() {
        this.destroyed = true;
    }
}

class FakeChannel {
    constructor() {
        this.readyState = 'open';
        this.bufferedAmount = 0;
        this.bufferedAmountLowThreshold = 1024 * 1024;
        this.listeners = { message: [], close: [] };
        this.peer = null;
    }

    addEventListener(type, fn) {
        this.listeners[type].push(fn);

        // Like a channel whose page attached its handler late: what came before is delivered now.
        if (type === 'message' && this.backlog) for (const data of this.backlog.splice(0)) fn({ data });
    }

    removeEventListener(type, fn) {
        this.listeners[type] = this.listeners[type].filter((entry) => entry !== fn);
    }

    send(data) {
        if (this.peer.listeners.message.length === 0) return (this.peer.backlog ||= []).push(data);

        for (const fn of [...this.peer.listeners.message]) fn({ data });
    }

    close() {
        this.readyState = 'closed';
    }
}

function pair() {
    const a = new FakeChannel();
    const b = new FakeChannel();

    a.peer = b;
    b.peer = a;

    return [a, b];
}

const tick = () => new Promise((resolve) => setImmediate(resolve));
const css = '.u{margin:0}'.repeat(400);
const meta = (timestamp) => ({ type: 4, timestamp, data: { href: 'https://shop.test/', width: 1280, height: 800 } });
const snapshot = (timestamp, text = css) => ({
    type: 2,
    timestamp,
    data: { node: { type: 0, childNodes: [{ type: 2, tagName: 'style', attributes: { _cssText: text }, childNodes: [] }] }, initialOffset: { top: 0, left: 0 } },
});
const click = (timestamp) => ({ type: 3, timestamp, data: { source: 2, type: 2, id: 5, x: 10, y: 10 } });

function feed(now = () => 1_000_000) {
    const made = [];
    const started = [];

    const live = new LiveFeed({
        now,
        createReplayer: () => {
            const replayer = new FakeReplayer();

            made.push(replayer);

            return replayer;
        },
        onStart: () => started.push(true),
    });

    return { live, made, started };
}

// ---- the live feed ---------------------------------------------------------------

test('shifts every event by the clock offset measured on the first one', () => {
    // The visitor's clock is a minute ahead of the agent's.
    const { live, made } = feed(() => 1_000_000);

    live.event(meta(1_060_000));
    live.event(snapshot(1_060_010));
    live.event(click(1_060_500));

    const replayer = made[0];

    assert.equal(made.length, 1);
    assert.deepEqual(
        replayer.events.map((event) => event.timestamp),
        [1_000_000, 1_000_010, 1_000_500],
    );
    // Played a little behind the visitor, from the Meta event on.
    assert.equal(replayer.baseline, 1_000_000 - LIVE_BUFFER_MS);
});

test('the offset stays the one of the first event, for a clock behind too', () => {
    let now = 5_000;
    const { live, made } = feed(() => now);

    live.event(snapshot(2_000));
    now = 9_000;
    live.event(click(2_400));

    assert.deepEqual(
        made[0].events.map((event) => event.timestamp),
        [5_000, 5_400],
    );
});

test('draws nothing before a full snapshot: keeps the Meta in front of it, drops the rest', () => {
    const { live, made, started } = feed();

    live.event(click(10));
    live.event(meta(20));

    assert.equal(made.length, 0);
    assert.equal(live.started, false);

    live.event(snapshot(30));

    assert.equal(made.length, 1);
    assert.deepEqual(
        made[0].events.map((event) => event.type),
        [4, 2],
    );
    assert.equal(started.length, 1);
    assert.equal(live.events, 1);
});

test('puts the stylesheets sent apart back before the event is played', () => {
    const styles = new LiveStyles();
    const event = snapshot(100);
    const messages = styles.strip(event);
    const { live, made } = feed();

    assert.equal(messages.length, 1);

    live.css(messages[0].key, messages[0].text);
    live.event(event);

    assert.equal(made[0].events[0].data.node.childNodes[0].attributes._cssText, css);
});

test('starts a fresh Replayer for a new channel: new offset, new stylesheets', () => {
    let now = 1_000;
    const { live, made } = feed(() => now);

    live.css('1', 'a{}');
    live.event(snapshot(500));
    live.reset();

    assert.equal(made[0].destroyed, true);
    assert.equal(live.started, false);
    assert.equal(live.texts.size, 0);

    now = 2_000;
    live.event(snapshot(10_000, '/*sr-live:1*/'));

    assert.equal(made.length, 2);
    assert.equal(made[1].events[0].timestamp, 2_000);
    // The old channel's stylesheet is not this channel's: an empty sheet rather than the wrong one.
    assert.equal(made[1].events[0].data.node.childNodes[0].attributes._cssText, '');
});

// ---- pointer mapping ---------------------------------------------------------------

test('maps a point on the scaled live iframe to the visitor\'s viewport pixels', () => {
    // A 1280×800 viewport shown at half size, 100 px from the left, 50 from the top.
    const frame = { rect: { left: 100, top: 50, width: 640, height: 400 }, width: 1280, height: 800 };

    assert.deepEqual(stagePoint(100, 50, frame), { x: 0, y: 0 });
    assert.deepEqual(stagePoint(420, 250, frame), { x: 640, y: 400 });
    assert.deepEqual(stagePoint(740, 450, frame), { x: 1280, y: 800 });
    assert.equal(stagePoint(99, 60, frame), null);
    assert.equal(stagePoint(741, 60, frame), null);
    assert.equal(stagePoint(200, 451, frame), null);
});

test('maps nothing without a sized frame', () => {
    assert.equal(stagePoint(10, 10, null), null);
    assert.equal(stagePoint(10, 10, { rect: { left: 0, top: 0, width: 0, height: 0 }, width: 1280, height: 800 }), null);
    assert.equal(stagePoint(10, 10, { rect: { left: 0, top: 0, width: 100, height: 100 }, width: 0, height: 800 }), null);
});

test('scales wheel deltas to the visitor\'s pixels', () => {
    const frame = { rect: { left: 0, top: 0, width: 640, height: 400 }, width: 1280, height: 800 };

    assert.deepEqual(stageDelta(0, 100, frame), { dx: 0, dy: 200 });
    assert.deepEqual(stageDelta(5, -3, null), { dx: 5, dy: -3 });
});

// ---- gestures ----------------------------------------------------------------------

test('a press that does not move is a click where it was pressed', () => {
    const gesture = new Gesture();

    assert.deepEqual(gesture.down({ x: 10, y: 20 }), [[{ t: 'move', x: 10, y: 20 }, { droppable: true }]]);
    assert.deepEqual(gesture.move({ x: 12, y: 21 }), []);
    assert.deepEqual(gesture.up({ x: 12, y: 21 }), [[{ t: 'click', x: 10, y: 20 }, {}]]);
    // Released outside the page: still the click.
    gesture.down({ x: 5, y: 5 });
    assert.deepEqual(gesture.up(null), [[{ t: 'click', x: 5, y: 5 }, {}]]);
});

test('a press that moves is a drag from where it began', () => {
    const gesture = new Gesture();

    gesture.down({ x: 10, y: 10 });

    assert.deepEqual(gesture.move({ x: 30, y: 10 }), [
        [{ t: 'drag.start', x: 10, y: 10 }, {}],
        [{ t: 'drag', x: 30, y: 10 }, {}],
    ]);
    assert.deepEqual(gesture.move({ x: 40, y: 12 }), [[{ t: 'drag', x: 40, y: 12 }, { droppable: true }]]);
    assert.deepEqual(gesture.move(null), []);
    assert.deepEqual(gesture.up({ x: 40, y: 12 }), [[{ t: 'drag.stop' }, {}]]);
    assert.deepEqual(gesture.cancel(), []);
});

test('moving without a press sends droppable pointer samples, and a cancelled drag stops', () => {
    const gesture = new Gesture();

    assert.deepEqual(gesture.move({ x: 1, y: 2 }), [[{ t: 'move', x: 1, y: 2 }, { droppable: true }]]);

    gesture.down({ x: 0, y: 0 });
    gesture.move({ x: 50, y: 50 });

    assert.deepEqual(gesture.cancel(), [[{ t: 'drag.stop' }, {}]]);
});

test('knows which mirrored elements take typing', () => {
    assert.equal(editable({ tagName: 'INPUT', type: 'text' }), 'text');
    assert.equal(editable({ tagName: 'INPUT', type: 'email' }), 'text');
    assert.equal(editable({ tagName: 'INPUT', type: 'password' }), 'text');
    assert.equal(editable({ tagName: 'INPUT', type: 'checkbox' }), null);
    assert.equal(editable({ tagName: 'INPUT', type: 'submit' }), null);
    assert.equal(editable({ tagName: 'TEXTAREA' }), 'text');
    assert.equal(editable({ tagName: 'SELECT' }), 'select');
    assert.equal(editable({ tagName: 'BUTTON' }), null);
    assert.equal(editable(null), null);
});

// ---- the agent's state ---------------------------------------------------------------

test('says why a control is not available', () => {
    assert.equal(new AgentState({ calls: false }).why('control'), 'no_calls');
    assert.equal(new AgentState({ routes: false }).why('audio'), 'no_routes');

    const state = new AgentState({ video: false });

    assert.equal(state.why('control'), 'connecting');
    assert.equal(state.why('share'), 'connecting');

    state.open();

    assert.equal(state.why('control'), null);
    assert.equal(state.why('video'), 'no_video');

    state.receive({ t: 'state', hidden: true, url: 'https://shop.test/', width: 1280, height: 800 });

    assert.equal(state.why('control'), 'hidden');
    assert.equal(state.why('draw'), null);

    state.mismatch();

    assert.equal(state.why('control'), 'version');

    state.end();

    assert.equal(state.why('control'), 'ended');
    assert.equal(state.why('share'), 'ended');
});

test('control: asked, granted, refused for one action without ending, released by the visitor', () => {
    const state = new AgentState();

    state.open();

    assert.deepEqual(state.askControl(), { t: 'control.request' });
    assert.equal(state.askControl(), null);
    assert.equal(state.why('control'), 'asking');

    assert.deepEqual(state.receive({ t: 'control.granted' }), [{ note: 'control_granted' }]);
    assert.equal(state.control, 'on');

    // A masked field: the visitor's page refuses that one input, control stays.
    assert.deepEqual(state.receive({ t: 'control.refused', reason: 'masked' }), [{ note: 'action_refused', reason: 'masked' }]);
    assert.equal(state.control, 'on');

    assert.deepEqual(state.receive({ t: 'control.released', reason: 'visitor' }), [{ stopControl: true }, { note: 'control_released', reason: 'visitor' }]);
    assert.equal(state.control, 'off');
    assert.deepEqual(state.receive({ t: 'control.released', reason: 'visitor' }), []);
});

test('control: the visitor says no, and a new channel never keeps it', () => {
    const state = new AgentState();

    state.open();
    state.askControl();

    assert.deepEqual(state.receive({ t: 'control.refused', reason: 'declined' }), [{ stopControl: true }, { note: 'control_refused', reason: 'declined' }]);
    assert.equal(state.control, 'off');

    state.askControl();
    state.receive({ t: 'control.granted' });
    state.close();

    assert.equal(state.control, 'off');

    state.open();

    assert.equal(state.control, 'off');
    assert.deepEqual(state.releaseControl(), null);
});

test('audio and video: asked, granted (the agent\'s own media starts), refused, ended', () => {
    const state = new AgentState({ video: true });

    state.open();

    assert.deepEqual(state.askMedia('audio'), { t: 'media.request', kind: 'audio' });
    assert.equal(state.why('audio'), 'asking');
    assert.deepEqual(state.receive({ t: 'media.granted', kind: 'audio' }), [{ media: 'audio' }, { note: 'audio_granted' }]);
    assert.equal(state.audio, 'on');
    assert.equal(state.askMedia('audio'), null);

    assert.deepEqual(state.askMedia('video'), { t: 'media.request', kind: 'video' });
    assert.deepEqual(state.receive({ t: 'media.refused', kind: 'video' }), [{ note: 'video_refused' }]);
    assert.equal(state.video, 'off');

    assert.deepEqual(state.stopMedia('audio'), { t: 'media.stop', kind: 'audio' });
    assert.equal(state.stopMedia('audio'), null);
});

test('screen share: the view switches to the visitor\'s screen and back when it stops', () => {
    const state = new AgentState();

    state.open();

    assert.equal(state.setMode('screen'), 'mirror');
    assert.deepEqual(state.askScreen(), { t: 'screen.request' });
    assert.deepEqual(state.receive({ t: 'screen.granted' }), [{ mode: 'screen' }, { note: 'screen_granted' }]);
    assert.equal(state.mode, 'screen');
    assert.equal(state.why('control'), 'screen_mode');
    assert.equal(state.why('draw'), 'screen_mode');
    assert.equal(state.setMode('mirror'), 'mirror');
    assert.equal(state.setMode('screen'), 'screen');

    assert.deepEqual(state.receive({ t: 'screen.stopped' }), [{ mode: 'mirror' }, { note: 'screen_stopped' }]);
    assert.equal(state.mode, 'mirror');
    assert.equal(state.screen, 'off');
});

test('names the visitor\'s reasons it knows, and only those', () => {
    assert.equal(reasonKey('masked'), 'masked');
    assert.equal(reasonKey('password'), 'password');
    assert.equal(reasonKey('something_new'), 'other');
    assert.equal(reasonKey(null), 'other');
});

test('says how long ago the visitor was seen', () => {
    assert.deepEqual(ago(12.4), { unit: 's', value: 12 });
    assert.deepEqual(ago(130), { unit: 'min', value: 2 });
    assert.equal(ago(null), null);
});

// ---- both sides over a channel ------------------------------------------------------

test('the stage plays what the visitor\'s page sends and its controls follow the visitor\'s answers', async () => {
    const [visitorSide, agentSide] = pair();
    const { live, made } = feed(() => 50_000);
    const state = new AgentState();
    const effects = [];
    const received = [];

    const agent = new Endpoint({
        channel: agentSide,
        role: 'agent',
        schema: FROM_VISITOR,
        drain: async () => {},
        onReady: () => state.open(),
        onMessage: (message) => effects.push(...handleVisitorMessage(message, { feed: live, state })),
    }).start();

    const visitor = new Endpoint({
        channel: visitorSide,
        role: 'visitor',
        schema: FROM_AGENT,
        context: { viewport: () => ({ width: 1280, height: 800 }), knows: (id) => id === 5 },
        drain: async () => {},
        onMessage: (message) => received.push(message),
    }).start();

    await tick();

    assert.equal(state.connected, true);

    // The visitor's half: a stylesheet once, then the events pointing at it.
    const styles = new LiveStyles();
    const first = snapshot(60_000);

    for (const message of styles.strip(first)) visitor.send(message);
    visitor.sendRaw(encodeEvent(JSON.stringify(meta(59_990))));
    visitor.sendRaw(encodeEvent(JSON.stringify(first)));
    visitor.send({ t: 'state', hidden: false, url: 'https://shop.test/cart', width: 1280, height: 800 });
    await tick();

    assert.equal(made.length, 1);
    assert.deepEqual(
        made[0].events.map((event) => [event.type, event.timestamp]),
        [
            [4, 50_000],
            [2, 50_010],
        ],
    );
    assert.equal(made[0].events[1].data.node.childNodes[0].attributes._cssText, css);
    assert.equal(state.url, 'https://shop.test/cart');

    // The agent asks for control; the visitor's page grants it; a click goes over.
    agent.send(state.askControl());
    await tick();

    assert.deepEqual(received.at(-1), { t: 'control.request' });

    visitor.send({ t: 'control.granted' });
    await tick();

    assert.equal(state.control, 'on');

    agent.send({ t: 'click', x: 100, y: 200 });
    agent.send({ t: 'input', id: 5, value: 'hello' });
    agent.send({ t: 'annot.start', x: 5000, y: 10 });
    await tick();

    assert.deepEqual(received.slice(-2), [
        { t: 'click', x: 100, y: 200 },
        { t: 'input', id: 5, value: 'hello' },
    ]);

    visitor.send({ t: 'control.refused', reason: 'masked' });
    visitor.send({ t: 'control.released', reason: 'hidden' });
    await tick();

    assert.equal(state.control, 'off');
    assert.deepEqual(
        effects.filter((effect) => effect.note).map((effect) => [effect.note, effect.reason]),
        [
            ['control_granted', undefined],
            ['action_refused', 'masked'],
            ['control_released', 'hidden'],
        ],
    );

    // Something the visitor's page may not send is dropped, not played.
    visitorSide.send(JSON.stringify({ t: 'control.request' }));
    visitorSide.send(JSON.stringify({ t: 'rr', e: { type: 99, timestamp: 1 } }));
    await tick();

    assert.equal(made[0].events.length, 2);
    assert.equal(agent.bad, 2);
});
