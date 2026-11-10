# Changelog

All notable changes to `packstub/session-replay` are documented here.

## Unreleased

### Security

- **Credentials only in the request body.** The upload endpoints (and the new Assist routes, all POSTs) read the signed token, the write key and an assist request's secret from the body only, never from the query string, which access logs, proxies and error trackers keep.

### Added

- **Assist: help requests, server side.** A visitor asks for help from the page (`POST assist/requests` with the recording's token, id and write key, and a message), someone from support takes the request from the new Requests page in the built-in viewer (`/session-replay/assist`) or its JSON queue, or offers help from a live recording (`assist/sessions/{session}/propose`), and the recorder hears of an offer in the upload answer and the new `assist/heartbeat` (`{assist: {id, agent}}`). The visitor proves the tab with a per-request secret (issued once, stored as its SHA-256), and the token: one that expired, belongs to another workspace or names someone else is refused or ends the request. With [`packstub/calls`](https://github.com/packstub/calls) installed (suggested), the visitor's page and the agent are put on a call of kind `assist` when the page acknowledges the claim or accepts the offer, and the visitor's poll is its Calls poll; without it the requests are a plain queue (open, claimed, ended by the agent). One request per recording at a time (per tab without one, by an id the recorder keeps per tab, so pages from a full-page cache that share a nonce still get one each), `assist.requests_per_hour` and `assist.max_open_requests` (per workspace) against floods, guests only while `assist.guests` is on, with a budget per workspace of their own (`assist.guest_requests_per_hour`, 20) and only from a recording with events, timeouts that run inside the polls, `SessionReplay::canAssistUsing()` asked on every agent endpoint (default: the gate; requests without a recording are nobody's while `visibleUsing()` narrows the list and no `canAssistUsing()` decides, and the agent's own workspace from `tenantUsing()` bounds what they see), the agent loaded and rung in the visitor's request from what the claim stored (their type, guard and Calls scope, so an agent on a panel's own guard is rung as themselves and their panel sees the call), claims refused while `packstub-calls.connection` differs from a set `storage.connection` (database per tenant), a call that fails half-way ended again and a live request whose call ended without the listener hearing it ended on the visitor's next poll, an agent who leaves the live view for longer than `packstub-calls.reconnect_timeout` keeping the request (it goes back to claimed without a call, the visitor's page says the agent is reconnecting, and a new call starts once the agent's stage is back; the claim lapses after `assist.claim_timeout_seconds`, an agent's own offer then ending as `agent_left`, `max_duration_minutes` counts from the first start; what the visitor allowed belongs to that agent and is revoked when another takes over), a recording from before write keys never kept open, attached to or knocked on by Assist (the visitor routes take it as no recording), the timeouts over the list run at most once every 5 seconds per workspace and only over the agent's workspace (`queue()` takes a workspace to narrow its query to), a grants log for what the agent asked for (`assist/requests/{id}/asks`) and what the visitor allowed, an agent who lets go of a claim putting the request back in the list (an offer taken back ends), `canAssist` asked again when the visitor accepts, the events `AssistRequested`, `AssistClaimed`, `AssistStarted` and `AssistEnded`, and an `assist` marker the server writes on the recording when a request finishes, on the recording's own clock, with how long each grant was on ("control 2 min, audio 45 s"). `lock_key` is a filtered unique index on SQL Server. New strings in all five languages. Off by default (`SESSION_REPLAY_ASSIST`); run `php artisan migrate` for the new `replay_assist_requests` table. See [Assist](docs/assist.md).
- **Assist on the visitor's page.** The recorder asks for help (`SessionReplay.assist.request({ message })`, `.cancel()`, `.end()`, `.status()`, a `session-replay:assist` event, and an optional floating "Get help" button, `assist.button`), answers an agent's offer (Accept / Not now) from the upload answer and a visible tab's heartbeat (`assist.heartbeat_seconds`), and polls its request on a Web Worker timer, so a hidden tab keeps its side of the call. With Calls, the visitor's half of the live view: the recorder's own events, masked like the recording, go to the agent over a WebRTC data channel (a full snapshot when it opens, stylesheets sent once), and the agent may ask for remote control (named cursor, dashed outline, the visitor's own input wins, Escape takes it back, never a password field, masked fields only with `assist.control_masked` and its own question), draw annotations that fade, and ask for audio, video and screen share, each its own question with a 30-second timeout, each answer reported to the grants log (the agent stopping the visitor's screen share too). The visitor stays in charge: a question takes the focus to its card, never to Allow, and its buttons take a press only half a second after it shows, so typing on the page cannot answer it; one question of a kind is up at a time, and after a no the agent waits 10 seconds before asking the same (refused with `reason: cooldown`); a microphone the visitor muted stays muted until their own Unmute (kept across a reload), and audio the agent ended comes back only after a new question. Remote control never fills a password field, also one a show-password button turned into text (rrweb's `data-rr-is-password`, `autocomplete` `current-password` / `new-password`), and acts only on what the agent can see: an element off-screen, hidden by CSS or inside `data-replay-skip`, `privacy.block_selector` or `.rr-block` is refused (`not_visible`, `blocked`) and counts as masked for typing. The visitor's UI is plain DOM in a closed shadow root, translated into the five languages, with no CSS framework. The channel protocol (`resources/js/lib/assist-protocol.js`: hello and version, 64 KB parts, backpressure that drops pointer samples first, a schema for every message and a budget for bad ones) is what the agent's stage speaks. The engine is `@packstub/calls`, in a module of its own (`assist.js`, 58 KB, 19 KB gzipped) that the recorder loads with `import()` from its own script route only when Assist is needed (an offer, a request going, the button, or `request()`); the recorder itself carries a hook of about 4 KB (the heartbeat, the knock, `SessionReplay.assist`) and imports the module only from the page's own origin. The visitor's code takes the calls engine by injection, so its node tests run without `node_modules`.
- **Assist, the agent's side in the built-in viewer.** A **Live now** tab in the recordings list (recordings with an upload or a heartbeat in the last two minutes, each saying when its page was last heard from, "last seen 40 s ago"; `ReplaySession::live()` and `lastSeenSeconds()`), **Offer help** on a live recording (with Calls), and the Requests page's Claim leading to the new **live stage** (`assist/requests/{id}/stage`, for the agent who holds the request; the gate, `visibleUsing()` and `canAssist` asked again). The stage plays the visitor's page live with rrweb's `Replayer` (no controller, rrweb's sandboxed iframe without scripts, the visitor's clock lined up on the first event, stylesheets sent apart put back, a fresh snapshot on every reconnect), runs its own Calls client over Calls' own routes (`kind=assist`, ICE servers from the `ice` route; register `Calls::routes()` in a plain app, `assist.calls_routes` for another name prefix; the stage says so when they are missing), and follows the request's status until it ends. Its tools: request and release control (the pointer, drags, the wheel and the arrow keys drive the visitor's page, a box over a clicked field types into it, a dashed frame while controlling), draw annotations, start audio (and video where Calls allows it) with a small audio bar, the visitor's screen as incoming video or the agent's own screen shared, withdraw and end. Every tool that cannot be used now is disabled with the reason under it, and the visitor's answers and refusals (a masked field, a password, a hidden tab, a no a moment ago) are listed as they come. Who holds a request is compared by key, model type and guard everywhere (the stage, the agent routes, the status poll, `mine`), so Admin #12 is not User #12, and the stage stops once the request is no longer the agent's. It polls Calls as often as Calls' `poll.active` says, caps stylesheets per channel (1000, 32 MB) and lets go of a page that sends more than it can take (4 MB/s, bursts of 48 MB; `too_fast`). Plain Blade and the package's own CSS, strings in all five languages, served as `stage.js` and `stage.css` from the script route. The workbench has Assist on, signs in an agent on `/agent`, and `npm run test:e2e` runs a visitor and an agent in two Chromium browsers through claim, mirror, control, annotation and audio, and through the agent leaving the live view and coming back.
- **The stage in a page of your own.** `stage.js` mounts on any `[data-session-replay-stage]` and only looks for `data-ref`, `data-action` and `data-why` inside it, so a host brings its own markup (Filament Session Replay renders the panel's buttons): a button's label goes into its `[data-label]` element when it has one, the root dispatches `session-replay:stage` after every change with the state and a one-word `phase` (`waiting`, `connecting`, `live`, `hidden`, `reconnecting`, `ended`, `unavailable`), and with `wire:navigate` the stage leaves the call on `livewire:navigating` (like an unload; the request stays the agent's) and mounts again on `livewire:navigated`. See [Hosting the stage](docs/assist.md#hosting-the-stage-in-a-page-of-your-own).
- **`data-replay-skip`.** An element carrying it is left out of the recording with everything inside it (no empty box as with `data-replay-block`), and so are the changes, input and clicks aimed at it.

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
