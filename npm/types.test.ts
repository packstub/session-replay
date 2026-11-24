// What a TypeScript frontend writes against index.d.ts, checked by `npm run test:types` (tsc, nothing is run).
// Not published: package.json "files" lists the declarations only.
import { consent, header, isRecording, mark, send, sessionId, start, stop, type StartOptions } from '@packstub/session-replay';

const options: StartOptions = {
    endpoint: 'https://api.example.com/session-replay/token',
    credentials: 'omit',
    headers: { Authorization: 'Bearer token' },
    fetch: window.fetch.bind(window),
    global: true,
};

const started: Promise<boolean> = start(options);
// Again after a sign-in: the options of the first call carry over.
const again: Promise<boolean> = start();
const marked: boolean = mark('Checkout started', { cart: 3 });
const sent: Promise<boolean> = send('Wrong total', { invoice: 12 });
const agreed: boolean = consent(true);
const id: string | null = sessionId();
const recording: boolean = isRecording();
const headers: Record<string, string> = { Accept: 'application/json', ...header() };

stop();

// @ts-expect-error the first start() needs the endpoint
const missing: StartOptions = { credentials: 'include' };
// @ts-expect-error header() carries one header and no other
header()['X-Other'];
// @ts-expect-error consent() takes the answer
consent();

export { again, agreed, headers, id, marked, missing, recording, sent, started };
