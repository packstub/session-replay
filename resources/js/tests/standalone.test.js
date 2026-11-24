import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HEADER, configProblem, readTokenResponse, replayHeader, sameOrigin, tokenHeaders, tokenUrl } from '../lib/standalone.js';

const endpoint = 'https://api.example.com/session-replay/token';
const page = 'https://app.example.com';

function config(overrides = {}) {
    return {
        token: 'payload.signature',
        identity: 'abc123',
        ingestUrl: 'https://api.example.com/session-replay/ingest',
        assetUrl: 'https://api.example.com/session-replay/ingest/asset',
        sampleRate: 1,
        idleTimeout: 1_800_000,
        flushInterval: 5000,
        maxBatchBytes: 1_572_864,
        consent: 'always',
        respectGpc: false,
        privacy: { maskAllInputs: true, redactQuery: ['token'] },
        capture: { console: ['error'], errors: true, livewire: true, requests: 500, vitals: true, rageClicks: true },
        size: { dedupeStylesheets: true, dedupeMinBytes: 2048, stripAttributes: ['wire:*'], keepAttributes: [], sampling: {} },
        snapshots: { share: true, volatileIds: [], url: 'https://api.example.com/session-replay/ingest/snapshot' },
        cookie: 'session_replay_id',
        mode: 'session',
        onError: null,
        appName: 'Acme',
        assist: { routes: {} },
        ...overrides,
    };
}

test('the token URL resolves against the page and takes http or https only, without credentials or a fragment', () => {
    assert.equal(tokenUrl(endpoint, page), endpoint);
    assert.equal(tokenUrl('/session-replay/token#x', 'https://app.test/orders'), 'https://app.test/session-replay/token');
    assert.throws(() => tokenUrl('', page), TypeError);
    assert.throws(() => tokenUrl(undefined, page), TypeError);
    assert.throws(() => tokenUrl('javascript:alert(1)', page), TypeError);
    assert.throws(() => tokenUrl('https://user:secret@api.example.com/session-replay/token', page), TypeError);
});

test('an answer to record is started with Assist, shared snapshots and the cross-origin cookie forced off', () => {
    const result = readTokenResponse({ record: true, config: config(), expires_at: '2026-12-01T10:00:00+00:00' }, endpoint, page);

    assert.equal(result.record, true);
    assert.equal(result.config.assist, null);
    assert.equal(result.config.snapshots.share, false);
    assert.equal(result.config.cookie, null);
    assert.equal(result.config.token, 'payload.signature');
    assert.equal(result.expiresAt, Date.parse('2026-12-01T10:00:00Z'));
});

test('a page on the token route\'s own origin keeps the cookie', () => {
    const result = readTokenResponse({ record: true, config: config() }, endpoint, 'https://api.example.com');

    assert.equal(result.config.cookie, 'session_replay_id');
    assert.equal(result.expiresAt, null);
});

test('record false is passed on; anything else that is no answer is refused', () => {
    assert.deepEqual(readTokenResponse({ record: false }, endpoint, page), { record: false });

    for (const body of [null, 'yes', [], {}, { record: 'true' }, { record: true }, { record: true, config: [] }]) {
        assert.equal(readTokenResponse(body, endpoint, page), null, JSON.stringify(body));
    }
});

test('a config that sends the recording anywhere but the token route\'s origin is refused', () => {
    for (const [field, value] of [
        ['ingestUrl', 'https://evil.test/session-replay/ingest'],
        ['ingestUrl', 'http://api.example.com/session-replay/ingest'],
        ['ingestUrl', '/session-replay/ingest'],
        ['assetUrl', 'https://api.example.com.evil.test/asset'],
        ['snapshots', { share: false, url: 'https://evil.test/snapshot' }],
        ['snapshots', null],
    ]) {
        const problem = configProblem(config({ [field]: value }), endpoint);

        assert.notEqual(problem, null, `${field}: ${JSON.stringify(value)}`);
        assert.equal(readTokenResponse({ record: true, config: config({ [field]: value }) }, endpoint, page), null);
    }
});

test('a config of the wrong shape is refused', () => {
    for (const overrides of [
        { token: '' },
        { token: 42 },
        { token: 'x'.repeat(20_000) },
        { identity: 7 },
        { sampleRate: 2 },
        { sampleRate: '1' },
        { idleTimeout: 0 },
        { flushInterval: 10 },
        { maxBatchBytes: -1 },
        { consent: 'maybe' },
        { mode: 'always' },
        { mode: 'on_error', onError: null },
        { privacy: { redactQuery: 'token' } },
        { capture: null },
        { size: { stripAttributes: [], keepAttributes: [1] } },
    ]) {
        assert.notEqual(configProblem(config(overrides), endpoint), null, JSON.stringify(overrides));
    }

    assert.equal(configProblem(config(), endpoint), null);
    assert.equal(configProblem(config({ identity: null }), endpoint), null);
});

test('same origin means scheme, host and port', () => {
    assert.equal(sameOrigin('https://api.example.com/x', 'https://api.example.com'), true);
    assert.equal(sameOrigin('https://api.example.com:8443/x', 'https://api.example.com'), false);
    assert.equal(sameOrigin('http://api.example.com/x', 'https://api.example.com'), false);
    assert.equal(sameOrigin('not a url', 'https://api.example.com'), false);
    assert.equal(sameOrigin('data:text/plain,hi', 'null'), false);
});

test('the header carries the id and the write key, and nothing for no recording or a malformed one', () => {
    const id = '0b0f5c3e-6f37-4b0a-9d3c-2a1f0e9b8c7d';

    assert.equal(HEADER, 'X-Session-Replay');
    assert.deepEqual(replayHeader({ id, key: 'a1b2c3' }), { 'X-Session-Replay': `${id}.a1b2c3` });
    assert.deepEqual(replayHeader({ id, key: null }), { 'X-Session-Replay': id });
    assert.deepEqual(replayHeader({ id, key: 'not a key!' }), { 'X-Session-Replay': id });
    assert.deepEqual(replayHeader(null), {});
    assert.deepEqual(replayHeader({ id: '<script>', key: 'a' }), {});
});

test('the token request asks for JSON and takes only string headers of the app', () => {
    assert.deepEqual(tokenHeaders({ Authorization: 'Bearer t', Bad: 1 }), { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', Authorization: 'Bearer t' });
    assert.deepEqual(tokenHeaders(null), { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
});

test('the ask dialog module can be imported where there is no document (server-side rendering)', async () => {
    assert.equal(typeof globalThis.document, 'undefined');

    const module = await import('../ask.js');

    assert.equal(typeof module.ask, 'function');
});
