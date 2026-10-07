import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_RETRIES, pendingFate, pendingKey, retryDelay } from '../lib/pending.js';

const record = (overrides = {}) => ({ session: 'tab-a', seq: 0, identity: 'p1', savedAt: 100_000, ...overrides });
const page = { sessionId: 'tab-a', identity: 'p1', now: 160_000, idleTimeout: 1_800_000 };

test('retries for about four minutes: doubling from 2 s, then once a minute', () => {
    assert.deepEqual([1, 2, 3, 4, 5, 6, 7, 8].map(retryDelay), [2_000, 4_000, 8_000, 16_000, 32_000, 60_000, 60_000, 60_000]);
    assert.equal(retryDelay(0), 2_000);

    const total = Array.from({ length: MAX_RETRIES }, (_, i) => retryDelay(i + 1)).reduce((sum, ms) => sum + ms, 0);

    assert.ok(total >= 180_000 && total <= 300_000, `${total} ms in all`);
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
