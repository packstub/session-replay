# Standalone frontend

`@sessionReplay` records pages that Laravel renders. If your frontend runs on its own origin, for example a Vue or React app on `app.example.com` that talks to your Laravel API on `api.example.com`, use the npm package `@packstub/session-replay` instead. It runs the same recorder. The difference is where the config comes from: the package fetches it from a token route on your app, behind your app's own sign-in, and then uploads to the usual ingest routes across origins. Recordings are stored and watched like every other recording.

## On the Laravel side

Turn on the token route and list the frontend's origin:

```dotenv
SESSION_REPLAY_TOKEN_ENDPOINT=true
SESSION_REPLAY_ORIGINS=https://app.example.com
```

`SESSION_REPLAY_ORIGINS` takes a comma-separated list. Write each entry as an exact origin: scheme and host, plus the port if it is not the default (`http://localhost:5173` for a Vite dev server). Wildcards, paths and `null` are ignored and logged as a warning. The app's own origin is always allowed.

That adds `GET {path}/token` (`session-replay.token`). It runs behind `ingest.token_middleware`, and the person it signs in is recorded under the same rules as on a rendered page: `guests`, `recordWhen()`, `userUsing()`, `tenantUsing()`, `impersonatorUsing()`, `propertiesUsing()` and `privacy.anonymous`. The answer is the config the directive would embed, with a fresh signed token:

```json
{ "record": true, "config": { "token": "…", "ingestUrl": "https://api.example.com/session-replay/ingest", "…": "…" }, "expires_at": "2026-12-01T10:00:00+00:00" }
```

If the person is not recorded, the route answers `{ "record": false }`. A request from an origin that is neither listed nor the app's own gets a 403 before your middleware runs, and so does one that a tag or a link on another page made (the browser says so in `Sec-Fetch-Site`). Answers are sent with `Cache-Control: no-store, private`.

### Session cookie or bearer token

The default, `'token_middleware' => ['web']`, reads the session cookie. This is the setup Sanctum uses for an SPA: the frontend and the API share a site (`app.example.com` and `api.example.com`) and the frontend sends credentials. Guests reach the route too and get `record: false` unless `guests` is on.

The session cookie only travels between origins of the same site. If the frontend lives on another site altogether (`app.example.com` and `api.example.net`), browsers leave a `SameSite=Lax` cookie out of the request, so sign in with a bearer token there.

With bearer tokens, use the guard instead:

```php
// config/session-replay.php
'ingest' => [
    'token_middleware' => ['auth:sanctum'],
],
```

With `auth:sanctum`, a request without a valid token gets a 401, which the package treats as "not recorded", so guests are never recorded this way.

### Rate limits

A signed-in person can fetch tokens up to `ingest.requests_per_minute` times a minute. All guests together share `ingest.guest_tokens_per_minute` (600). The limits never key on the IP address.

## In the frontend

```bash
npm install @packstub/session-replay
```

```js
import { start } from '@packstub/session-replay';

start({ endpoint: 'https://api.example.com/session-replay/token' });
```

`start()` resolves to `true` once the tab records. Call it again whenever the person signs in or out: a token for someone else ends the current recording and starts a new one, and `record: false` stops recording.

| Option | Default | Meaning |
| --- | --- | --- |
| `endpoint` | required | The token route's URL. |
| `credentials` | `'include'` | Sent with the token request. Use `'omit'` with a bearer token. |
| `headers` | `{}` | Extra headers for the token request only, such as `Authorization`. |
| `fetch` | the browser's | The `fetch` the token request goes through. |
| `global` | `false` | Also expose the recorder as `window.SessionReplay`. |

The package also exports `stop()`, `mark(label, payload)`, `send(label, payload)`, `consent(given)`, `sessionId()`, `isRecording()` and `header()`. They work like the [browser API](recording.md) of the directive's recorder. Before `start()` has run, `consent()` only stores the answer, and `start()` picks it up.

The recorder will only upload to the token route's own origin. If `ingestUrl`, `assetUrl` or the snapshot URL in the answer point anywhere else, the answer is refused and nothing is recorded. If an upload gets a 401 (for example, the token expired while the tab stayed open), the recorder fetches a fresh token once and sends the batch again.

### Vue

```js
// src/main.js
import { createApp } from 'vue';
import { start } from '@packstub/session-replay';
import App from './App.vue';
import router from './router';

createApp(App).use(router).mount('#app');

start({ endpoint: `${import.meta.env.VITE_API_URL}/session-replay/token` });
```

```js
// after login and logout, e.g. in your auth store
import { start } from '@packstub/session-replay';

await api.post('/login', credentials);
await start(); // options from the first start() carry over
```

### React

```jsx
// src/App.jsx
import { useEffect } from 'react';
import { start } from '@packstub/session-replay';
import { useAuth } from './auth';

export default function App() {
    const { user } = useAuth();

    // Again on every sign-in and sign-out: a new person gets a new recording.
    useEffect(() => {
        start({ endpoint: `${import.meta.env.VITE_API_URL}/session-replay/token` });
    }, [user?.id]);

    return <Routes />;
}
```

With a bearer token:

```js
start({
    endpoint: 'https://api.example.com/session-replay/token',
    credentials: 'omit',
    headers: { Authorization: `Bearer ${token}` },
});
```

Page changes made by the router (`pushState`, the back button) become navigation markers. Failed requests to the API's origin, meaning the token route's origin, become request markers, as same-origin requests do for a rendered page.

## Link your API's logs to the replay

The recording's cookie lives on the frontend's origin and never reaches your API, so the package exposes the same value as a header. `AddReplayContext` reads `X-Session-Replay` (`context.header`) when there is no cookie, and puts the recording into Laravel's `Context` like the cookie does (see [Error tracking](error-tracking.md)):

```js
import axios from 'axios';
import { header } from '@packstub/session-replay';

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL, withCredentials: true });

// Your own API only: the header carries the recording's write key.
api.interceptors.request.use((config) => {
    Object.assign(config.headers, header());

    return config;
});
```

`header()` returns `{}` while nothing is recording. Because it is a custom header, the browser sends a preflight request first, so your API's CORS settings (`config/cors.php`) must allow it: `allowed_headers` is `['*']` by default, and if you list headers yourself, add `X-Session-Replay`.

## config/cors.php

The package handles CORS for its own routes. Keep its path (`session-replay/*`) out of `cors.paths` in your app. If the path is listed there, Laravel's middleware answers those requests with your app's rules, and the package logs a warning. The defaults (`api/*`, `sanctum/csrf-cookie`) don't match it.

The upload routes never allow credentials. They trust the signed token in the request body and nothing else. Only the token route allows credentials, since it runs behind your sign-in.

## Limits

- **No Assist across origins.** Help requests, co-browsing and calls stay on pages Laravel renders.
- **`except` and `except_routes` don't apply** to the frontend's routes. The server never sees them. Leave a page out by calling `stop()` there and `start()` when the person leaves it.
- **No shared snapshots.** An SPA has one page load per tab, so there is nothing to share.
- **Send the header only to your own API.** It carries the recording's write key.
- **The token is signed, not encrypted.** The person, workspace, impersonator and properties in it can be read by the page, as they can in a rendered page's source.
- **An oversized upload can't be read by the frontend.** Laravel's `ValidatePostSize` answers it with a 413 before routing, so the answer carries no CORS headers. Keep `ingest.max_batch_kb` under PHP's `post_max_size`.
