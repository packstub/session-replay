import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';
import { hasHeader, observe, requestMarker } from '../lib/requests.js';

const page = 'https://app.test/orders?page=2';
const skip = ['https://app.test/session-replay/ingest'];

test('a same-origin request answered with a 5xx is a marker with method, path, status and duration', () => {
    const marker = requestMarker({ url: '/api/orders?status=open', method: 'post', status: 500, duration: 182.4, page, skip });

    assert.equal(marker.label, 'POST /api/orders failed (500)');
    assert.deepEqual(marker.payload, { method: 'POST', status: 500, url: 'https://app.test/api/orders?status=open', duration: 182 });
});

test('a request without an answer is a marker', () => {
    assert.equal(requestMarker({ url: 'https://app.test/api/orders', status: 0, page, skip }).label, 'GET /api/orders got no answer');
});

test('answers below the threshold are not markers; the threshold is configurable', () => {
    assert.equal(requestMarker({ url: '/api/orders', status: 404, page, skip }), null);
    assert.equal(requestMarker({ url: '/api/orders', status: 422, page, skip }), null);
    assert.equal(requestMarker({ url: '/api/orders', status: 422, threshold: 400, page, skip }).payload.status, 422);
});

test('other origins and the recorder\'s own uploads are never markers', () => {
    assert.equal(requestMarker({ url: 'https://analytics.test/collect', status: 0, page, skip }), null);
    assert.equal(requestMarker({ url: '/session-replay/ingest', status: 500, page, skip }), null);
    assert.equal(requestMarker({ url: '/session-replay/ingest/asset', status: 503, page, skip }), null);
});

test('an unparseable URL is not a marker', () => {
    assert.equal(requestMarker({ url: 'http://[bad', status: 500, page, skip }), null);
});

test('headers are found in a Headers-like object, a plain object or a list of pairs, in any case', () => {
    assert.equal(hasHeader({ 'x-livewire': '1' }, 'X-Livewire'), true);
    assert.equal(hasHeader([['X-Livewire', '1']], 'X-Livewire'), true);
    assert.equal(hasHeader({ has: (name) => name === 'X-Livewire' }, 'X-Livewire'), true);
    assert.equal(hasHeader({ Accept: 'application/json' }, 'X-Livewire'), false);
    assert.equal(hasHeader(undefined, 'X-Livewire'), false);
});

test('an opaque or opaque-redirect response is not a request without an answer', () => {
    assert.equal(requestMarker({ url: '/api/orders', status: 0, type: 'opaqueredirect', page, skip }), null);
    assert.equal(requestMarker({ url: '/api/orders', status: 0, type: 'opaque', page, skip }), null);
    assert.equal(requestMarker({ url: '/api/orders', status: 0, type: 'basic', page, skip }).label, 'GET /api/orders got no answer');
});

test('observe() reports how a request went and settles like the request itself', async () => {
    const seen = [];

    assert.equal(await observe(Promise.resolve('ok'), (value) => seen.push(value), () => seen.push('rejected')), 'ok');
    await assert.rejects(observe(Promise.reject(new Error('down')), () => seen.push('resolved'), (error) => seen.push(error.message)), /down/);
    assert.deepEqual(seen, ['ok', 'down']);

    // A callback that throws changes nothing for the app.
    assert.equal(await observe(Promise.resolve(1), () => { throw new Error('ours'); }, () => {}), 1);
});

test('a failure the app never catches still reaches unhandledrejection', () => {
    // In its own process: the test runner would take the rejection for a failure of this test.
    const script = `
        import { observe } from ${JSON.stringify(new URL('../lib/requests.js', import.meta.url).href)};
        process.on('unhandledRejection', (reason) => { console.log('unhandled:' + reason.message); process.exit(0); });
        observe(Promise.reject(new Error('uncaught')), () => {}, () => {});
        setTimeout(() => { console.log('handled'); process.exit(0); }, 50);
    `;

    assert.equal(execFileSync(process.execPath, ['--input-type=module', '-e', script], { encoding: 'utf8' }).trim(), 'unhandled:uncaught');
});
