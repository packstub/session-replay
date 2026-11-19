import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_RETRIES, RETRY_AFTER_MAX_MS, SPREAD_MAX_MS, SPREAD_MIN_MS, isOutage, pendingFate, pendingKey, retryAfterDelay, retryDelay, spreadDelay, timeLeft } from '../lib/pending.js';

const record = (overrides = {}) => ({ session: 'tab-a', seq: 0, identity: 'p1', savedAt: 100_000, ...overrides });
const page = { sessionId: 'tab-a', identity: 'p1', now: 160_000, idleTimeout: 1_800_000 };

test('retries for about four minutes: doubling from 2 s, then once a minute, around the middle of the jitter', () => {
    const middle = (tries) => retryDelay(tries, () => 0.5);

    assert.deepEqual([1, 2, 3, 4, 5, 6, 7, 8].map(middle), [2_000, 4_000, 8_000, 16_000, 32_000, 60_000, 60_000, 60_000]);
    assert.equal(middle(0), 2_000);

    const total = Array.from({ length: MAX_RETRIES }, (_, i) => middle(i + 1)).reduce((sum, ms) => sum + ms, 0);

    assert.ok(total >= 180_000 && total <= 300_000, `${total} ms in all`);
});

test('jitters every retry from half its step to one and a half times it', () => {
    assert.equal(retryDelay(1, () => 0), 1_000);
    assert.equal(retryDelay(1, () => 0.999999), 3_000);
    assert.equal(retryDelay(6, () => 0), 30_000);
    assert.equal(retryDelay(8, () => 1), 90_000);
    assert.equal(retryDelay(3, () => 0.25), 6_000);
    assert.equal(retryDelay(3, () => Number.NaN), 8_000, 'a broken random source falls back to the middle');

    // Tabs that failed together come back apart.
    const tabs = new Set(Array.from({ length: 50 }, () => retryDelay(5)));

    assert.ok(tabs.size > 40, `${tabs.size} distinct delays out of 50`);

    for (const ms of tabs) assert.ok(ms >= 16_000 && ms <= 48_000, `${ms} ms`);
});

test('tells an outage (no answer, 502, 503, 504) from an app error', () => {
    for (const status of [0, null, undefined, 502, 503, 504, '503']) assert.equal(isOutage(status), true, String(status));
    for (const status of [500, 501, 505, 404, 419, 429, 200]) assert.equal(isOutage(status), false, String(status));
});

test('spreads the first upload of an outage\'s window over 30 to 120 s', () => {
    assert.equal(SPREAD_MIN_MS, 30_000);
    assert.equal(SPREAD_MAX_MS, 120_000);
    assert.equal(spreadDelay(() => 0), 30_000);
    assert.equal(spreadDelay(() => 1), 120_000);
    assert.equal(spreadDelay(() => 0.5), 75_000);

    for (let i = 0; i < 50; i++) {
        const ms = spreadDelay();

        assert.ok(ms >= 30_000 && ms <= 120_000, `${ms} ms`);
    }
});

test('reads Retry-After in seconds or as a date, at least a second, at most ten minutes, up to a fifth longer', () => {
    const now = Date.parse('2026-11-19T10:00:00Z');
    const exact = (header) => retryAfterDelay(header, now, () => 0);

    assert.equal(exact('90'), 90_000);
    assert.equal(exact(' 120 '), 120_000);
    assert.equal(exact('0'), 1_000);
    assert.equal(exact('86400'), RETRY_AFTER_MAX_MS, 'a day is capped');
    assert.equal(exact('Thu, 19 Nov 2026 10:02:00 GMT'), 120_000);
    assert.equal(exact('Thu, 19 Nov 2026 09:00:00 GMT'), 1_000, 'a date in the past');
    assert.equal(retryAfterDelay('100', now, () => 1), 120_000);
    assert.equal(retryAfterDelay('590', now, () => 1), RETRY_AFTER_MAX_MS, 'the jitter stays under the cap');

    for (const header of [null, undefined, '', 'soon', '-5', '1.5']) assert.equal(exact(header), null, String(header));
});

test('counts what is left of a spread or a pause, within its bound', () => {
    assert.equal(timeLeft(10_000, 4_000, SPREAD_MAX_MS), 6_000);
    assert.equal(timeLeft(1_000, 4_000, SPREAD_MAX_MS), 0, 'over');
    assert.equal(timeLeft(10_000_000, 0, SPREAD_MAX_MS), SPREAD_MAX_MS, 'a clock that went back');
    assert.equal(timeLeft(undefined, 4_000, SPREAD_MAX_MS), 0);
    assert.equal(timeLeft(0, 4_000, SPREAD_MAX_MS), 0);
});

test('keys a kept batch by recording and seq, so a split window keeps both halves', () => {
    assert.equal(pendingKey('tab-a', 0), 'tab-a:0');
    assert.notEqual(pendingKey('tab-a', 0), pendingKey('tab-a', 1));
});

test('sends what this tab kept for the same person', () => {
    assert.equal(pendingFate(record(), page), 'send');
    assert.equal(pendingFate(record({ identity: null }), { ...page, identity: null }), 'send', 'guests have no identity on either side');
    assert.equal(pendingFate(record({ identity: undefined }), { ...page, identity: null }), 'send');
});

test('leaves another tab\'s live window alone', () => {
    assert.equal(pendingFate(record({ session: 'tab-b' }), page), 'keep');
});

test('drops what is too old, in the future, kept for someone else, or not a record', () => {
    assert.equal(pendingFate(record({ savedAt: 160_000 - 1_800_001 }), page), 'drop', 'older than idleTimeout');
    assert.equal(pendingFate(record({ savedAt: 160_000 - 1_800_000 }), page), 'send', 'exactly idleTimeout is still fine');
    assert.equal(pendingFate(record({ savedAt: 300_000 }), page), 'drop', 'a clock that went backwards');
    assert.equal(pendingFate(record({ identity: 'p2' }), page), 'drop', 'another person, same tab');
    assert.equal(pendingFate(record({ session: 'tab-b', identity: 'p2' }), page), 'drop', 'another person, another tab');
    assert.equal(pendingFate(record({ identity: null }), page), 'drop', 'kept as a guest, signed in now');
    assert.equal(pendingFate(record(), { ...page, identity: null }), 'drop', 'kept signed in, a guest now');
    assert.equal(pendingFate(null, page), 'drop');
    assert.equal(pendingFate({ seq: 0 }, page), 'drop');
    assert.equal(pendingFate(record({ savedAt: '100000' }), page), 'drop');
});
