# Changelog

All notable changes to `packstub/session-replay` are documented here.

## 1.3.0 — 2026-11-24

**Record a frontend on its own origin.** A Vue or React app served apart from Laravel can now be recorded with the new npm package `@packstub/session-replay`, and watched like any other recording. See [Standalone frontend](docs/standalone.md).

- `@packstub/session-replay` on npm: `start()`, `stop()`, `mark()`, `send()`, `consent()` and `header()`, the same recorder as `@sessionReplay`
- A token route, `GET {path}/token`, behind your own sign-in, off by default (`SESSION_REPLAY_TOKEN_ENDPOINT=true`)
- CORS on the upload routes and the token route for the origins in `ingest.origins` (`SESSION_REPLAY_ORIGINS`); uploads still trust nothing but the signed token
- The `X-Session-Replay` header links your API's log lines to the replay, the way the cookie does for rendered pages

## 1.2.0 — 2026-11-20

**Introducing Assist: live help, right on the page.** Session Replay no longer only shows what happened: support can now join a visitor while they are still there. The agent sees the page live, points, draws over it and, when the visitor allows it, takes control, talks or shares a screen, all from your own Laravel app. The live part runs on [Holler for Laravel](https://github.com/packstub/holler) 1.0: a WebRTC call straight between the two browsers, with signaling over plain HTTP, so there is no websocket server to run.

### ✨ Assist, powered by Holler

- **Ask or offer:** a visitor asks with the optional "Get help" button or `SessionReplay.assist.request()`; support offers help from the new **Live now** tab, and the visitor answers Accept or Not now
- **The live stage:** the visitor's page as it happens, masked exactly like the recording, with the agent's pointer and annotations that fade
- **Everything is asked:** remote control, typing, audio, video and screen share are each asked of the visitor separately; Escape takes control back, and a password field is never filled
- **Built for real support work:** Put on hold and Resume, one live assist per agent, and a request that waits while the agent steps away and comes back
- **Safe by default:** limits per recording, per hour and for guests, `SessionReplay::canAssistUsing()` for who may help, and a grants log plus an `assist` marker on the recording saying what was allowed and for how long
- **Yours to place:** events for every step (`AssistRequested`, `AssistClaimed`, `AssistStarted`, `AssistEnded`), a stage you can host in a page of your own, and in a Filament panel with Filament Session Replay

**Getting started:** set `SESSION_REPLAY_ASSIST=true`, run `php artisan migrate` (new `replay_assist_requests` table) and `composer require packstub/holler` for the live view; without Holler, requests work as a plain queue. Everything else is in the [Assist guide](docs/assist.md).

### Also in this release

- **Security:** the upload endpoints (and the new Assist routes) read the signed token, the write key and a request's secret from the request body only, never from the query string, which access logs, proxies and error trackers keep
- `data-replay-skip` leaves an element out of the recording with everything inside it
- After a server outage, tabs spread their uploads over 30 to 120 s and honour `Retry-After`; `ingest.global_requests_per_minute` caps uploads for the whole app (off by default) ([When the upload fails](docs/recording.md#when-the-upload-fails))
- **Fixed:** a window kept in the browser during an outage is sent on the tab's next page load (since 1.1.0 it waited until it expired)
- **Fixed:** on SQLite, uploads and Assist grants wait their turn instead of failing with "database is locked"

## 1.1.0 — 2026-11-04

A security release, with the memory fixes for a panel tab that grew all day. Run `php artisan migrate`: a table, a column and two indexes.

**Security:** reported privately on 2026-10-26, from reading the source. A viewer of one workspace's recording could read a stylesheet or a shared page snapshot stored for another workspace, given its hash, and a recording that names nobody could be appended to by anyone holding its id. Recordings from before 1.0.0-beta.5 replay without their stylesheets. Details in [Storage](docs/storage.md#the-tables) and [Error tracking](docs/error-tracking.md#how-it-works).

- A stylesheet or a shared snapshot is served only to a recording whose own workspace uploaded it: the new `replay_asset_owners` table says who proved the content, storage stays once per hash
- Every recording gets a write key, made by its browser and sent with every upload and in the log-context cookie; a recording from before write keys closes to uploads twice `idle_minutes` after its last activity, and a day after it started
- An id that is no recording answers 403 like one the gate denies, and a browser cannot mark an error as the server's or give it a report id
- A tab that navigates with `wire:navigate` no longer keeps a page snapshot per page change (rrweb 2.2.0); batches waiting for a slow server or a retry wait compressed
- Recording a long table costs less: the mask selector no longer walks every element's ancestors (default `[data-replay-mask], [contenteditable]`), big mutations are sized without being stringified, and the `on_error` window holds what `max_buffer_mb` says. See [Recording](docs/recording.md#recording-only-when-something-goes-wrong)
- The server checks a gzip batch without holding what it inflates, bounds the index next to the events and stores at most 5,000 markers per recording ([Limits](docs/storage.md#limits)); a recording whose files the disk could not remove is pruned again next time
- **Fixed:** the `on_error.ask` dialog, the toast and the built-in viewer's stylesheet carry the page's CSP nonce; what a policy needs on each side is in [Recording](docs/recording.md#content-security-policy) and [Watching](docs/watching.md#content-security-policy)
- The README opens with a banner and an animation of a replay, the docs sync to packstub.dev on every change, and a demo app, [packstub/session-replay-demo](https://github.com/packstub/session-replay-demo), shows the package in a small shop

## 1.0.0 — 2026-10-22

The first stable release, with a "Report a problem" button, server errors on the replay and a long list of privacy and upload fixes.

**Upgrading:** run `php artisan migrate` (a column for the impersonator's type and an index for a workspace's list). Config keys were renamed to name their unit (`idle_minutes`, `flush_seconds`, `on_error.ask_timeout_seconds`, `ingest.requests_per_minute`); a published config with the old names keeps working, but `config()->set()` at runtime needs the new names ([#42](https://github.com/packstub/session-replay/issues/42)).

- `SessionReplay.send()` for a "Report a problem" button, with a `ReplayReported` event to tell support ([#39](https://github.com/packstub/session-replay/issues/39))
- Server errors on the replay, marker details, and **Open in logs** through `SessionReplay::logUrlUsing()`
- Failed `fetch` and XHR requests and client-side page changes are marked in apps without Livewire
- **Copy link to this moment** under the player, and the `on_error.ask` question as a toast
- A `ReplaySession` policy (Filament Shield's, say) authorizes the viewer when no gate is defined ([#41](https://github.com/packstub/session-replay/issues/41))
- The impersonator can be a model ([#40](https://github.com/packstub/session-replay/issues/40)), and a `session-replay:session` browser event for error trackers ([#43](https://github.com/packstub/session-replay/issues/43))
- Privacy fixes: hidden and file inputs always masked, URLs redacted in errors, stricter defaults for a misspelt `consent` or `mode` ([#38](https://github.com/packstub/session-replay/issues/38)), `visibleUsing()` kept to its scope
- Sturdier uploads and times: the daily limit is obeyed, half-failed batches are stored in full, and times follow the app's timezone

Docs: https://packstub.dev/docs/session-replay

## 1.0.0-beta.5 — 2026-10-07

- A page reached with `wire:navigate` is recorded as a fresh snapshot, like a page load: smaller recordings and quicker seeking
- Stylesheets that finish loading after the page was recorded are stored once too

## 1.0.0-beta.4 — 2026-10-07

- `on_error.livewire_error_modal`: the `on_error.ask` question can take the place of Livewire's error modal
- The question is now a native `<dialog>`, always above the page, Livewire's modal included

## 1.0.0-beta.3 — 2026-10-07

Recording only when something goes wrong. Run `php artisan migrate` for the new `replay_session_assets` table.

- `mode = on_error`: the browser keeps the last minute in memory and uploads it only after an error
- `on_error.ask`: a small dialog asks the person before sending, with an option to send anonymously
- The kept window survives a failed upload and goes up on the next page load (`on_error.keep_pending`)
- Shared snapshots: pages that look the same for everyone can be stored once (`snapshots.share_routes`)
- Failed uploads are retried for about four minutes instead of 14 seconds
- The player works in Firefox 140 ESR. Thanks @VincentBean.

## 1.0.0-beta.2 — 2026-10-02

- `except_routes`: route names that never get the recorder; password-reset and verification pages left out by default
- `privacy.anonymous`: record signed-in people without storing who they are
- `privacy.store_user_agent = false` drops the browser's user agent
- A published `except` list with keyed entries no longer throws

## 1.0.0-beta.1 — 2026-10-02

First beta of Session Replay for Laravel: record what people do in your app with rrweb and watch it back. Names and config may still change before 1.0.

- `@sessionReplay` records each tab across page loads, with sampling, opt-in consent and one recording per person and workspace
- Private by default: every input masked, no IP address stored, sensitive query parameters redacted
- Errors, failed Livewire requests, Web Vitals, rage clicks and page views as markers on the timeline
- Small recordings: stylesheets stored once, batches gzipped in the browser, kept on any filesystem disk
- A built-in list and player behind the `viewSessionReplay` gate, and a `<x-session-replay::player>` component
- Replay links in Laravel's log context; the viewer in English, German, Spanish, Romanian and Russian

Docs: https://packstub.dev/docs/session-replay
