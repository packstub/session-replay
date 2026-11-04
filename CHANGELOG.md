# Changelog

All notable changes to `packstub/session-replay` are documented here.

## Unreleased

- The README opens with a banner and an animation of a replay; the docs sync to packstub.dev on every change
- A demo app, [packstub/session-replay-demo](https://github.com/packstub/session-replay-demo): a small Laravel shop with real recordings to watch
- **Fixed:** the `on_error.ask` dialog, the toast and the notice carry the page's CSP nonce (read from the recorder's own tag), and so does the built-in viewer's stylesheet; a `style-src` that names a nonce no longer leaves them unstyled. Watching a replay still needs `'unsafe-inline'` in the `style-src` of the page the player is on, as rrweb rebuilds the recorded page from inline styles: [Recording](docs/recording.md#content-security-policy) and [Watching](docs/watching.md#content-security-policy) say what a policy needs on each side, and `CSP=1 composer serve` runs the workbench under a strict one

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
