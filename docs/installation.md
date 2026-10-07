# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer, with `ext-zlib` |
| Laravel | 12.x or 13.x |
| Browser (recorded pages) | Any current browser. `CompressionStream` and `crypto.subtle` are used when present; without them batches upload uncompressed and stylesheets stay inline. |

The package has no front-end build step. The recorder and the player ship prebuilt and are served by a route.

**In a Filament panel**, install [packstub/filament-session-replay](https://github.com/packstub/filament-session-replay/blob/main/docs/installation.md) instead. It requires this package and registers the recorder per panel. Everything below applies there too.

## Install

Recordings and their index go to your default database connection. If they belong somewhere else (a central connection in a multi-tenant app), set `SESSION_REPLAY_DB_CONNECTION` first, because the install command offers to run the migrations; see [Storage](storage.md#multi-tenant-apps).

```bash
composer require "packstub/session-replay:^1.0@beta"
php artisan session-replay:install
```

The install command:

1. publishes `config/session-replay.php`,
2. offers to run the migrations (five tables: `replay_sessions`, `replay_chunks`, `replay_markers`, `replay_assets`, `replay_session_assets`),
3. publishes `app/Providers/SessionReplayServiceProvider.php` with the `viewSessionReplay` gate in it and registers it in `bootstrap/providers.php`.

Migrations also run with a plain `php artisan migrate`; set `run_migrations` to `false` to publish and run them yourself (see [Storage](storage.md#multi-tenant-apps)).

## Record a page

Put the directive before `</body>` in every layout you want recorded:

```blade
        @sessionReplay
    </body>
</html>
```

It renders nothing when the request is not recorded: recording turned off, a guest while `guests` is `false`, an excluded path, or a `recordWhen()` callback that said no. See [Recording](recording.md).

**Inertia, Vue or React:** put it in the root template that every page renders through (`resources/views/app.blade.php` with Inertia), before `</body>`. The recorder stays loaded while the router changes pages.

## Decide who may watch

Until your app defines the `viewSessionReplay` gate, the viewer answers only in the `local` environment. Edit the published provider:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Packstub\SessionReplay\Models\ReplaySession;

class SessionReplayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
            return in_array($user->email, [
                'support@example.com',
            ], true);
        });
    }
}
```

The gate is called without a recording for the list and with it for a single replay and every file behind it. [Watching replays](watching.md) has examples.

## Schedule the clean-up

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('session-replay:prune')->daily();
```

It deletes recordings older than `retention.days` (30 by default), except pinned ones, and the stylesheets nothing references any more.

## Try it

Sign in, open a recorded page, click around for a few seconds, then open `/session-replay`. The recorder uploads every five seconds and when the tab is hidden.

## Before production

- **A disk every server sees.** The default `local` disk is fine on one server. With several servers, Laravel Cloud, Vapor or containers that are replaced on deploy, set `SESSION_REPLAY_DISK` to a private shared disk (S3, R2); see [Storage](storage.md#the-disk).
- **The gate.** Outside `local` the viewer answers 403 until `viewSessionReplay` lets someone in.
- **The clean-up** is scheduled (above).
- **Your privacy policy** says what is recorded; [Privacy](privacy.md#wording-for-your-privacy-policy) has wording to start from.

## When nothing shows up

Work through these in order:

1. **Is the recorder on the page?** View the page source and look for `window.__sessionReplay`. If it is missing, the request was not recorded: a guest while `guests` is `false`, a path in `except`, a route in `except_routes`, `recordWhen()` saying no, or `SESSION_REPLAY_ENABLED=false`. The directive must also be in the layout that page uses.
2. **Does the browser upload?** In the network tab, look for `POST /session-replay/ingest` a second or so after the page loads.
   - **No request:** check the following.
     - `consent` is `opt-in` and nobody called `SessionReplay.consent(true)`.
     - The browser sends Global Privacy Control while `respect_gpc` is on.
     - `sample_rate` is below 1.
     - The tab is in mode `on_error` and nothing went wrong yet. `SessionReplay.isRecording()` and `isBuffering()` in the console tell you which.
   - **A request the browser blocked** (`ERR_BLOCKED_BY_CLIENT`): a content blocker. Change `path` to something neutral.
   - **401:** the page's token expired or `APP_KEY` changed. Reload.
   - **419:** something added `web` to `ingest.middleware`. The endpoint needs no session or CSRF token; leave the list empty.
   - **422 "Nothing was uploaded":** the batch was over PHP's `upload_max_filesize` or `post_max_size`, so PHP dropped it. **413:** it was over `ingest.max_batch_kb`. See [PHP upload limit](#php-upload-limit).
   - **429:** the per-minute throttle or the person's daily allowance; the recorder backs off. See [Storage](storage.md#limits).
3. **Does the viewer let you in?** A 403 on `/session-replay` outside `local` means the gate said no. A redirect to `/login` means `viewer.middleware` wants a login your app does not have on that guard; see [Watching replays](watching.md#the-built-in-viewer).

On a site served over plain `http://` (other than `localhost`), browsers withhold `crypto.subtle`. Recording still works, with each stylesheet stored inside the snapshot instead of once per content.

## The routes

Everything lives under one prefix (`path`, default `session-replay`; `domain` optional):

| Route name | Method and URI | Middleware |
| --- | --- | --- |
| `session-replay.script` | `GET {path}/scripts/{file}` (`recorder.js`, `player.js`, `player.css`) | none |
| `session-replay.ingest` | `POST {path}/ingest` | `ingest.middleware`, `throttle:session-replay` |
| `session-replay.ingest.asset` | `POST {path}/ingest/asset` | same |
| `session-replay.ingest.snapshot` | `POST {path}/ingest/snapshot` | same |
| `session-replay.index` | `GET {path}` | `viewer.middleware`, the gate |
| `session-replay.show` | `GET {path}/{session}` | same |
| `session-replay.manifest` | `GET {path}/{session}/manifest` | same |
| `session-replay.chunk` | `GET {path}/{session}/chunks/{seq}` | same |
| `session-replay.asset` | `GET {path}/{session}/assets/{hash}` | same |
| `session-replay.snapshot` | `GET {path}/{session}/snapshots/{hash}` | same |

The script URLs carry a version, so browsers cache them for a year and pick up a new release on the next page load.

`viewer.enabled = false` removes `index` and `show` and keeps the data routes, which the player component needs.

## PHP upload limit

A batch is at most `ingest.max_batch_kb` (1536 KB by default), which stays under PHP's default `upload_max_filesize` of `2M`. Batches are normally a few dozen kilobytes; if your PHP limit is lower than 2M, lower `max_batch_kb` to match. See [Storage](storage.md#limits).
