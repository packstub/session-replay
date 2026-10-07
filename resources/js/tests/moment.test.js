import assert from 'node:assert/strict';
import { test } from 'node:test';
import { momentUrl } from '../lib/moment.js';

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
