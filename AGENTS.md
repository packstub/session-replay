# packstub/session-replay

Session replay for a Laravel app, built on rrweb: a recorder served to the browser, an ingest endpoint, gzip chunks on a filesystem disk with an index in the database, and a small gated viewer. **Session Replay for Laravel** on packstub.dev. Free, MIT. `packstub/filament-session-replay` (the sibling repo, `plugins/filament-session-replay`) puts the sessions into a Filament panel and requires this package; the two share the `Packstub\SessionReplay\` namespace (Composer merges the PSR-4 directories), so class names must not collide across them. The plan and the reasoning behind the decisions live in `workspace/notes/session-replay/plugin-idea.md`.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, no Filament, no Livewire)
composer test:filter <name>
composer lint               # Pint
npm test                    # node --test over resources/js/lib (pure functions)
npm run build               # esbuild: resources/js -> resources/dist (committed)
composer serve              # Testbench workbench on :8000: a page to record, the viewer on /session-replay
```

## Layout

- `resources/js/recorder.js` — runs on recorded pages: session id per tab in `sessionStorage` (idle timeout, sticky sampling), rrweb `record()` with the privacy options, markers (errors, console, failed Livewire requests, vitals, rage clicks, navigation, custom), batching, gzip via `CompressionStream`, upload, retry, the plain cookie the log context reads. `resources/js/lib/process.js` — the pure part: attribute stripping, stylesheet slots, asset placeholders, restore; tested with `node --test`. `resources/js/player.js` + `player.css` — loads manifest, chunks and stylesheets, mounts `rrweb-player` and the marker list on `[data-session-replay-player]`.
- `resources/dist` — the built files, committed and served by `ScriptController` under `{path}/scripts/{file}`; an app never builds or publishes anything. Rebuild after every change in `resources/js`.
- `src/SessionReplayManager.php` + `Facades\SessionReplay` — what the app registered (`recordWhen`, `userUsing`, `tenantUsing`, `impersonatorUsing`, `propertiesUsing`, `urlUsing`), `recorder()` (the script tags with the config and the signed token), `check()` (the gate rule), URLs.
- `src/Support/ContextToken` — identity for ingest: user, tenant, impersonator and properties signed with the app key when the page renders. The ingest endpoint trusts only this, so it needs no session, cookie or CSRF token.
- `src/Http/Controllers/IngestController` + `src/Support/BatchIngester` — the upload endpoints and what a batch does to the index. Events are never parsed on the server; the browser sends the index next to them ("meta"). Gzip is inflated once with a ceiling, to keep a bomb away from the viewer's browser. Stylesheets are stored under the SHA-256 of their content, verified on upload.
- `src/Support/ReplayStorage` — paths and bytes on the disk (`sessions/{id}/{seq}.json.gz`, `assets/{aa}/{hash}.css.gz`).
- `src/Http/Middleware/AuthorizeViewer` — the `viewSessionReplay` gate on every viewer **and data** route, with the recording as argument when there is one; `ReplayDataController` (manifest, chunk, stylesheet), `ViewerController` (list, player page), `resources/views`.
- `src/Http/Middleware/AddReplayContext` — global middleware: recording id and viewer URL into Laravel's `Context`.
- `src/Models` — `ReplaySession`, `ReplayChunk`, `ReplayMarker`, `ReplayAsset` on `storage.connection`; `Concerns/HasSessionReplays`; `Events/ReplaySessionStarted`; `Commands/PruneCommand`.
- `stubs/SessionReplayServiceProvider.stub` — published by `session-replay:install` with the gate in it.
- `workbench/` — the Testbench app behind `composer serve`.
- `docs/` — customer docs; synced into the store under `session-replay/1.x` once the package is public (no docs-sync workflow until then).

## Conventions

- PHP 8.3+, Laravel 12 and 13. Every change needs a test and a `CHANGELOG.md` line.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- Nothing here may import from `Filament\` or `Livewire\`; what needs a panel belongs in `packstub/filament-session-replay`. The recorder may *detect* Livewire in the browser.
- Privacy defaults only ever get stricter inside a major. Every privacy control stays in this free package.
- Strings live in `resources/lang/{en,de,es,ro,ru}/viewer.php` and `player.php` (`session-replay::viewer.*`); the player gets its strings from the Blade component as `data-labels`. A new string goes into all five languages; `ViewerTest` compares the key sets.
- The gate guards data, not pages: any new route that returns recording content goes behind `AuthorizeViewer` with the recording bound.
- Never add identity to the ingest request other than the signed token; never key a limit by IP (edge proxies hide it).
- Config keys, migration file names, route names, the chunk format and the `/*sr-asset:<sha256>*/` placeholder are shared with the Filament package and with stored recordings; they must not change inside a major. Schema changes follow the workspace rule (final shape in `create_*`, guarded `add_*`).
- After a change here run the Filament package's suite too (`plugins/filament-session-replay`, `composer test`).
- After a change in `resources/js`, run the Filament package's browser check too: `cd ../filament-session-replay/workbench/lab && RECORDER=$PWD/../../../session-replay/resources/dist/recorder.js node navigate.mjs` (excluded pages, swaps, identity changes across `wire:navigate`; `CHANNEL=chrome` uses the installed Chrome). Unit tests cover the state machine, not the browser.
