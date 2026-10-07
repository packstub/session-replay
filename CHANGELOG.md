# Changelog

All notable changes to `packstub/session-replay` are documented here.

## Unreleased

### Fixed

- **Pages the app does not record are not recorded when reached with `wire:navigate` either.** `except`, `except_routes`, `recordWhen()` and the Filament plugin's own rules (its Sessions pages, `record()`) only kept the recorder off pages loaded in full: with `wire:navigate` the recorder of an earlier page kept going on them. The recorder now reads its config again on every page Livewire swaps in. A page without one is not recorded: no snapshot, events, markers or console messages, including those during the swap to it and away from it, until a page that is recorded; `SessionReplay.isRecording()` is `false` there. Each page's signed token is used from then on, and a page signed for another person, workspace or impersonator (a tenant switch with `wire:navigate`) starts a new recording, as a page load does; before, the visit was filed in the recording of the page the tab started on. A page's `snapshots.share` setting now applies to the snapshots taken on it.

## 1.0.0-beta.5 — 2026-10-07

### Changed

- **A `wire:navigate` page is recorded as a snapshot, like a page load.** Livewire replaces the whole page body on `wire:navigate`, which rrweb recorded as one change adding every node of the new page. The recorder now pauses on `livewire:navigating` and starts again on `livewire:navigated` (or after five seconds if that never comes), so the new page arrives as a fresh snapshot. In the Filament lab's navigation run a page went from 25 KB to 17 KB on the disk, about a third less main-thread blocking, the player jumps to any page without replaying the ones before it, and the last page before the tab closed is no longer lost when the swap was too large for the closing request. Markers, `SessionReplay.mark()` calls and console messages during the swap are kept, and the console stays patched through it, so a wrapper another script put around `console.error` stays in place. A recording now has a chunk row per page with `wire:navigate` as with page loads. Snapshots of pages reached with `wire:navigate` are not shared through `snapshots.share_routes`: only a page load is, never a page shown after a `wire:navigate`, even when recording started there.

### Fixed

- **Stylesheets that finish loading after the page was recorded are deduplicated too.** rrweb sends the text of a `<link rel="stylesheet">` that was still loading when it was serialized (a page-specific stylesheet `wire:navigate` appends, or a slow one at page load) later, as an attribute change. That text went up inline on every page view; it is now stored once by hash like every other stylesheet, and the player puts it back. Recordings made before keep playing as they are.

## 1.0.0-beta.4 — 2026-10-07

### Added

- **`on_error.livewire_error_modal`.** When a failed Livewire request puts the `on_error.ask` question up, the question can take the place of Livewire's modal with the error page: `production` (the default) does so while `app.debug` is off, where that page says no more than "500 | Server Error", and keeps the debug error page behind the question in development; `replace` always, `keep` never. When a failed request put the question up, the dialog shows the status code and the error page's title (left out when it is only the app's name, as on the debug page) under its own title and says the last action could not be completed (`recorder.ask.failed`), so nothing is lost with the modal; after "Send" a short notice thanks the person (`recorder.ask.sent`). Both strings in all five languages. An expired page (419) keeps Livewire's offer to reload. Nothing changes without `on_error.ask`.

### Fixed

- **The `on_error.ask` dialog is a native `<dialog>` in the browser's top layer.** It opens with `showModal()` one tick after the trigger, so it is centred in the viewport whatever the page does and sits above anything with a z-index, Livewire's own error modal included: a failed Livewire request used to open Livewire's error page over the question, which only showed once that page was closed. The rest of the page is inert while it is open; Escape and a `close()` from outside count as "Don't send".

## 1.0.0-beta.3 — 2026-10-07

### Added

- **Recording only when something goes wrong (`mode = on_error`).** The browser keeps the last `on_error.buffer_seconds` (60) in memory, with a fresh page snapshot every half window, and uploads nothing until a marker of a type in `on_error.triggers` (`error` and `request` by default; `console`, `rage-click` and `custom` on request) happens. Then it uploads that window and records the rest of the tab's session as usual. `mode` defaults to `session`, so nothing changes for existing apps. `SessionReplay.isBuffering()` in the browser.
- **`on_error.ask`.** After a trigger, a small dialog drawn by the recorder (plain DOM in a closed shadow root, never part of a replay) asks whether to send the replay; "Don't send" drops it and leaves the tab alone for the rest of its session. Signed-in people can send it anonymously: the `privacy.anonymous` rule for that one recording (an `anonymous` field on the upload that only ever removes identity from the signed token). Strings in `recorder.php`, in all five languages.
- **The window survives a failed upload (`on_error.keep_pending`).** The server that cannot take the upload is usually the one whose failed request fired the trigger. The first time the window's upload fails it is kept in the browser's IndexedDB, and the tab's next page load sends it first, in its original place in the recording; dropped after `idle_timeout`, when the person changes or when the tab stops. On by default; `false` keeps nothing recorded at rest in the browser. Nothing is kept before the person agreed with `on_error.ask`.
- **Shared snapshots.** Pages that look the same for everyone (a pricing page, the docs, a sign-in form) can have their snapshot stored once per SHA-256 of its content and shared by every recording, like stylesheets: opt in per route name or path with `snapshots.share_routes` and `snapshots.share_paths` (`Str::is` patterns, keyed entries read like plain ones). The chunk keeps the full snapshot event with `data.srSnapshot: <sha256>` in place of its tree; recordings without it, and pages not opted in, are stored as before. Ids a script makes up on every load (`snapshots.volatile_ids`, Filament's dropdown panels by default) are renamed in shared snapshots, so two loads of a page still match. The upload answers the same whether the snapshot was stored or not, the viewer serves one only for a recording that points at it, and `session-replay:prune` keeps it while a recording that is left does. New: the `replay_session_assets` table, `replay_assets.kind`, the `session-replay.ingest.snapshot` and `session-replay.snapshot` routes. Run `php artisan migrate` (or publish the migrations again with `run_migrations` off).

### Changed

- **Failed uploads are retried for about four minutes** instead of 14 seconds, in both modes: after 2, 4, 8, 16 and 32 seconds, then once a minute, or sooner when the browser comes back online. Later batches wait in memory, in order.

### Fixed

- **Player in Firefox 140 ESR.** Recordings failed to play with `rrweb-snapshot.rebuild() cannot rebuild into an unprotected browser document`; the player now keeps the replay frame's first document. Thanks @VincentBean.

## 1.0.0-beta.2 — 2026-10-02

### Added

- **`except_routes`.** Route names (`Str::is` patterns) that never get the recorder, next to the `except` paths. The default list leaves out the password-reset and verification pages of Laravel's starter kits (`password.*`, `verification.*`) and of Filament panels (`filament.*.auth.password-reset.*`, `filament.*.auth.email-verification.*`, `filament.*.auth.email-change-verification.*`), whatever their URL. An app that published its config before gets the default list from the package until it sets its own.
- **`privacy.anonymous`.** Records signed-in people without storing who they are: no person and no impersonator, the workspace and your properties stay. Who is signed in still decides whether a page is recorded and when a new recording starts, and uploads are limited per person through a keyed hash in the signed token that is never stored. Filament panels follow it too.
- **`privacy.store_user_agent`.** `false` drops the browser's user agent; the device class is kept.

### Changed

- **Docs.** A shorter Features list in the README and on the docs index, one line per area.

### Fixed

- **`except` with keyed entries.** A published `except` list with a keyed entry followed by a plain one (`['secrets' => 'admin/secrets*', 'billing/*']`) threw on every recorded page; keys are now ignored, in `except_routes` too.

## 1.0.0-beta.1 — 2026-10-02

First beta. Names and config may still change before 1.0; every change will be listed here with how to upgrade.

### Added

- **Recorder.** `@sessionReplay` before `</body>` records the page with rrweb 2: one recording per tab, continued across page loads until the tab sits idle (`idle_timeout`), a sticky sampling decision (`sample_rate`), `SessionReplay::recordWhen()`, `guests`, `except`, opt-in consent (`window.SessionReplay.consent(true)`), optional Global Privacy Control. A recording belongs to one person in one workspace: a tab that changes person, workspace or impersonator starts a new one, and the server refuses an upload that crosses either line.
- **Privacy defaults.** Every input masked (passwords always), `data-replay-mask`, `data-replay-block`, `data-replay-ignore`, `privacy.mask_all_text` for layout-only recordings, no IP address stored. Text typed into rich editors (`contenteditable`) is masked like an input, hidden inputs lose their value, the query parameters in `privacy.redact_query` (`token`, `signature`, `code`, `state`, `email`, …) lose their value in every URL the recorder sends (links and form actions in the page included), and the default `except` list leaves out password-reset and verification pages.
- **Markers.** Uncaught errors and unhandled rejections, `console.error`, failed Livewire requests, LCP/INP/CLS, rage clicks, page views (`wire:navigate` included) and your own (`SessionReplay.mark()`), indexed in `replay_markers` and drawn on the player's timeline. A rage click is labelled by the control that was clicked: `button "Save changes"`, never the text of a link or a cell.
- **Small recordings.** Stylesheets are stored once per SHA-256 of their content instead of inside every snapshot, attributes the player never uses (`wire:snapshot`, `wire:effects`, Alpine expressions) are dropped, comments and scripts are left out, batches are gzipped in the browser and stored and served as sent. `x-cloak` and Livewire's `wire:loading*`, `wire:offline*` and `wire:dirty*` stay, because stylesheets select on them (`size.keep_attributes` takes `prefix*` too).
- **Ingest.** Identity comes from a token signed when the page rendered (person, workspace, impersonator, properties), so the endpoint needs no session and no CSRF token; per-person throttle, batch and recording size limits, idempotent retries. Guests are throttled per rendered page (a nonce in the signed token, not a value the browser picks), `ingest.daily_mb` caps what one person uploads in a day and `ingest.guest_daily_mb` what all guests do together, only a single complete gzip stream is accepted (no second member or trailing bytes behind the checked one), and a recording only takes batches from the same person, workspace and impersonator.
- **Storage.** Gzip chunks on any filesystem disk, the index in four tables on a configurable connection, `session-replay:prune` with `retention.days` and pinned recordings.
- **Watching.** `<x-session-replay::player>` and a built-in list and player behind the `viewSessionReplay` gate, which receives the recording and guards every data route; only the local environment is let in until the app defines it. `session-replay:install` publishes the provider with the gate. The player draws the mouse as a trail that fades along its length and marks every click with a ripple and a lingering dot, on the replay's clock (frozen on pause, in step with the speed, never drawn across a seek); `--sr-pointer` sets the colour.
- **Log context.** The running recording's id and URL in Laravel's `Context`, so log lines and error reports link to the replay.
- **Cached pages.** `ingest.token_days` (7) is how long the token a page was rendered with is accepted, and `ingest.guest_tokens_expire=false` lets a token that names nobody (no person, no workspace, no impersonator) live as long as the page cache that holds it.
- **Lists that follow the gate.** `SessionReplay::visibleUsing(fn (Builder $query, $viewer) => ...)` keeps recordings the gate would refuse out of lists, in SQL; `SessionReplay::visibleTo($query)` applies it on your own page.
- **Languages.** The viewer and the player in English, German, Spanish, Romanian and Russian, following the app's locale (`vendor:publish --tag=session-replay-translations`).
- `HasSessionReplays`, `ReplaySessionStarted`, `SessionReplay::tenantUsing()`, `impersonatorUsing()`, `propertiesUsing()`, `userUsing()`, `urlUsing()`.
