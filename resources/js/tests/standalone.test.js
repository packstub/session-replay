import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HEADER, configProblem, createTokenRefresher, readTokenResponse, refreshDelay, retryDelay, replayHeader, sameOrigin, tokenHeaders, tokenLifetime, tokenUrl } from '../lib/standalone.js';

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

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

test('the lifetime is the server\'s own count, whatever this browser\'s clock says', () => {
    const body = { record: true, expires_in: 604_800, expires_at: '2026-12-01T10:00:00+00:00' };

    // A clock a year ahead or behind changes nothing.
    for (const now of [Date.parse('2026-11-24T10:00:00Z'), Date.parse('2027-11-24T10:00:00Z'), Date.parse('2025-11-24T10:00:00Z')]) {
        assert.equal(tokenLifetime(body, now), 7 * DAY);
    }

    assert.equal(readTokenResponse({ ...body, config: config() }, endpoint, page, Date.parse('2030-01-01T00:00:00Z')).lifetime, 7 * DAY);

    // A token that never expires, and counts that are none.
    assert.equal(tokenLifetime({ expires_in: null, expires_at: null }, 0), null);
    assert.equal(tokenLifetime({ expires_in: 0 }, 0), null);
    assert.equal(tokenLifetime({ expires_in: -5 }, 0), null);
    assert.equal(tokenLifetime(null, 0), null);
    assert.equal(tokenLifetime({}, 0), null);
});

test('an app from before expires_in is read by its expires_at against this browser\'s clock', () => {
    const body = { expires_at: '2026-12-01T10:00:00+00:00' };
    const issued = Date.parse('2026-11-24T10:00:00Z');

    assert.equal(tokenLifetime(body, issued), 7 * DAY);
    // A clock an hour behind takes the token for an hour longer-lived: half of that is still well inside its life.
    assert.equal(tokenLifetime(body, issued - HOUR), 7 * DAY + HOUR);
    assert.ok(refreshDelay(tokenLifetime(body, issued - HOUR)) < 7 * DAY);
    // A clock ahead of the expiry: no lifetime to plan with, the 401 answer is what is left.
    assert.equal(tokenLifetime(body, issued + 8 * DAY), null);
    assert.equal(tokenLifetime({ expires_at: 'soon' }, issued), null);
    assert.equal(tokenLifetime({ expires_in: '3600', expires_at: 'soon' }, issued), null);
});

test('a token is renewed at half its life, and a failed renewal is tried again ever later', () => {
    assert.equal(refreshDelay(7 * DAY), 3.5 * DAY);
    assert.equal(refreshDelay(DAY), 12 * HOUR);
    assert.equal(refreshDelay(4000), 2000);
    assert.equal(refreshDelay(10), 1000);

    assert.deepEqual([1, 2, 3, 4, 5, 6, 7, 500].map(retryDelay), [30_000, 60_000, 120_000, 240_000, 480_000, 900_000, 900_000, 900_000]);
});

/** A clock and timers to move by hand; run() lets promises settle after each timer. */
function fakeTime(start = 1_000_000) {
    const time = { now: start, timers: new Map(), next: 1, delays: [] };

    time.set = (callback, ms) => {
        time.delays.push(ms);
        time.timers.set(time.next, { at: time.now + ms, callback });

        return time.next++;
    };
    time.clear = (id) => time.timers.delete(id);
    time.advance = async (ms) => {
        const end = time.now + ms;

        for (;;) {
            const due = [...time.timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];

            if (!due) break;

            time.timers.delete(due[0]);
            time.now = due[1].at;
            due[1].callback();
            await settle();
        }

        time.now = end;
    };

    return time;
}

const settle = async () => {
    for (let i = 0; i < 10; i++) await Promise.resolve();
};

function refresherWith(time, load, ready = () => true) {
    return createTokenRefresher({ load, ready, now: () => time.now, setTimer: time.set, clearTimer: time.clear });
}

test('the refresher asks for a token at half the life and plans the next from the new one', async () => {
    const time = fakeTime();
    const asked = [];
    const refresher = refresherWith(time, async () => {
        asked.push(time.now - 1_000_000);

        return { lifetime: 2 * DAY };
    });

    refresher.set(DAY);
    // No timer waits longer than five minutes, far below setTimeout's 24.8 days.
    assert.ok(Math.max(...time.delays) <= 5 * MINUTE);

    await time.advance(12 * HOUR - 1);
    assert.deepEqual(asked, []);

    await time.advance(1);
    assert.deepEqual(asked, [12 * HOUR]);

    // The new token lives two days: the next one is asked for a day later.
    await time.advance(DAY - 1);
    assert.equal(asked.length, 1);
    await time.advance(1);
    assert.deepEqual(asked, [12 * HOUR, 36 * HOUR]);
    assert.ok(Math.max(...time.delays) <= 5 * MINUTE);
});

test('a token that never expires is never renewed', async () => {
    const time = fakeTime();
    let asked = 0;
    const refresher = refresherWith(time, async () => ++asked && { lifetime: null });

    refresher.set(null);
    assert.equal(refresher.scheduled(), false);

    await time.advance(30 * DAY);
    refresher.check();
    assert.equal(asked, 0);
});

test('a timer that slept is made up for when the page is shown', async () => {
    const time = fakeTime();
    let asked = 0;
    const refresher = refresherWith(time, async () => ++asked && { lifetime: DAY });

    refresher.set(DAY);

    // The laptop slept: the clock moved, no timer ran.
    time.now += 13 * HOUR;
    refresher.check();
    await settle();
    assert.equal(asked, 1);

    // Shown again a moment later: nothing is due.
    refresher.check();
    await settle();
    assert.equal(asked, 1);
});

test('one request at a time: the schedule and an upload answered 401 share it', async () => {
    const time = fakeTime();
    let asked = 0;
    let release;
    const refresher = refresherWith(time, () => {
        asked++;

        return new Promise((resolve) => {
            release = () => resolve({ lifetime: DAY, config: { token: 'fresh' } });
        });
    });

    refresher.set(DAY);
    await time.advance(12 * HOUR);
    assert.equal(asked, 1);
    assert.equal(refresher.pending(), true);

    const first = refresher.refresh();
    const second = refresher.refresh();

    refresher.check();
    assert.equal(first, second);
    assert.equal(asked, 1);

    release();
    assert.equal((await first).config.token, 'fresh');
    assert.equal(refresher.pending(), false);
    assert.equal(asked, 1);

    // Once it is answered, a refused upload gets a request of its own.
    refresher.refresh();
    await settle();
    assert.equal(asked, 2);
});

test('a renewal that fails is tried again later and given up once the token has expired', async () => {
    const time = fakeTime();
    const asked = [];
    let answer = () => null;
    const refresher = refresherWith(time, async () => {
        asked.push(time.now - 1_000_000);

        return answer();
    });

    refresher.set(HOUR);
    await time.advance(30 * MINUTE);
    assert.deepEqual(asked, [30 * MINUTE]);

    // 30 s, then a minute after that: no hammering.
    await time.advance(29_999);
    assert.equal(asked.length, 1);
    await time.advance(1);
    assert.equal(asked.length, 2);
    await time.advance(60_000);
    assert.equal(asked.length, 3);

    // A rejection counts as a failure too.
    answer = () => Promise.reject(new Error('offline'));
    await time.advance(120_000);
    assert.equal(asked.length, 4);

    // The token expires an hour after it came: the attempt after that is the last.
    answer = () => null;
    await time.advance(HOUR);
    const tries = asked.length;

    assert.ok(asked[tries - 1] >= HOUR);
    assert.ok(asked[tries - 2] < HOUR);
    assert.equal(refresher.scheduled(), false);

    await time.advance(DAY);
    refresher.check();
    await settle();
    assert.equal(asked.length, tries);

    // An upload answered 401 still asks, once per call.
    answer = () => ({ lifetime: HOUR });
    await refresher.refresh();
    assert.equal(asked.length, tries + 1);
    assert.equal(refresher.scheduled(), true);
});

test('no token for this person any more: nothing further is asked until a new one is set', async () => {
    const time = fakeTime();
    let asked = 0;
    const refresher = refresherWith(time, async () => ++asked && false);

    refresher.set(HOUR);
    await time.advance(30 * MINUTE);
    assert.equal(asked, 1);
    assert.equal(refresher.scheduled(), false);

    await time.advance(DAY);
    refresher.check();
    await settle();
    assert.equal(asked, 1);

    refresher.set(HOUR);
    assert.equal(refresher.scheduled(), true);
});

test('stop() clears the timer, and a request that was under way decides nothing', async () => {
    const time = fakeTime();
    let asked = 0;
    let release;
    const refresher = refresherWith(time, () => {
        asked++;

        return new Promise((resolve) => {
            release = resolve;
        });
    });

    refresher.set(HOUR);
    assert.equal(time.timers.size, 1);

    refresher.stop();
    assert.equal(time.timers.size, 0);
    await time.advance(DAY);
    refresher.check();
    assert.equal(asked, 0);

    // Under way when stop() came: its answer starts no timer.
    refresher.set(HOUR);
    await time.advance(30 * MINUTE);
    assert.equal(asked, 1);
    refresher.stop();
    release({ lifetime: HOUR });
    await settle();
    assert.equal(time.timers.size, 0);

    // resume() goes on with the token there was: due long ago by now.
    time.now += DAY;
    refresher.resume();
    await settle();
    assert.equal(asked, 2);
});

test('a new token set while a renewal is under way keeps its own schedule', async () => {
    const time = fakeTime();
    let release;
    const refresher = refresherWith(time, () => new Promise((resolve) => {
        release = resolve;
    }));

    refresher.set(HOUR);
    await time.advance(30 * MINUTE);

    // start() again, for someone else: their token lives a day.
    refresher.set(DAY);
    release(null);
    await settle();

    // Not the 30 s of a failed renewal: twelve hours.
    assert.equal(time.timers.size, 1);
    assert.equal([...time.timers.values()][0].at <= time.now + 5 * MINUTE, true);
    await time.advance(HOUR);
    assert.equal(refresher.pending(), false);
});

test('while nothing records, nothing is asked: the refresher looks again later', async () => {
    const time = fakeTime();
    let asked = 0;
    let recording = false;
    const refresher = refresherWith(time, async () => ++asked && { lifetime: HOUR }, () => recording);

    refresher.set(HOUR);
    await time.advance(45 * MINUTE);
    assert.equal(asked, 0);
    // Waiting five minutes at a time, never spinning.
    assert.ok(time.delays.every((ms) => ms >= 1000));

    recording = true;
    await time.advance(5 * MINUTE);
    assert.equal(asked, 1);
});

test('where there is no window (server-side rendering) the refresher is made without a timer or a request', async () => {
    assert.equal(typeof globalThis.window, 'undefined');

    let asked = 0;
    // The browser's own clock and timers, as standalone.js makes it when the package is imported.
    const refresher = createTokenRefresher({ load: async () => ++asked && false });

    assert.equal(refresher.scheduled(), false);
    refresher.check();
    refresher.stop();
    await settle();
    assert.equal(asked, 0);
    assert.equal(refresher.scheduled(), false);
});
