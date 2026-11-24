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
