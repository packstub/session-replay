import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LEAD_IN_MS, momentUrl, startOffset } from '../lib/moment.js';

test('the link carries the whole second the replay is at', () => {
    assert.equal(momentUrl('https://app.test/session-replay/abc', 83_900), 'https://app.test/session-replay/abc?t=83');
});

test('other query parameters stay, an earlier t is replaced and the fragment goes', () => {
    assert.equal(momentUrl('https://app.test/admin/session-replays/abc?tab=pages&t=10#markers', 5_000), 'https://app.test/admin/session-replays/abc?tab=pages&t=5');
});

test('the start of the replay needs no t', () => {
    assert.equal(momentUrl('https://app.test/session-replay/abc?t=12', 400), 'https://app.test/session-replay/abc');
    assert.equal(momentUrl('https://app.test/session-replay/abc', Number.NaN), 'https://app.test/session-replay/abc');
});

test('a copied link drops the at= it was opened with', () => {
    assert.equal(momentUrl('https://app.test/session-replay/abc?at=1791391475672', 9_000), 'https://app.test/session-replay/abc?t=9');
});

test('the replay opens at ?t=, else a moment before ?at=, else at the start', () => {
    const startedAt = 1_791_391_400_000;

    assert.equal(startOffset('?t=83', startedAt), 83_000);
    assert.equal(startOffset(`?at=${startedAt + 60_000}`, startedAt), 60_000 - LEAD_IN_MS);
    assert.equal(startOffset(`?t=5&at=${startedAt + 60_000}`, startedAt), 5_000);
    assert.equal(startOffset(`?at=${startedAt + 1_000}`, startedAt), 0);
    assert.equal(startOffset('?at=yesterday', startedAt), 0);
    assert.equal(startOffset('', startedAt), 0);
});
