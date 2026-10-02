# Session Replay for Laravel

<div class="filament-hidden">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/session-replay)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/session-replay/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/session-replay/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/session-replay.svg?style=flat-square)](https://packagist.org/packages/packstub/session-replay)
[![License](https://img.shields.io/packagist/l/packstub/session-replay.svg?style=flat-square)](https://github.com/packstub/session-replay/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

Record what a person did in the browser, keep the recording on your own disk and database, and watch it inside your own app, behind a gate you define. Built on [rrweb](https://github.com/rrweb-io/rrweb). Free and open source (MIT).

"The form did nothing when I clicked save" becomes a 40-second replay with the console error and the failed Livewire request marked on the timeline.

> **Beta.** 1.0 is close and feedback is very welcome in the [issues](https://github.com/packstub/session-replay/issues). Until 1.0, names and config may still change between betas; the changelog says how to upgrade.

- Docs: [docs/](https://github.com/packstub/session-replay/tree/main/docs)
- Support: [GitHub issues](https://github.com/packstub/session-replay/issues)

## Features

- **[One Blade directive](#quick-start)**: `@sessionReplay` before `</body>` records the page, with nothing to build or publish.
- **[Private by default](#privacy-defaults)**: every input masked, no IP address stored, an anonymous mode, sensitive pages left out.
- **[Recordings stay with you](docs/storage.md)**: gzip chunks on any Laravel disk, the index in four tables on your connection.
- **[Markers on the timeline](docs/recording.md#markers)**: errors, failed Livewire requests, web vitals, rage clicks, page views and your own moments.
- **[Small recordings](docs/storage.md#sizes)**: stylesheets stored once, unused attributes dropped, batches gzipped in the browser.
- **[A gate decides who watches](docs/watching.md#the-gate)**: `viewSessionReplay` guards the list, the player and every file behind them.
- **[A link in every log line](docs/error-tracking.md)**: the recording's id and URL in Laravel's `Context`, so error reports point at the replay.
- **[Multi-tenant aware](docs/storage.md#multi-tenant-apps)**: a recording stays in one workspace, and the tables can live on your central connection.
- **[Friendly to cached pages](docs/recording.md#cached-pages)**: the recorder's token can outlive a full-page cache.
- **Five languages**: the viewer and the player in English, German, Spanish, Romanian and Russian.

## Quick start

```bash
composer require "packstub/session-replay:^1.0@beta"
php artisan session-replay:install
```

Put the recorder in the layouts you want recorded:

```blade
    @sessionReplay
</body>
```

Decide who may watch, in the `app/Providers/SessionReplayServiceProvider.php` the installer published:

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $user->is_admin;
});
```

Schedule the clean-up in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('session-replay:prune')->daily();
```

Sign in, click around, then open `/session-replay`.

## How it works

1. **Recorder.** `@sessionReplay` renders a small config object and a deferred script. The script records the DOM and what happens to it with rrweb, one recording per browser tab, continued across page loads until the tab sits idle.
2. **Signed token.** When the page renders, the server signs who is signed in, the workspace, an impersonator and your own properties with the app key. The recorder sends that token back with every upload, so the ingest endpoint needs no session, no cookie and no CSRF token.
3. **Ingest.** Every few seconds the recorder uploads a gzip batch with a small index next to it (markers, counts, the stylesheets the batch references). The server stores the file and updates the index; it never parses the events.
4. **Storage.** Chunks go to `sessions/{id}/{seq}.json.gz` on your disk, stylesheets to `assets/` under the hash of their content, the index to `replay_sessions`, `replay_chunks`, `replay_markers` and `replay_assets`.
5. **Viewer.** `/session-replay` lists recordings; the player loads the manifest, the chunks and the stylesheets through routes that check the `viewSessionReplay` gate for that recording on every request.

## Privacy defaults

| Default | Setting |
| --- | --- |
| Every input masked, passwords always | `privacy.mask_all_inputs` |
| Guests are not recorded | `guests` |
| No IP address stored | not configurable |
| Livewire component state (`wire:snapshot`) never enters a recording | `size.strip_attributes` |
| Recordings deleted after 30 days | `retention.days` |
| Nobody can watch outside `local` until the gate exists | `viewSessionReplay` |

See [Privacy](docs/privacy.md) for masking, consent and wording for your privacy policy.

## Documentation

| Guide | What it covers |
| --- | --- |
| [Installation](docs/installation.md) | Requirements, the install command, the directive, the gate, the scheduler |
| [Recording](docs/recording.md) | The directive and its options, who is recorded, sessions and sampling, markers, Livewire, CSP |
| [Privacy](docs/privacy.md) | What is recorded, masking and blocking, consent, retention, policy wording |
| [Watching replays](docs/watching.md) | The gate, the built-in viewer, the player component, models and scopes |
| [Storage](docs/storage.md) | Disk layout, tables, multi-tenant apps, sizes and limits, pruning |
| [Error tracking](docs/error-tracking.md) | The recording's id and URL in Laravel's `Context` |
| [Configuration](docs/configuration.md) | Every key in `config/session-replay.php` |

## In a Filament panel

[packstub/filament-session-replay](https://github.com/packstub/filament-session-replay/tree/main/docs) puts the sessions inside a Filament v5 panel: a filterable resource, the player with timeline tabs, a relation manager for your users, masking declared on the form field. It requires this package and records through it.

## Alongside other tools

PostHog, Sentry, Microsoft Clarity and OpenReplay are great choices for product analytics, error monitoring and replay as a platform. This package is for replay inside your own app, next to your own models, on your own storage. They work well together.

## Testing and development

```bash
composer test     # Pest
composer lint     # Pint
npm test          # the recorder's pure functions
npm run build     # resources/js -> resources/dist
composer serve    # a page to record and the viewer, on :8000
```

## Credits

- [rrweb](https://github.com/rrweb-io/rrweb) (MIT) records and replays the DOM.
- [web-vitals](https://github.com/GoogleChrome/web-vitals) (Apache-2.0) measures LCP, INP and CLS.

## License

MIT. See [LICENSE.md](LICENSE.md).
