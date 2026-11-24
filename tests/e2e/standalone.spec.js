// The npm build on a page of another origin (workbench/standalone, the lab): the token comes from the workbench
// with its session cookie, the uploads go there across origins, and the recording is Ada's.
import { expect, test } from '@playwright/test';

const LAB = `http://127.0.0.1:${Number(process.env.STANDALONE_E2E_PORT || 8792)}`;

test('a frontend on another origin records the signed-in person', async ({ page, baseURL }) => {
    const corsErrors = [];

    page.on('console', (message) => {
        if (message.type() === 'error' && /CORS|Access-Control/i.test(message.text())) corsErrors.push(message.text());
    });

    // Signs Ada in on the workbench.
    await page.goto(`${baseURL}/`);

    const uploaded = page.waitForResponse((response) => response.url() === `${baseURL}/session-replay/ingest` && response.status() === 201);

    await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
    await expect(page.locator('#state')).toContainText('"isRecording": true');
    await expect(page.locator('#state')).toContainText('X-Session-Replay');

    await page.fill('input[name="note"]', 'not in the replay');
    await page.getByRole('button', { name: 'Mark a moment' }).click();
    await page.getByRole('button', { name: 'Go to another route' }).click();

    const response = await uploaded;

    expect(response.headers()['access-control-allow-origin']).toBe(LAB);
    expect(response.headers()['access-control-allow-credentials']).toBeUndefined();

    const id = JSON.parse(await page.locator('#state').textContent()).sessionId;

    // A batch with the marker: the page read the first answer (CORS), so it keeps uploading.
    await page.getByRole('button', { name: 'Report a problem' }).click();
    await expect.poll(async () => (await page.request.get(`${baseURL}/session-replay/${id}/manifest`)).status()).toBe(200);

    const manifest = await (await page.request.get(`${baseURL}/session-replay/${id}/manifest`)).json();

    expect(JSON.stringify(manifest)).toContain('Lab button');
    expect(corsErrors).toEqual([]);
});

/** Signs Ada in, then opens the lab with the first upload answered 401, as the server answers an expired token. */
async function openWithExpiredToken(page, baseURL) {
    const seen = { tokens: 0, refused: 0 };

    await page.goto(`${baseURL}/`);

    page.on('request', (request) => {
        if (request.method() === 'GET' && request.url() === `${baseURL}/session-replay/token`) seen.tokens++;
    });

    await page.route(`${baseURL}/session-replay/ingest`, async (route) => {
        if (route.request().method() !== 'POST' || seen.refused > 0) return route.continue();

        seen.refused++;

        await route.fulfill({
            status: 401,
            contentType: 'application/json',
            headers: { 'Access-Control-Allow-Origin': LAB, Vary: 'Origin' },
            body: JSON.stringify({ ok: false, message: 'The recording token is missing, invalid or expired.', stop: true }),
        });
    });

    return seen;
}

test('an upload refused with 401 fetches one fresh token and goes again', async ({ page, baseURL }) => {
    const seen = await openWithExpiredToken(page, baseURL);
    const uploaded = page.waitForResponse((response) => response.url() === `${baseURL}/session-replay/ingest` && response.status() === 201);

    await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
    await expect(page.locator('#state')).toContainText('"isRecording": true');

    const id = JSON.parse(await page.locator('#state').textContent()).sessionId;

    await uploaded;

    // start()'s token, and one more for the refused batch: the same recording carries on with it.
    expect(seen).toEqual({ tokens: 2, refused: 1 });

    await page.getByRole('button', { name: 'Mark a moment' }).click();
    await expect(page.locator('#state')).toContainText('"isRecording": true');
    expect(JSON.parse(await page.locator('#state').textContent()).sessionId).toBe(id);

    // The batch that was refused is in the recording: its first page is there to watch.
    await expect.poll(async () => (await page.request.get(`${baseURL}/session-replay/${id}/manifest`)).status()).toBe(200);

    // Later batches ask for no token of their own.
    await page.getByRole('button', { name: 'Report a problem' }).click();
    await expect.poll(async () => JSON.stringify(await (await page.request.get(`${baseURL}/session-replay/${id}/manifest`)).json())).toContain('Reported from the standalone lab');
    expect(seen.tokens).toBe(2);
});

test('an upload refused with 401 stops the recording when no fresh token is to be had', async ({ page, baseURL }) => {
    const seen = await openWithExpiredToken(page, baseURL);
    let answered = 0;

    // The person signed out since: the second token request is answered as a sign-in guard would.
    await page.route(`${baseURL}/session-replay/token`, async (route) => {
        if (route.request().method() !== 'GET' || ++answered === 1) return route.continue();

        await route.fulfill({
            status: 401,
            contentType: 'application/json',
            headers: { 'Access-Control-Allow-Origin': LAB, 'Access-Control-Allow-Credentials': 'true', Vary: 'Origin' },
            body: JSON.stringify({ message: 'Unauthenticated.' }),
        });
    });

    await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
    await expect(page.locator('#state')).toContainText('"isRecording": true');

    await expect.poll(() => seen.tokens).toBe(2);
    await expect.poll(async () => {
        await page.getByRole('button', { name: 'Mark a moment' }).click();

        return JSON.parse(await page.locator('#state').textContent()).isRecording;
    }).toBe(false);

    // No loop: the stopped recorder asks for nothing more.
    await page.waitForTimeout(1500);
    expect(seen).toEqual({ tokens: 2, refused: 1 });
    expect(JSON.parse(await page.locator('#state').textContent()).header).toEqual({});
});

test('the token is renewed before it expires, and the upload of the closing page goes up with the fresh one', async ({ page, baseURL }) => {
    const tokens = [];
    const uploads = [];
    const refused = [];

    await page.goto(`${baseURL}/`);

    // The workbench's token lives for days: here the app says four seconds, so the package renews after two.
    await page.route(`${baseURL}/session-replay/token`, async (route) => {
        if (route.request().method() !== 'GET') return route.continue();

        const response = await route.fetch();
        const body = await response.json();

        tokens.push(body.config.token);

        await route.fulfill({ response, json: { ...body, expires_in: 4, expires_at: new Date(Date.now() + 4000).toISOString() } });
    });

    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url() === `${baseURL}/session-replay/ingest`) uploads.push(request);
    });
    page.on('response', (response) => {
        if (response.url() === `${baseURL}/session-replay/ingest` && response.status() >= 400) refused.push(response.status());
    });

    await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
    await expect(page.locator('#state')).toContainText('"isRecording": true');

    const id = JSON.parse(await page.locator('#state').textContent()).sessionId;

    // No upload was refused: the second token was asked for by the clock alone.
    await expect.poll(() => tokens.length, { timeout: 10_000 }).toBeGreaterThanOrEqual(2);
    expect(new Set(tokens).size).toBe(tokens.length);

    // The same recording goes on.
    await page.getByRole('button', { name: 'Mark a moment' }).click();
    expect(JSON.parse(await page.locator('#state').textContent()).sessionId).toBe(id);

    const before = uploads.length;
    const renewed = tokens.length;

    // The page goes away (the event alone: a request of a page that is really gone is not shown to the test): what
    // is left goes up once, with a token from a renewal, not the one start() got.
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    await expect.poll(() => uploads.length).toBeGreaterThan(before);

    const last = uploads.at(-1).postDataBuffer().toString('latin1');

    expect(last).toContain('Lab button');
    expect(last).not.toContain(tokens[0]);
    expect(tokens.slice(1, renewed + 1).some((token) => last.includes(token))).toBe(true);

    expect(refused).toEqual([]);

    // And it is in the recording.
    await expect.poll(async () => JSON.stringify(await (await page.request.get(`${baseURL}/session-replay/${id}/manifest`)).json())).toContain('Lab button');
});

test('stop() ends the renewals', async ({ page, baseURL }) => {
    let asked = 0;

    await page.goto(`${baseURL}/`);
    await page.route(`${baseURL}/session-replay/token`, async (route) => {
        if (route.request().method() !== 'GET') return route.continue();

        asked++;

        const response = await route.fetch();

        await route.fulfill({ response, json: { ...(await response.json()), expires_in: 4 } });
    });

    await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
    await expect(page.locator('#state')).toContainText('"isRecording": true');

    await page.getByRole('button', { name: 'stop()' }).click();
    await expect(page.locator('#state')).toContainText('"isRecording": false');

    await page.waitForTimeout(4000);
    expect(asked).toBe(1);
});

for (const [name, answer] of [
    ['signed out', (route) => route.fulfill({
        status: 401,
        contentType: 'application/json',
        headers: { 'Access-Control-Allow-Origin': LAB, 'Access-Control-Allow-Credentials': 'true', Vary: 'Origin' },
        body: JSON.stringify({ message: 'Unauthenticated.' }),
    })],
    ['someone else signed in', async (route) => {
        const response = await route.fetch();
        const body = await response.json();

        await route.fulfill({ response, json: { ...body, expires_in: 4, config: { ...body.config, identity: 'someone-else' } } });
    }],
]) {
    test(`a renewal that finds the person ${name} ends the recording, and consent(true) does not bring it back`, async ({ page, baseURL }) => {
        let asked = 0;

        await page.goto(`${baseURL}/`);
        await page.route(`${baseURL}/session-replay/token`, async (route) => {
            if (route.request().method() !== 'GET') return route.continue();

            if (++asked > 1) return answer(route);

            const response = await route.fetch();

            await route.fulfill({ response, json: { ...(await response.json()), expires_in: 4 } });
        });

        await page.goto(`${LAB}/?app=${encodeURIComponent(baseURL)}`);
        await expect(page.locator('#state')).toContainText('"isRecording": true');

        // Renewed after two seconds by the clock alone, no upload refused: the recording stops there, not days later.
        await expect.poll(async () => {
            await page.getByRole('button', { name: 'Mark a moment' }).click();

            return JSON.parse(await page.locator('#state').textContent()).isRecording;
        }, { timeout: 10_000 }).toBe(false);
        expect(asked).toBe(2);

        await page.getByRole('button', { name: 'consent(true)' }).click();
        expect(JSON.parse(await page.locator('#state').textContent()).isRecording).toBe(false);

        // No renewals after it either.
        await page.waitForTimeout(3000);
        expect(asked).toBe(2);
    });
}
