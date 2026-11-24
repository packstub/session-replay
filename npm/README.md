# @packstub/session-replay

The browser recorder of [Session Replay for Laravel](https://github.com/packstub/session-replay), for a frontend that runs on its own origin: a Vue or React app on `app.example.com` that talks to your Laravel API on `api.example.com`. It records the page with [rrweb](https://github.com/rrweb-io/rrweb) and uploads to your own Laravel app, where the recordings are stored and watched. Nothing goes to a third party.

If Laravel renders your pages (Blade, Livewire, Inertia), you don't need this package: the `@sessionReplay` directive of the Composer package loads the same recorder.

## Requirements

A Laravel app with [`packstub/session-replay`](https://packagist.org/packages/packstub/session-replay) installed, the token route turned on and your frontend's origin listed:

```dotenv
SESSION_REPLAY_TOKEN_ENDPOINT=true
SESSION_REPLAY_ORIGINS=https://app.example.com
```

Use the same version of both packages: this one is released with the Composer package, under the same version number.

## Install

```bash
npm install @packstub/session-replay
```

## Usage

```js
import { start } from '@packstub/session-replay';

start({ endpoint: 'https://api.example.com/session-replay/token' });
```

`start()` fetches the recorder's config and a signed token from your app, behind your app's own sign-in, and resolves to `true` once the tab records. Call it again whenever the person signs in or out (the options of the first call carry over): a token for someone else ends the current recording and starts a new one.

The token is renewed for you before it expires, so a tab that stays open for days keeps recording to its last moment.

With a bearer token instead of the session cookie:

```js
start({
    endpoint: 'https://api.example.com/session-replay/token',
    credentials: 'omit',
    headers: { Authorization: `Bearer ${token}` },
});
```

| Option | Default | Meaning |
| --- | --- | --- |
| `endpoint` | required | The token route's URL. |
| `credentials` | `'include'` | Sent with the token request. Use `'omit'` with a bearer token. |
| `headers` | `{}` | Extra headers for the token request only, such as `Authorization`. |
| `fetch` | the browser's | The `fetch` the token request goes through. |
| `global` | `false` | Also expose the recorder as `window.SessionReplay`. |

## API

| Function | What it does |
| --- | --- |
| `start(options)` | Fetches a token and records. Resolves to whether this tab records. |
| `stop()` | Stops recording until `start()` or `consent(true)`. |
| `mark(label, payload)` | Puts a moment of your own on the recording's timeline. |
| `send(label, payload)` | Sends the replay now, for a "report a problem" button. |
| `consent(given)` | `true` remembers the answer and starts recording, `false` forgets it and stops. |
| `sessionId()` | The running recording's id, or `null`. |
| `isRecording()` | Whether this tab uploads right now. |
| `header()` | `{ 'X-Session-Replay': '…' }` to link your API's log lines to the replay, `{}` while nothing records. |

`header()` carries the recording's write key: send it to your own API only.

```js
api.interceptors.request.use((config) => {
    Object.assign(config.headers, header());

    return config;
});
```

The package ships TypeScript declarations and can be imported during server-side rendering; it records only in a browser.

## Documentation

The full guide, with the Laravel side, Vue and React examples, CORS and the limits: [Standalone frontend](https://packstub.dev/docs/session-replay/standalone).

## License

MIT
