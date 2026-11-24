/**
 * @packstub/session-replay: the Session Replay for Laravel recorder for a frontend served apart from the Laravel app.
 * See https://packstub.dev/docs/session-replay (Standalone frontend).
 */

export interface StartOptions {
    /** The app's token route, e.g. "https://api.example.com/session-replay/token". */
    endpoint: string;
    /** Sent with the token request: "include" (the default) for a session cookie, "omit" with a bearer token. */
    credentials?: RequestCredentials;
    /** Extra headers for the token request only, e.g. { Authorization: `Bearer ${token}` }. */
    headers?: Record<string, string>;
    /** The fetch the token request goes through; the browser's own by default. */
    fetch?: (input: string, init: RequestInit) => Promise<Response>;
    /** Also put the recorder on window.SessionReplay. */
    global?: boolean;
}

/** Fetches a token and records; resolves to whether this tab records. Call it again after a sign-in or sign-out. */
export function start(options?: StartOptions): Promise<boolean>;

/** Stops recording until start() or consent(true). */
export function stop(): void;

/** Records a moment of your own on the timeline. */
export function mark(label: string, payload?: Record<string, unknown>): boolean;

/** Sends the replay now (a "report a problem" button); resolves to whether anything went up. */
export function send(label?: string, payload?: Record<string, unknown>): Promise<boolean>;

/** true remembers the answer and starts recording, false forgets it and stops. */
export function consent(given: boolean): boolean;

/** The running recording's id, or null. */
export function sessionId(): string | null;

/** Whether this tab uploads right now. */
export function isRecording(): boolean;

/** The header that links your API's log lines to the replay; {} while nothing records. Send it to your own API only. */
export function header(): { 'X-Session-Replay'?: string };
