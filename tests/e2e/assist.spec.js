/**
 * Assist for real, between two browsers on the workbench: Ada's page records and asks for help; Ana claims the
 * request on the Requests page and lands on the live stage. Ana sees the mirror change when Ada types and adds a
 * row, asks for control and, once Ada allows it, clicks a button on Ada's page; she draws on Ada's page, starts
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

    // ---- control: asked, allowed, a click on Ada's page -------------------------------------------

    await agent.getByRole('button', { name: 'Request control' }).click();
    await expect(agent.getByRole('button', { name: 'Waiting for the visitor…' })).toBeDisabled();
    await expect(visitor.getByText(/would like to control this page/)).toBeVisible();
    await visitor.getByRole('button', { name: 'Allow', exact: true }).click();

    await expect(agent.getByRole('button', { name: 'Release control' })).toBeVisible();
    await expect(agent.locator('[data-ref="screen"]')).toHaveClass(/is-controlling/);
    await expect(visitor.getByText(/is controlling this page/)).toBeVisible();

    const add = await mirror.locator('#add').boundingBox();

    await agent.mouse.click(add.x + add.width / 2, add.y + add.height / 2);

    // The click happened on Ada's page, and the mirror shows its result.
    await expect(visitor.locator('#list li')).toHaveCount(2);
    await expect(mirror.locator('#list li')).toHaveCount(2);

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

    // ---- the end -------------------------------------------------------------------------------------

    await agent.getByRole('button', { name: 'End assist' }).click();
    await expect(status).toHaveText(/This assist is over/);
    await expect(visitor.getByText('The help session has ended.')).toBeVisible({ timeout: 30_000 });

    expect(consoleErrors).toEqual([]);

    await visitorContext.close();
    await agentContext.close();
});
