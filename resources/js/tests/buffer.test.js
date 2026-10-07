import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activeTime, checkoutInterval, estimateChars, isTrigger, trimWindow, windowStart } from '../lib/buffer.js';

const meta = (timestamp) => ({ type: 4, timestamp, data: { href: 'https://app.test/' } });
const snapshot = (timestamp) => ({ type: 2, timestamp, data: { node: {} } });
const move = (timestamp) => ({ type: 3, timestamp, data: { source: 1 } });
const mutation = (timestamp) => ({ type: 3, timestamp, data: { source: 0 } });

// Snapshots at 0, 30 s and 60 s, as the recorder takes them while buffering; a hashed snapshot can land after later events.
const buffer = () => [meta(0), snapshot(5), move(1_000), mutation(20_000), meta(30_000), move(30_010), snapshot(30_004), move(45_000), meta(60_000), snapshot(60_003), move(61_000)];

test('takes a snapshot every half window, never more often than 5 s or less often than 30 s', () => {
    assert.equal(checkoutInterval(60_000), 30_000);
    assert.equal(checkoutInterval(20_000), 10_000);
    assert.equal(checkoutInterval(4_000), 5_000);
    assert.equal(checkoutInterval(600_000), 30_000);
});

test('starts the window at the meta event of the newest snapshot at or before the cutoff', () => {
    assert.equal(windowStart(buffer(), 61_000 - 60_000), 0);
    assert.equal(windowStart(buffer(), 31_000), 30_000);
    assert.equal(windowStart(buffer(), 30_002), 0, 'a snapshot finished after the cutoff does not count');
    assert.equal(windowStart(buffer(), Infinity), 60_000);
});

test('keeps everything when no snapshot is old enough', () => {
    assert.equal(windowStart([move(1), move(2)], 10), null);
    assert.equal(windowStart(buffer(), -1), null);

    const events = [move(1)];
    const markers = [{ type: 'error', at: 1 }];

    assert.deepEqual(trimWindow(events, markers, null), { events, markers });
});

test('drops what came before the window, events and markers, and keeps the order', () => {
    const markers = [
        { type: 'navigation', label: '/orders', at: 0 },
        { type: 'console', label: 'old', at: 20_000 },
        { type: 'navigation', label: '/orders/7', at: 25_000 },
        { type: 'error', label: 'Boom', at: 45_000 },
    ];

    const { events, markers: kept } = trimWindow(buffer(), markers, 30_000);

    assert.deepEqual(
        events.map((event) => event.timestamp),
        [30_000, 30_010, 30_004, 45_000, 60_000, 60_003, 61_000],
    );
    assert.deepEqual(kept, [
        { type: 'navigation', label: '/orders/7', at: 30_000 },
        { type: 'error', label: 'Boom', at: 45_000 },
    ]);
});

test('triggers only on the configured types, and never on page views or vitals', () => {
    assert.ok(isTrigger('error', ['error', 'request']));
    assert.ok(isTrigger('request', ['error', 'request']));
    assert.ok(!isTrigger('console', ['error', 'request']));
    assert.ok(isTrigger('custom', ['custom']));
    assert.ok(!isTrigger('navigation', ['navigation']));
    assert.ok(!isTrigger('vital', ['vital']));
    assert.ok(!isTrigger('error', null));
});

test('estimates the size like the recorder does and counts active time', () => {
    assert.equal(estimateChars([snapshot(1), move(2), move(3)]), 200_600);
    assert.equal(activeTime([move(1_000), move(2_000), mutation(2_500), move(9_000), move(9_500)]), 1_500);
});
