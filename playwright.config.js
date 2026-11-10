// End-to-end: Assist for real between two browsers on the workbench (`composer serve`'s app): a visitor whose
// page records and asks for help, and an agent who claims the request and works on the live stage. Headless
// Chromium with a fake microphone and camera; WebRTC over loopback. `npm run test:e2e` (the server is started
// here, with packstub/calls installed from the sibling checkout as AGENTS.md describes).

import { defineConfig, devices } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.ASSIST_E2E_PORT || 8791);
const BASE_URL = `http://127.0.0.1:${PORT}`;

/** Without a file for the fake microphone, Chrome on macOS asks for the real one and getUserMedia hangs: one second of silence. */
function silentWav() {
    const path = join(tmpdir(), 'session-replay-e2e-mic.wav');
    const sampleRate = 16000;
    const data = Buffer.alloc(sampleRate * 2);
    const header = Buffer.alloc(44);

    header.write('RIFF', 0);
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(1, 22);
    header.writeUInt32LE(sampleRate, 24);
    header.writeUInt32LE(sampleRate * 2, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36);
    header.writeUInt32LE(data.length, 40);
    writeFileSync(path, Buffer.concat([header, data]));

    return path;
}

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 120_000,
    expect: { timeout: 20_000 },
    // One worker: the tests share the workbench database.
    workers: 1,
    retries: 0,
    reporter: process.env.CI ? 'github' : 'list',
    outputDir: 'test-results',

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        permissions: ['camera', 'microphone'],
        launchOptions: {
            args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${silentWav()}`, '--autoplay-policy=no-user-gesture-required'],
        },
    },

    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

    webServer: {
        // Several PHP workers: the visitor's page and the agent's stage poll at the same time. Calls drops a side
        // after 6 s instead of 15, so the test of an agent leaving the live view and coming back is quick.
        command: `php vendor/bin/testbench workbench:build && CALLS_RECONNECT_TIMEOUT=6 PHP_CLI_SERVER_WORKERS=8 php vendor/bin/testbench serve --port=${PORT}`,
        url: `${BASE_URL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
