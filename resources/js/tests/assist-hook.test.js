import assert from 'node:assert/strict';
import { test } from 'node:test';
import { STATE_KEY, TAB_KEY, createAssistHook, storedRequest, tabId } from '../lib/assist-hook.js';

function storage(initial = {}) {
    const data = { ...initial };

    return { data, read: (key) => data[key] ?? null, write: (key, value) => (data[key] = value) };
}

function fakeDocument(visibilityState = 'visible') {
    const listeners = {};

    return { visibilityState, listeners, addEventListener: (type, fn) => ((listeners[type] ||= []).push(fn)) };
}

/** A hook with a fake module: counts the imports, records what the module was asked. */
function hook({ store = storage(), settings = {}, fetch, recording = { id: 'rec-1', key: 'k1' }, fail = false } = {}) {
    const imports = [];
    const asked = [];
    const module = {
        installAssist(lend) {
            asked.push(['install', lend.tab]);

            return {
                tap: (event) => asked.push(['tap', event]),
                knock: (assist) => asked.push(['knock', assist.id]),
                api: { request: async (options) => asked.push(['request', options]) && { id: 'r1', status: 'open', agent: null }, cancel: async () => asked.push(['cancel']), end: async () => asked.push(['end']), status: () => ({ id: 'r1', status: 'open', agent: null }) },
            };
        },
    };
    const instance = createAssistHook({
        settings: () => ({ module: 'https://shop.test/session-replay/scripts/assist.js?v=1', routes: { heartbeat: '/sr/assist/heartbeat' }, heartbeatMs: 60_000, ...settings }),
        token: () => 'tok',
        recording: () => recording,
        read: store.read,
        write: store.write,
        load: async (url) => {
            imports.push(url);
            if (fail) throw new Error('Refused by the CSP');

            return module;
        },
        lend: {},
        fetch: fetch || (async () => ({ ok: true, json: async () => ({ ok: true, assist: null }) })),
        document: fakeDocument(),
    });

    return { instance, imports, asked, store };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('the tab keeps one id for its life, in sessionStorage', () => {
    const store = storage();
    const first = tabId(store.read, store.write);

    assert.match(first, /^[a-f0-9]{24}$/);
    assert.equal(store.data[TAB_KEY], first);
    assert.equal(tabId(store.read, store.write), first);

    // Something that is not an id is replaced.
    store.write(TAB_KEY, '<script>');
    assert.notEqual(tabId(store.read, store.write), '<script>');
});

test('a recorded page with Assist on loads nothing of it until it is needed', async () => {
    const { instance, imports, asked } = hook();

    instance.start();
    instance.tap({ type: 3 });
    await settle();

    assert.deepEqual(imports, []);
    assert.equal(instance.loaded(), false);
    assert.deepEqual(asked, []);
    assert.deepEqual(instance.api.status(), { id: null, status: null, agent: null });
    await instance.api.cancel();
    assert.deepEqual(imports, []);

    instance.stop();
});

test('asking for help loads the module once, with the tab id, and hands it the request', async () => {
    const { instance, imports, asked, store } = hook();

    await Promise.all([instance.api.request({ message: 'Help' }), instance.api.request({ message: 'Again' })]);

    assert.deepEqual(imports, ['https://shop.test/session-replay/scripts/assist.js?v=1']);
    assert.deepEqual(asked[0], ['install', store.data[TAB_KEY]]);
    assert.deepEqual(asked.slice(1), [['request', { message: 'Help' }], ['request', { message: 'Again' }]]);

    // Loaded, the events go to it.
    instance.tap({ type: 2 });
    assert.deepEqual(asked.at(-1), ['tap', { type: 2 }]);
});

test('a knock, a request going in the tab, or the button load it', async () => {
    const knocked = hook();

    knocked.instance.knock(null);
    await settle();
    assert.deepEqual(knocked.imports, []);

    knocked.instance.knock({ id: 'offer-1', agent: { name: 'Ana' } });
    await settle();
    assert.deepEqual(knocked.asked.at(-1), ['knock', 'offer-1']);

    const reloaded = hook({ store: storage({ [STATE_KEY]: JSON.stringify({ id: 'r1', secret: 's', status: 'claimed', agent: { name: 'Ana', avatar: null } }) }) });

    assert.deepEqual(reloaded.instance.api.status(), { id: 'r1', status: 'claimed', agent: { name: 'Ana', avatar: null } });
    reloaded.instance.start();
    await settle();
    assert.equal(reloaded.imports.length, 1);
    reloaded.instance.stop();

    const finished = hook({ store: storage({ [STATE_KEY]: JSON.stringify({ id: 'r1', secret: 's', status: 'ended' }) }) });

    finished.instance.start();
    await settle();
    assert.equal(finished.imports.length, 0);
    finished.instance.stop();

    const button = hook({ settings: { button: true } });

    button.instance.start();
    await settle();
    assert.equal(button.imports.length, 1);
    button.instance.stop();
});

test('the heartbeat posts the triple in the body, and an offer in its answer loads the module', async () => {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url, init });

        return { ok: true, json: async () => ({ ok: true, assist: { id: 'offer-1', agent: { name: 'Ana' } } }) };
    };
    const { instance, asked } = hook({ fetch });

    await instance.heartbeat();
    await settle();
    instance.stop();

    assert.equal(calls[0].url, '/sr/assist/heartbeat');
    assert.equal(calls[0].init.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].init.body), { token: 'tok', session: 'rec-1', key: 'k1' });
    assert.deepEqual(asked.at(-1), ['knock', 'offer-1']);
});

test('no heartbeat without a recording; a module the page refuses leaves the recording alone and is tried again', async () => {
    const calls = [];
    const quiet = hook({ recording: null, fetch: async (...args) => calls.push(args) });

    await quiet.instance.heartbeat();
    quiet.instance.stop();
    assert.equal(calls.length, 0);

    const refused = hook({ fail: true });

    await assert.rejects(refused.instance.api.request({}));
    await assert.rejects(refused.instance.api.request({}));
    assert.equal(refused.imports.length, 2);
});

test('the stored request is read only with an id and a secret', () => {
    assert.equal(storedRequest(() => 'not json'), null);
    assert.equal(storedRequest(() => JSON.stringify({ id: 'r1' })), null);
    assert.deepEqual(storedRequest(() => JSON.stringify({ id: 'r1', secret: 's' })), { id: 'r1', secret: 's' });
});
