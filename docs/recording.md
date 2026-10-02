# Recording

## The directive

```blade
@sessionReplay
```

It renders a small `<script>` with the recorder's configuration and a deferred `<script src>` for the recorder itself, or nothing at all when this request is not recorded.

It accepts an options array for a page that knows more than the defaults:

```blade
@sessionReplay([
    'user' => auth('customer')->user(),
    'tenant' => $team,
    'impersonator' => session('impersonator_id'),
    'properties' => ['plan' => $team->plan],
    'nonce' => $cspNonce,
])
```

| Option | Meaning |
| --- | --- |
| `user` | The signed-in person, when it is not the default guard's user. Overrides `SessionReplay::userUsing()`. `null` means a guest. |
| `tenant` | The workspace (an Eloquent model). Overrides `SessionReplay::tenantUsing()`. |
| `impersonator` | The key of the person impersonating the user. Overrides `SessionReplay::impersonatorUsing()`. |
| `properties` | An array merged over what `SessionReplay::propertiesUsing()` returns; stored on the recording. |
| `nonce` | A CSP nonce for both script tags. |

Outside Blade, `SessionReplay::recorder($options)` returns the same HTML.

## Who is recorded

`SessionReplay::shouldRecord()` runs these checks in order; the first that fails means the directive renders nothing:

1. `enabled` is `true` (`SESSION_REPLAY_ENABLED`).
2. The request path is not the package's own (`path` and everything under it) and matches none of the `except` patterns (`Str::is` style, for example `admin/secrets*`), and the route's name matches none of the `except_routes` patterns (for example `billing.*`).
3. Someone is signed in, or `guests` is `true`.
4. `SessionReplay::recordWhen()` was not registered, or returns `true`.

```php
use Packstub\SessionReplay\Facades\SessionReplay;

// In a service provider's boot()
SessionReplay::recordWhen(fn ($user, $request): bool => ! $user?->is_staff);
```

The callback receives the user (or `null` for a guest) and the request.

The resolvers tell the package who and where:

```php
SessionReplay::userUsing(fn ($request) => auth('customer')->user());
SessionReplay::tenantUsing(fn ($request) => $request->user()?->currentTeam);
SessionReplay::impersonatorUsing(fn ($request) => session('impersonated_by'));
SessionReplay::propertiesUsing(fn ($request) => ['release' => config('app.version')]);
```

All four are evaluated when the page renders and signed into the token the recorder uploads with, so the ingest endpoint never has to work out identity itself. A token is accepted for seven days (`ingest.token_days`).

### Cached pages

A page served from a full-page cache (a response cache, a CDN, a static export) carries the token it was cached with. Two settings keep its uploads welcome:

```php
// config/session-replay.php
'ingest' => [
    // Longer than the longest time a page stays cached, origin and edge together.
    'token_days' => 10,
    // Guest pages cached for longer than that, or for good: a token that names nobody never expires.
    'guest_tokens_expire' => false,
],
```

`guest_tokens_expire` only ever applies to a token with no person, no workspace and no impersonator in it; a token that names someone always expires. Uploads with such a token are size-limited like every other and refused while `guests` is off. Guests are throttled per rendered page, so everyone served the same cached copy shares one `ingest.throttle` budget: on a busy cached page, raise it (each open tab uploads about 12 times a minute), and keep `ingest.guest_daily_mb` above a day of guest traffic. Cache only guest pages: a cached page with a person's token in it would attribute every visitor's recording to that person.

## Sessions, idle time and sampling

- **One recording per browser tab.** The recording's id (a UUID) lives in `sessionStorage` and continues across page loads: every full page load adds a new snapshot to the same recording.
- **One person, one workspace per recording.** A tab that signs in, signs out, switches to another person, moves to another workspace or starts impersonating starts a new recording on that page load. A visitor's walk through your public pages and their signed-in session are two recordings, and a recording filed under one workspace never holds pages of another. The server holds the same line: an upload whose token names another person or workspace than the recording's first batch is refused.
- **`idle_timeout`** (minutes, default 30): a tab without activity for longer starts a new recording on its next page load.
- **`sample_rate`** (0 to 1, default 1): the share of recordings that are kept. The decision is made once, in the browser, when a recording starts, and stays with it.
- **`flush_interval`** (milliseconds, default 5000, minimum 1000): how often events upload while the page is open. A batch also uploads when the tab is hidden and when the page goes away.
- A recording that reaches `ingest.max_session_mb` is marked truncated and the recorder stops.

## Markers

Markers are the moments worth jumping to. Each one is stored in `replay_markers` (type, label, payload, time) and drawn on the player's timeline.

| Type | When | Setting |
| --- | --- | --- |
| `navigation` | Every page load; every `wire:navigate` page swap while `capture.livewire` is on. Label: path and query. | page loads: always |
| `error` | An uncaught error or an unhandled promise rejection. Payload: source, line, column, stack. | `capture.errors` |
| `console` | `console.error(...)`. Other levels you add to `capture.console` are kept in the recording's events, without a marker. | `capture.console` (levels; `[]` turns the console off) |
| `request` | A Livewire request that failed. Payload: status, URL. | `capture.livewire` |
| `vital` | LCP, INP and CLS, with value and rating. | `capture.vitals` |
| `rage-click` | Three or more clicks on the same spot within 700 ms. Label: the control that was clicked (the button around the icon, not the icon), named by its `alt`, `aria-label` or `title`, or by a button's own caption, as in `button "Save changes"`; tag, id and classes when it has no name or sits in a masked area. The text of links, cells and anything else is never used. | `capture.rage_clicks` |
| `custom` | Your own, from the browser. | always |

A recording's `error_count` counts `error` and `request` markers, `page_count` counts `navigation`, `rage_click_count` counts `rage-click`, and `lcp_ms`, `inp_ms` and `cls` keep the worst value measured.

### Your own markers

```js
SessionReplay.mark('Checkout started', { cart: 3 });
```

## The browser API

`window.SessionReplay` exists on every page the directive rendered on.

| Method | What it does |
| --- | --- |
| `start()` | Starts recording if consent allows and the recording is sampled in. Returns `true` when recording started. Called automatically on load. |
| `stop()` | Stops recording, drops what was not uploaded yet and clears the log-context cookie. |
| `flush()` | Uploads what is buffered now. Returns a promise. |
| `mark(label, payload = {})` | Adds a `custom` marker. |
| `consent(given)` | `true` remembers the answer and starts; `false` remembers it and stops. See [Privacy](privacy.md#consent). |
| `isRecording()` | `true` while events are being recorded. |
| `sessionId()` | The recording's id while recording, otherwise `null`. |

## Livewire

The recorder detects Livewire in the browser; the package itself does not depend on it.

- **Failed requests** become `request` markers with the status (a 419 after the session expired, a 500, a 503 when the network dropped).
- **`wire:navigate`** swaps pages without a load. rrweb records the swap as ordinary DOM changes, and the recorder adds a `navigation` marker on `livewire:navigated`.
- **Attributes the player never uses are dropped** before upload: `wire:*` (including `wire:snapshot` and `wire:effects`), `x-*`, `@*`, `:*` and `ax-load*`. The player runs no scripts, so they only cost bytes, and dropping `wire:snapshot` keeps component state out of recordings. `x-cloak`, `wire:loading*`, `wire:offline*` and `wire:dirty*` are kept because stylesheets select on them: Livewire hides its loading, offline and dirty indicators with a rule on those attributes, and a replay without them would show every spinner at once. Change the lists with `size.strip_attributes` and `size.keep_attributes`.
- A widget that polls or animates constantly can be left out with `data-replay-block`.

## Content Security Policy

Both script tags carry a nonce when one is available: the `nonce` option, or `Vite::cspNonce()` automatically when your app calls `Vite::useCspNonce()`. The scripts are served from your own origin, so `script-src 'self'` covers them too. Uploads go to your own origin (`connect-src 'self'`).

## Known limits

- **Duplicated tabs share a recording id.** The browser copies `sessionStorage` into a duplicated tab, so both tabs upload into one recording and some batches of the second tab are skipped as duplicates.
- **Canvas and cross-origin iframes are not recorded.** A same-size placeholder appears in the replay.
- **Images and fonts are referenced by URL.** The replay loads them from where the page did; one that was removed since shows as missing. Stylesheets are stored with the recording.
- **The last moments before a tab closes** upload in one request without compression; a very large final batch may not make it.
