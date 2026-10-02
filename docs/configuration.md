# Configuration

`php artisan session-replay:install` publishes `config/session-replay.php`; `php artisan vendor:publish --tag=session-replay-config` does the same on its own. Your file is merged over the package defaults key by key, so it may contain only what you change. A list you set (`except`, `except_routes`, `capture.console`, `size.strip_attributes`, `size.keep_attributes`, `ingest.middleware`, `viewer.middleware`) replaces the default list as a whole, so `[]` really turns a list off.

## Recording

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `env('SESSION_REPLAY_ENABLED', true)` | The master switch. Off: the directive renders nothing and ingest refuses uploads. |
| `sample_rate` | `env('SESSION_REPLAY_SAMPLE_RATE', 1.0)` | Share of recordings kept, decided once per recording in the browser. |
| `guests` | `env('SESSION_REPLAY_GUESTS', false)` | Record people who are not signed in. |
| `except` | password-reset and verification pages | Request paths (`Str::is` patterns) that never get the recorder. The package's own pages are always left out. The default list covers `*password-reset*`, `*reset-password*`, `*forgot-password*`, `password/*`, `*email-verification*`, `*verify-email*` and `email/verify*`; a list you set replaces it, so keep those in yours. |
| `except_routes` | password-reset and verification routes | Route names (`Str::is` patterns) that never get the recorder, whatever their URL. The default list covers `password.*` and `verification.*` (Laravel's starter kits) and `filament.*.auth.password-reset.*`, `filament.*.auth.email-verification.*` and `filament.*.auth.email-change-verification.*` (Filament panels). |
| `idle_timeout` | `30` | Minutes without activity after which a tab starts a new recording. |
| `flush_interval` | `5000` | Milliseconds between uploads while the page is open (minimum 1000). |

## Consent

| Key | Default | Meaning |
| --- | --- | --- |
| `consent` | `env('SESSION_REPLAY_CONSENT', 'always')` | `always` or `opt-in` (waits for `SessionReplay.consent(true)`). |
| `respect_gpc` | `false` | Do not record browsers that send Global Privacy Control. |

## Privacy

| Key | Default | Meaning |
| --- | --- | --- |
| `privacy.mask_all_inputs` | `true` | Mask every input's value. Password inputs are masked regardless. |
| `privacy.mask_all_text` | `false` | Mask every text node: layout-only recordings. |
| `privacy.mask_text_selector` | `[data-replay-mask], [data-replay-mask] *, [contenteditable], [contenteditable] *` | Elements whose text is replaced with asterisks. Rich editors are in the default, so what people type there is masked like an input. |
| `privacy.block_selector` | `[data-replay-block]` | Elements recorded as an empty box of the same size. |
| `privacy.ignore_selector` | `[data-replay-ignore]` | Elements whose input events are not recorded. |
| `privacy.anonymous` | `false` | Record signed-in people without storing who they are (no person, no impersonator; the workspace stays). Uploads are still limited per person. See [Privacy](privacy.md#recordings-that-name-nobody). |
| `privacy.store_user_agent` | `true` | `false` drops the user agent; the device class is kept. |
| `privacy.redact_query` | `token`, `access_token`, `refresh_token`, `id_token`, `signature`, `code`, `state`, `password`, `secret`, `key`, `api_key`, `email` | Query parameters whose value is replaced with `redacted` in every URL the recorder sends. |

## Capture

| Key | Default | Meaning |
| --- | --- | --- |
| `capture.console` | `['error']` | Console levels recorded; `console.error` also becomes a `console` marker. Other levels you add are kept in the recording's events without a marker. `[]` turns the console off. |
| `capture.errors` | `true` | Uncaught errors and unhandled rejections as `error` markers. |
| `capture.livewire` | `true` | Failed Livewire requests as `request` markers, `wire:navigate` page views as `navigation` markers. |
| `capture.vitals` | `true` | LCP, INP and CLS as `vital` markers. |
| `capture.rage_clicks` | `true` | Three or more clicks on one spot within 700 ms as `rage-click` markers. |

## Size

| Key | Default | Meaning |
| --- | --- | --- |
| `size.dedupe_stylesheets` | `true` | Store stylesheets once per SHA-256 instead of inside every snapshot. |
| `size.dedupe_min_bytes` | `2048` | Smaller stylesheets stay inline. |
| `size.strip_attributes` | `['wire:*', 'x-*', '@*', ':*', 'ax-load*']` | Attribute names dropped before upload; `*` is a trailing wildcard. |
| `size.keep_attributes` | `['x-cloak', 'wire:loading*', 'wire:offline*', 'wire:dirty*']` | Names (or `prefix*`) that stay even when a pattern matches: what stylesheets select on. |
| `size.sampling` | `['mousemove' => 50, 'scroll' => 150, 'media' => 800, 'input' => 'last']` | rrweb's sampling options: milliseconds between recorded mouse moves, scrolls and media events; `input: 'last'` keeps the final value of a burst. |

## Routes

| Key | Default | Meaning |
| --- | --- | --- |
| `path` | `session-replay` | Prefix of every package route. |
| `domain` | `null` | Restrict the routes to one domain. |
| `ingest.middleware` | `[]` | Extra middleware for the two upload routes. None is needed: identity comes from the signed token. |
| `ingest.throttle` | `240` | Uploads per minute per person (for guests, per rendered page: a value in the signed token, not one the browser picks). `null` turns it off. |
| `ingest.daily_mb` | `250` | Megabytes one person may upload per day, as sent. A day of steady work in a panel is well under 100 MB. `null` turns it off. |
| `ingest.guest_daily_mb` | `2048` | Megabytes all guests together may upload per day. Guests cannot be told apart, so this is the cap that keeps a script from filling the disk. `null` turns it off. |
| `ingest.max_batch_kb` | `1536` | Largest upload accepted, as sent. |
| `ingest.max_session_mb` | `50` | A recording stops growing here and is marked truncated. |
| `ingest.max_asset_kb` | `1536` | Largest stylesheet accepted, as sent (compressed). |
| `ingest.token_days` | `7` | Days the token a page was rendered with is accepted. Keep it longer than your full-page cache (see [Cached pages](recording.md#cached-pages)). |
| `ingest.guest_tokens_expire` | `true` | `false`: a token that names nobody (no person, no workspace, no impersonator) never expires. |

## Storage

| Key | Default | Meaning |
| --- | --- | --- |
| `storage.disk` | `env('SESSION_REPLAY_DISK', 'local')` | Filesystem disk for chunks and stylesheets. |
| `storage.directory` | `session-replay` | Folder on that disk. |
| `storage.connection` | `env('SESSION_REPLAY_DB_CONNECTION')` | Database connection of the four tables; `null` is the default connection. |
| `run_migrations` | `true` | Run the package migrations with `php artisan migrate`. |
| `retention.days` | `30` | What `session-replay:prune` keeps. |

## Viewer

| Key | Default | Meaning |
| --- | --- | --- |
| `viewer.enabled` | `true` | The built-in list and player page. The data routes stay either way. |
| `viewer.middleware` | `['web', 'auth']` | Runs before the `viewSessionReplay` gate on pages and data routes. |
| `viewer.guard` | `null` | The guard whose user is handed to the gate. |
| `viewer.per_page` | `25` | Recordings per list page. |
| `viewer.user_label` | `email` | Attribute shown for a person in the list. |

## Log context

| Key | Default | Meaning |
| --- | --- | --- |
| `context.enabled` | `true` | Write the cookie and add `session_replay` / `session_replay_url` to Laravel's `Context`. |
| `context.cookie` | `session_replay_id` | The cookie's name. |

## What is registered in code

| Call | Purpose |
| --- | --- |
| `Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => ...)` | Who may watch. |
| `SessionReplay::recordWhen(fn ($user, $request) => ...)` | Narrow who is recorded. |
| `SessionReplay::userUsing(fn ($request) => ...)` | The signed-in person, when not the default guard's. |
| `SessionReplay::tenantUsing(fn ($request) => ...)` | The workspace of a recording. |
| `SessionReplay::impersonatorUsing(fn ($request) => ...)` | The impersonator's key. |
| `SessionReplay::propertiesUsing(fn ($request) => [...])` | Anything else to keep on the recording. |
| `SessionReplay::urlUsing(fn (ReplaySession $session) => ...)` | Where replays are watched, when not the built-in viewer. |
| `SessionReplay::visibleUsing(fn (Builder $query, $viewer) => ...)` | Narrow the recordings a viewer finds in a list. |

## Languages

The viewer and the player ship in English, German, Spanish, Romanian and Russian and follow the app's locale. To change a string or add a language, publish the files:

```bash
php artisan vendor:publish --tag=session-replay-translations
```

They land in `lang/vendor/session-replay/{locale}/viewer.php` and `player.php`. The player reads its strings from the `<x-session-replay::player>` component, so a page that embeds it is translated too.

## Commands

| Command | Purpose |
| --- | --- |
| `session-replay:install` | Publish the config, offer to migrate, publish the provider with the gate. |
| `session-replay:prune {--days=}` | Delete old recordings and unreferenced stylesheets. |
