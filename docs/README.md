# Session Replay for Laravel

Record what a person did in the browser with rrweb, keep the recording on your own disk and database, and watch it inside your own app behind a gate you define. One Blade directive records; nothing has to be built or published. Free and open source (MIT).

- Repository: [github.com/packstub/session-replay](https://github.com/packstub/session-replay)
- Packagist: [packstub/session-replay](https://packagist.org/packages/packstub/session-replay)
- Support: [GitHub issues](https://github.com/packstub/session-replay/issues)

In a Filament panel, [Filament Session Replay](https://github.com/packstub/filament-session-replay/tree/main/docs) puts the sessions, the player and the masking macros on top of this package.

## Features

- **[One Blade directive](recording.md#the-directive)**: `@sessionReplay` before `</body>` records the page, with nothing to build or publish.
- **[Private by default](privacy.md)**: every input masked, no IP address stored, consent and an anonymous mode when you need them.
- **[Recordings stay with you](storage.md)**: gzip chunks on any filesystem disk (local, S3, R2), the index in four tables on your connection.
- **[Markers on the timeline](recording.md#markers)**: errors, failed Livewire requests, web vitals, rage clicks, page views and your own moments.
- **[Small recordings](storage.md#sizes)**: stylesheets stored once, unused attributes dropped, batches gzipped in the browser.
- **[A gate decides who watches](watching.md#the-gate)**: `viewSessionReplay` guards the list, the player and every file behind them.
- **[Identity without a session](recording.md#who-is-recorded)**: who is signed in travels in a signed token, so any guard and any tenancy setup works.
- **[A link in every log line](error-tracking.md)**: the recording's id and URL in Laravel's `Context`.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the install command, the directive, the gate, the scheduler, the routes |
| [Recording](recording.md) | The directive and its options, who is recorded, sessions and sampling, markers, the browser API, Livewire, CSP, known limits |
| [Privacy](privacy.md) | What is and is not recorded, masking and blocking, consent, guests, retention, wording for a privacy policy |
| [Watching replays](watching.md) | The `viewSessionReplay` gate, the built-in viewer, the player component, links, models and scopes |
| [Storage](storage.md) | Disk layout, the four tables, multi-tenant apps, sizes and limits, pruning, S3 |
| [Error tracking](error-tracking.md) | The recording in Laravel's `Context`, and where it shows up |
| [Configuration](configuration.md) | Every key in `config/session-replay.php` |
