/**
 * Assist for real, between two browsers on the workbench: Ada's page records and asks for help; Ana claims the
 * request on the Requests page and lands on the live stage. Ana sees the mirror change when Ada types and adds a
 * row, points at the heading (Ada sees her cursor there, nothing asked), puts the assist on hold and resumes it,
 * asks for control and, once Ada allows it, clicks a button on Ada's page; she draws on Ada's page, starts
 * audio (Ada allows the microphone), and ends the assist. WebRTC over loopback, a fake microphone.
 *
 * The visitor's panel lives in a closed shadow root; the test opens it (attachShadow forced to `open` before the
 * page runs) so Playwright can click the visitor's answers like a person would.
 */

import { expect, test } from '@playwright/test';

const openShadowRoots = () => {
    const attach = Element.prototype.attachShadow;

    Element.prototype.attachShadow = function (init) {
        return attach.call(this, { ...init, mode: 'open' });
    };
};

/** Pixels drawn on the visitor's annotation canvas (inside the panel's shadow root). */
const inkOnVisitor = (page) =>
    page.evaluate(() => {
        let painted = 0;

        for (const host of document.querySelectorAll('[data-replay-skip]')) {
            for (const canvas of host.shadowRoot?.querySelectorAll('canvas') ?? []) {
                if (!canvas.width || !canvas.height) continue;

                const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;

                for (let i = 3; i < pixels.length; i += 4) if (pixels[i] > 0) painted++;
            }
        }

        return painted;
    });

/** The agent's cursor on the visitor's page (inside the panel's shadow root): shown or not, whose name, where, and whether the control frame is up. */
const cursorOnVisitor = (page) =>
    page.evaluate(() => {
        for (const host of document.querySelectorAll('[data-replay-skip]')) {
            const cursor = host.shadowRoot?.querySelector('.cursor');

            if (!cursor) continue;

            const [, x, y] = /translate\((-?\d+)px, (-?\d+)px\)/.exec(cursor.style.transform) ?? [];

            return { shown: !cursor.hidden, name: cursor.querySelector('span')?.textContent ?? '', x: Number(x), y: Number(y), frame: !host.shadowRoot.querySelector('.outline').hidden };
        }

        return null;
    });

const stageState = (page) => page.evaluate(() => document.querySelector('[data-session-replay-stage]')?.sessionReplayStage?.state ?? null);

test('an agent claims a request and helps on the live stage: mirror, control, annotation, audio', async ({ browser }) => {
    const visitorContext = await browser.newContext();
    const agentContext = await browser.newContext();

    await visitorContext.addInitScript(openShadowRoots);

    const visitor = await visitorContext.newPage();
    const agent = await agentContext.newPage();

    const consoleErrors = [];

    agent.on('pageerror', (error) => consoleErrors.push(`agent: ${error.message}`));
    visitor.on('pageerror', (error) => consoleErrors.push(`visitor: ${error.message}`));

    // What the agent's browser was made to fetch from a host the visitor's page chose: a request the CSP stopped
    // fails with "csp" before it leaves the browser; anything else went out (or tried to: the host does not resolve).
    const leaks = [];
    const leaked = (request, how) => request.url().includes('leak.invalid') && how !== 'csp' && leaks.push(`${request.url()} (${how})`);

    agent.on('requestfinished', (request) => leaked(request, 'finished'));
    agent.on('requestfailed', (request) => leaked(request, request.failure()?.errorText));

    // ---- Ada's page records, then asks for help -------------------------------------------------

    const firstUpload = visitor.waitForResponse((response) => response.url().includes('/session-replay/ingest') && response.status() === 201, { timeout: 30_000 });

    await visitor.goto('/');
    await firstUpload;

    await visitor.evaluate(() => window.SessionReplay.assist.request({ message: 'The add button does nothing' }));
    await expect(visitor.getByText('Waiting for someone from support')).toBeVisible();

    // ---- Ana claims it and lands on the stage -----------------------------------------------------

    await agent.goto('/agent');

    const row = agent.getByRole('row', { name: /The add button does nothing/ });

    await row.getByRole('button', { name: 'Claim' }).click();
    await expect(agent).toHaveURL(/\/session-replay\/assist\/requests\/[0-9a-f-]+\/stage$/);

    const status = agent.locator('[data-ref="status"]');

    await expect(status).toHaveText(/^Live:/, { timeout: 60_000 });
    await expect(visitor.getByText(/sees this page/)).toBeVisible();

    // ---- the mirror follows Ada's page ------------------------------------------------------------

    const mirror = agent.frameLocator('[data-ref="mirror"] iframe');

    await expect(mirror.locator('h1')).toHaveText('Workbench');

    await visitor.locator('#name').pressSequentially('hello');
    // size.sampling.input "last": the value is recorded when the field changes (on leaving it), live as stored.
    await visitor.locator('#name').press('Tab');
    // Masked in the mirror as in the recording: the agent sees that something was typed, never what.
    await expect.poll(() => mirror.locator('#name').inputValue()).toMatch(/^\*{5}$/);

    await visitor.locator('#add').click();
    await expect(mirror.locator('#list li')).toHaveCount(1);

    // An image the visitor's page adds from a host of its choosing reaches the mirror, but the stage's CSP keeps the
    // agent's browser from fetching it (and so from telling that host the agent's address).
    await visitor.evaluate(() => document.body.append(Object.assign(document.createElement('img'), { id: 'leak', src: 'http://leak.invalid/pixel.png', alt: '' })));
    await expect(mirror.locator('#leak')).toHaveCount(1);
    // A preload has no directive of the stage's policy to stop it: the mirror takes its rel off.
    await visitor.evaluate(() => document.body.append(Object.assign(document.createElement('link'), { id: 'leak-link', rel: 'preload', as: 'fetch', href: 'http://leak.invalid/data.json' })));
    await expect(mirror.locator('#leak-link')).toHaveCount(1);
    expect(await mirror.locator('#leak-link').getAttribute('rel')).toBeNull();
    await agent.waitForTimeout(500);
    expect(leaks).toEqual([]);

    // ---- the pointer: Ana only watches, and Ada sees where she points ------------------------------

    const title = await mirror.locator('h1').boundingBox();
    const titleOnPage = await visitor.locator('h1').boundingBox();

    await agent.mouse.move(title.x + title.width / 4, title.y + title.height / 2);
    await agent.mouse.move(title.x + title.width / 2, title.y + title.height / 2, { steps: 5 });

    await expect.poll(async () => (await cursorOnVisitor(visitor))?.shown, { timeout: 5_000 }).toBe(true);
    // The place she stopped at, on Ada's own page: the middle of the heading, give or take the mirror's scale.
    await expect.poll(async () => Math.abs((await cursorOnVisitor(visitor)).x - (titleOnPage.x + titleOnPage.width / 2))).toBeLessThan(12);

    const pointed = await cursorOnVisitor(visitor);

    expect(pointed.name).not.toBe('');
    expect(Math.abs(pointed.y - (titleOnPage.y + titleOnPage.height / 2))).toBeLessThan(12);
    // No question was asked and no control frame is up: nothing on Ada's page can be changed.
    expect(pointed.frame).toBe(false);
    await expect(visitor.getByText(/would like to/)).toHaveCount(0);
    expect((await stageState(agent)).control).toBe('off');
    // The cursor is part of the panel the recorder skips: it never comes back in the mirror.
    await expect(mirror.locator('[data-session-replay-assist]')).toHaveCount(0);

    // Off the page: gone on Ada's side too.
    await agent.mouse.move(2, 2);
    await expect.poll(async () => (await cursorOnVisitor(visitor)).shown, { timeout: 5_000 }).toBe(false);

    // Turned off, it stays off.
    await agent.getByRole('button', { name: 'Show my pointer' }).click();
    await expect(agent.getByRole('button', { name: 'Show my pointer' })).toHaveAttribute('aria-pressed', 'false');
    await agent.mouse.move(title.x + title.width / 2, title.y + title.height / 2, { steps: 5 });
    await agent.waitForTimeout(500);
    expect((await cursorOnVisitor(visitor)).shown).toBe(false);
    await agent.getByRole('button', { name: 'Show my pointer' }).click();

    // ---- on hold and back: Ada's page waits, the same call goes on ---------------------------------

    await agent.getByRole('button', { name: 'Put on hold' }).click();
    await expect(status).toHaveText(/^On hold/);
    await expect(visitor.getByText(/put this session on hold/)).toBeVisible({ timeout: 15_000 });
    expect((await stageState(agent)).phase).toBe('on_hold');

    await agent.getByRole('button', { name: 'Resume' }).click();
    await expect(status).toHaveText(/^Live:/, { timeout: 60_000 });
    await expect(visitor.getByText(/sees this page/)).toBeVisible({ timeout: 15_000 });
    await expect(mirror.locator('h1')).toHaveText('Workbench');
    await expect(mirror.locator('#list li')).toHaveCount(1);

    // ---- control: asked, allowed, a click on Ada's page -------------------------------------------

    await agent.getByRole('button', { name: 'Request control' }).click();
    await expect(agent.getByRole('button', { name: 'Waiting for the visitor…' })).toBeDisabled();
    await expect(visitor.getByText(/would like to control this page/)).toBeVisible();
    await visitor.getByRole('button', { name: 'Allow', exact: true }).click();

    await expect(agent.getByRole('button', { name: 'Release control' })).toBeVisible();
    await expect(agent.locator('[data-ref="screen"]')).toHaveClass(/is-controlling/);
    await expect(visitor.getByText(/is controlling this page/)).toBeVisible();
    // One cursor: with control it is the control cursor, inside the dashed frame.
    await expect.poll(async () => (await cursorOnVisitor(visitor)).frame).toBe(true);
    expect((await cursorOnVisitor(visitor)).shown).toBe(true);
    expect(await visitor.evaluate(() => [...document.querySelectorAll('[data-replay-skip]')].reduce((count, host) => count + (host.shadowRoot?.querySelectorAll('.cursor').length ?? 0), 0))).toBe(1);

    const add = await mirror.locator('#add').boundingBox();

    await agent.mouse.click(add.x + add.width / 2, add.y + add.height / 2);

    // The click happened on Ada's page, and the mirror shows its result.
    await expect(visitor.locator('#list li')).toHaveCount(2);
    await expect(mirror.locator('#list li')).toHaveCount(2);

    // ---- control goes on to the next page (assist.control_across_pages) ---------------------------

    const nav = await mirror.locator('#nav').boundingBox();

    await agent.mouse.click(nav.x + nav.width / 2, nav.y + nav.height / 2);
    await expect(visitor).toHaveURL(/\/second$/);

    // No new question: Ada is reminded, Ana keeps control and the stage says it went on.
    await expect(visitor.getByText(/still controls this page/)).toBeVisible();
    await expect(mirror.locator('h1')).toHaveText('Second page', { timeout: 30_000 });
    await expect(agent.getByText('Control went on to the visitor’s next page.')).toBeVisible({ timeout: 30_000 });
    await expect(agent.getByRole('button', { name: 'Release control' })).toBeVisible();
    await expect(visitor.getByText(/would like to control this page/)).toBeHidden();
    await expect.poll(async () => (await cursorOnVisitor(visitor))?.frame).toBe(true);

    // And acts there.
    const addHere = await mirror.locator('#add').boundingBox();

    await agent.mouse.click(addHere.x + addHere.width / 2, addHere.y + addHere.height / 2);
    await expect(visitor.locator('#list li')).toHaveCount(1);
    await expect(mirror.locator('#list li')).toHaveCount(1);

    // ---- an annotation drawn over Ada's page -------------------------------------------------------

    await agent.getByRole('button', { name: 'Draw' }).click();
    await expect(agent.getByRole('button', { name: 'Draw' })).toHaveAttribute('aria-pressed', 'true');

    const heading = await mirror.locator('h1').boundingBox();

    await agent.mouse.move(heading.x + 5, heading.y + heading.height / 2);
    await agent.mouse.down();

    for (let step = 1; step <= 10; step++) await agent.mouse.move(heading.x + 5 + step * 15, heading.y + heading.height / 2 + step * 2);

    await agent.mouse.up();
    await expect.poll(() => inkOnVisitor(visitor), { timeout: 5_000 }).toBeGreaterThan(50);

    await agent.getByRole('button', { name: 'Draw' }).click();
    await agent.getByRole('button', { name: 'Release control' }).click();
    await expect(visitor.getByText(/is controlling this page/)).toBeHidden();

    // ---- audio: Ada allows the microphone, both sides talk ------------------------------------------

    await agent.getByRole('button', { name: 'Start audio' }).click();
    await expect(visitor.getByText(/would like to talk with you/)).toBeVisible();
    await visitor.getByRole('button', { name: 'Allow', exact: true }).click();

    await expect(agent.getByRole('button', { name: 'End audio' })).toBeVisible();
    await expect
        .poll(() => agent.evaluate(() => (document.querySelector('[data-ref="audio"]').srcObject?.getAudioTracks() ?? []).map((track) => track.readyState).join(',')), { timeout: 30_000 })
        .toContain('live');
    await expect.poll(async () => (await stageState(agent))?.audio).toBe('on');
    // And Ada hears Ana: the agent's microphone reaches the visitor's panel, not muted.
    await expect
        .poll(
            () =>
                visitor.evaluate(() => {
                    for (const host of document.querySelectorAll('[data-replay-skip]')) {
                        for (const media of host.shadowRoot?.querySelectorAll('audio, video') ?? []) {
                            const live = (media.srcObject?.getAudioTracks() ?? []).some((track) => track.readyState === 'live');

                            if (live && !media.muted) return true;
                        }
                    }

                    return false;
                }),
            { timeout: 30_000 },
        )
        .toBe(true);


    // The grants log on the server shows what was asked and what was allowed.
    const requestId = new URL(agent.url()).pathname.split('/').at(-2);
    const grants = await agent.evaluate(async (id) => (await (await fetch(`/session-replay/assist/requests/${id}/status`, { headers: { Accept: 'application/json' } })).json()).grants, requestId);

    expect(grants.filter((entry) => entry.by === 'agent').map((entry) => entry.kind)).toEqual(expect.arrayContaining(['control', 'audio']));
    expect(grants.filter((entry) => entry.by === 'visitor' && entry.granted).map((entry) => entry.kind)).toEqual(expect.arrayContaining(['control', 'audio']));
    // One control period across both pages: allowed once, ended once.
    expect(grants.filter((entry) => entry.by === 'visitor' && entry.kind === 'control').map((entry) => entry.granted)).toEqual([true, false]);

    // ---- the end -------------------------------------------------------------------------------------

    await agent.getByRole('button', { name: 'End assist' }).click();
    await expect(status).toHaveText(/This assist is over/);
    await expect(visitor.getByText('The help session has ended.')).toBeVisible({ timeout: 30_000 });

    expect(consoleErrors).toEqual([]);

    await visitorContext.close();
    await agentContext.close();
});

test('an agent who leaves the live view for longer than Holler waits comes back to the same request on a new call', async ({ browser }) => {
    const visitorContext = await browser.newContext();
    const agentContext = await browser.newContext();

    await visitorContext.addInitScript(openShadowRoots);

    const visitor = await visitorContext.newPage();
    const agent = await agentContext.newPage();
    const consoleErrors = [];

    agent.on('pageerror', (error) => consoleErrors.push(`agent: ${error.message}`));
    visitor.on('pageerror', (error) => consoleErrors.push(`visitor: ${error.message}`));

    const firstUpload = visitor.waitForResponse((response) => response.url().includes('/session-replay/ingest') && response.status() === 201, { timeout: 30_000 });

    await visitor.goto('/');
    await firstUpload;
    await visitor.evaluate(() => window.SessionReplay.assist.request({ message: 'I will wait for you' }));

    await agent.goto('/agent');
    await agent.getByRole('row', { name: /I will wait for you/ }).getByRole('button', { name: 'Claim' }).click();
    await expect(agent).toHaveURL(/\/stage$/);

    const stageUrl = agent.url();
    const status = agent.locator('[data-ref="status"]');

    await expect(status).toHaveText(/^Live:/, { timeout: 60_000 });
    await expect(visitor.getByText(/sees this page/)).toBeVisible();

    const callOf = async () => (await agent.evaluate(async (url) => (await (await fetch(url.replace(/\/stage$/, '/status'), { headers: { Accept: 'application/json' } })).json()).call, stageUrl));
    const firstCall = await callOf();

    // Ana leaves the live view for longer than Holler waits for a side (HOLLER_RECONNECT_TIMEOUT=6 in this run).
    await agent.goto('/agent');
    await expect(visitor.getByText(/stepped away and is reconnecting/)).toBeVisible({ timeout: 30_000 });
    await agent.waitForTimeout(3_000);
    await expect(visitor.getByText(/stepped away and is reconnecting/)).toBeVisible();

    // She comes back: the request is still hers, and both are put on a new call.
    await agent.goto(stageUrl);
    await expect(status).toHaveText(/^Live:/, { timeout: 60_000 });
    await expect(visitor.getByText(/sees this page/)).toBeVisible();

    const mirror = agent.frameLocator('[data-ref="mirror"] iframe');

    await visitor.locator('#add').click();
    await expect(mirror.locator('#list li')).toHaveCount(1);
    expect(await callOf()).not.toBe(firstCall);

    await agent.getByRole('button', { name: 'End assist' }).click();
    await expect(visitor.getByText('The help session has ended.')).toBeVisible({ timeout: 30_000 });

    expect(consoleErrors).toEqual([]);

    await visitorContext.close();
    await agentContext.close();
});
