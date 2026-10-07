# Storage

The bytes go to a filesystem disk, the index goes to the database.

## The disk

```php
'storage' => [
    'disk' => env('SESSION_REPLAY_DISK', 'local'),
    'directory' => 'session-replay',
],
```

```
session-replay/
├── sessions/
│   └── 0b1c6a0e-…/          one folder per recording
│       ├── 000000.json.gz   one file per uploaded batch, in upload order
│       └── 000001.json.gz
└── assets/
    ├── c8/
    │   └── c87f92ce….css.gz  a stylesheet, named by the SHA-256 of its content
    └── 4f/
        └── 4fa6c27f….json.gz a shared page snapshot, the same way (opt-in, see below)
```

Everything on the disk is gzip. A batch the browser compressed is stored as sent; one that arrived as plain JSON (an older browser, the final batch of a closing page) is compressed by the server, so the disk has one shape. Chunks, stylesheets and shared snapshots are served with `Content-Encoding: gzip`, so the viewer's browser inflates them and PHP only streams.

Use a private disk. On S3 or R2:

```php
// config/filesystems.php
'replays' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('SESSION_REPLAY_BUCKET'),
    'visibility' => 'private',
],
```

```dotenv
SESSION_REPLAY_DISK=replays
```

The files are always read through the package's gated routes, never through a public URL.

## The tables

| Table | One row per | What it holds |
| --- | --- | --- |
| `replay_sessions` | recording | Who (`user_type`, `user_id`), workspace (`tenant_type`, `tenant_id`), `impersonator_id`, `properties`, first URL, user agent, device, viewport, counters (`page_count`, `event_count`, `chunk_count`, `bytes`, `error_count`, `rage_click_count`, `active_ms`), worst vitals (`lcp_ms`, `inp_ms`, `cls`), `pinned`, `truncated`, `started_at`, `last_activity_at`. The id is the UUID the browser generated, stored in lower case. Indexed for a workspace's list by `tenant_type`, `tenant_id`, `started_at`. |
| `replay_chunks` | uploaded batch | `seq`, the file's `path`, `bytes`, `event_count`, first and last event time. Unique per recording and `seq`, which makes a retried upload harmless. A batch's chunk row, markers, counters and snapshot references are written in one transaction, so a batch that failed half way is stored in full when it is retried. |
| `replay_markers` | marker | `type`, `label`, `payload`, `at_ms`. This table is what lets a list filter without opening a file. |
| `replay_assets` | stylesheet or shared snapshot | `hash`, `kind` (`stylesheet` or `snapshot`), `path`, stored and raw size, `last_seen_at`. Shared by every recording that references it. A shared snapshot whose content is also sent as a stylesheet becomes a stylesheet, and recordings that point at it as a snapshot still get it. |
| `replay_session_assets` | shared snapshot a recording points at | `replay_session_id`, `hash`. What the viewer checks before it serves a shared snapshot, and what keeps one from being pruned. |

User and tenant keys are stored as strings, so integer, UUID and ULID keys all fit. Chunks, markers and snapshot references are removed with their recording by foreign key; deleting a `ReplaySession` model also deletes its folder on the disk.

## Multi-tenant apps

Tell the package which workspace a recording belongs to, and who is who:

```php
use Packstub\SessionReplay\Facades\SessionReplay;

SessionReplay::tenantUsing(fn ($request) => $request->user()?->currentTeam);
SessionReplay::impersonatorUsing(fn ($request) => session('impersonated_by'));
SessionReplay::propertiesUsing(fn ($request) => ['plan' => $request->user()?->currentTeam?->plan]);
SessionReplay::userUsing(fn ($request) => auth('customer')->user());
```

The closures run when the page renders, where the tenant is known (a subdomain, a path segment, a panel). Their results are signed into the token the recorder uploads with, so the ingest route needs no tenancy middleware.

In a **database-per-tenant** app, keep the tables on the central connection, so operators see every workspace in one place and no tenant database grows with recordings:

```dotenv
SESSION_REPLAY_DB_CONNECTION=central
```

```php
'storage' => ['connection' => env('SESSION_REPLAY_DB_CONNECTION')],
'run_migrations' => false,
```

With `run_migrations` off, publish the migrations and run them where your central migrations live:

```bash
php artisan vendor:publish --tag=session-replay-migrations
```

The models and the migrations both follow `storage.connection`. Keep the disk central too.

## Sizes

A full snapshot of a server-rendered page is mostly stylesheet. Four things keep recordings small:

- **Stylesheets are stored once.** The recorder replaces every stylesheet of `size.dedupe_min_bytes` (2048) or more with a reference to the SHA-256 of its content. The server says which hashes it does not have, and the browser uploads only those. The server checks that the content matches the hash, so a stylesheet can never be stored under a name another recording points at. The player puts the text back before it plays. A deploy that changes your CSS simply adds one file.
- **Attributes the player never uses are dropped** (`size.strip_attributes`): `wire:*`, `x-*`, `@*`, `:*`, `ax-load*`, except `size.keep_attributes` (`x-cloak` and Livewire's `wire:loading*`, `wire:offline*` and `wire:dirty*`, which stylesheets select on).
- **Comments, scripts and head metadata are left out** of snapshots.
- **Mouse, scroll, media and input events are sampled** (`size.sampling`).

Stylesheet deduplication and shared snapshots need `crypto.subtle`, which browsers provide on HTTPS and on `localhost`. Without it stylesheets stay inside the snapshots and everything else works the same.

### Shared snapshots

A full page load stores the whole page, even when the page looks the same for everyone who opens it. For pages like that you can store the snapshot once, the way stylesheets are:

```php
'snapshots' => [
    'share_routes' => ['pricing', 'docs.*', 'login'],
    'share_paths' => ['legal/*'],
],
```

On those pages the recorder hashes the snapshot after masking, attribute stripping and stylesheet deduplication, uploads it once per hash and keeps only the hash in the recording. The player puts the page back before it plays. A page that changes gets a new snapshot next to the old one, so older recordings keep the page they showed. Everything that happens after the page loaded (typing, clicks, a table filling in) is recorded as before. Only a page load is shared: a page reached with `wire:navigate` is stored inside the recording, because its snapshot is numbered after the pages before it and would never match.

Only pages that are identical for every visit share anything. Measured on the store's public pages and a Filament 5 panel (headless Chrome, two visitors, two loads each, the snapshot after the processing above):

| Page | Snapshot (gzip) | Identical across loads | Identical across people |
| --- | --- | --- | --- |
| Storefront home, plugin list, a plugin page, docs index, guides, privacy | 4 to 11 KB | yes | yes |
| A Filament sign-in page (the store's and a panel's) | 5 KB | yes | yes |
| Panel pages: a create form, a table, a kanban board | 11 to 30 KB | yes, with `volatile_ids` | no: the user menu shows the person's name and avatar |

What already keeps two loads apart is taken out before hashing: the CSRF token's meta tag (left out with the other head metadata), hidden inputs' values (CSRF `_token` included), `wire:id` and `wire:snapshot`, and rrweb's node ids, which are numbered in page order and come out the same for the same page. What is left to break a match is what differs on the page itself: a name, a flash message, a timestamp, a table of somebody's data, and ids a script makes up on every load (Filament's dropdown panels, renamed by `snapshots.volatile_ids`). Share a page only when it shows nothing about the person looking at it; a panel page shared anyway only saves space when the same person reloads it.

The upload says nothing about what is stored: the server checks the content against the hash and counts it against the daily limit whether it had the snapshot or not, and answers the same either way. The viewer serves a shared snapshot only for a recording that points at it, behind the gate. A browser sends each snapshot once an hour at most, and the final request of a closing page always carries its snapshot inline.

### What to expect

Measured on a Filament 5 panel (a table of 50 rows, a modal form, a create page with a rich editor and a file upload; headless Chrome, everything above switched on). Stored means gzip on the disk.

| What happens on the page | Stored |
| --- | --- |
| The first snapshot of the 50-row table page | 34 KB |
| The Filament stylesheet, once for every recording | 65 KB |
| The page open and nobody touching it | 0.2 KB per minute |
| The same table polling every 2 s (`wire:poll`), a cell changing in every row each time | 8 KB per minute |
| A search or a sort that re-renders the table | 10 to 30 KB each time, by how many rows come back |
| An edit modal (four fields) opened, filled in and saved, the table refreshing behind it | about 16 KB each time |
| A create page opened, a paragraph typed into the rich editor, a file uploaded | about 26 KB, 16 KB of it the page |
| Another page, as a full page load | 8 to 34 KB, the size of its snapshot |
| Another page with `wire:navigate` | the same: the recorder pauses while Livewire swaps the page and stores the new page as a snapshot |

A visit is mostly looking, so whole recordings come out far below the busiest rows: the store's own numbers are 38 KB for a 39-second visit across four storefront pages and 24 KB for two pages of a customer panel. The lab's scripted hands never rest (a search or a sort every two seconds stored 430 KB per minute, a modal every five seconds 160 KB per minute); nobody keeps that up, so read the table per action and let `retention.days` and `sample_rate` set the total.

### Planning disk and database

The recording itself lives on the disk; the database only holds the index, and it stays small:

| Table | A row per | About |
| --- | --- | --- |
| `replay_sessions` | recording | 0.4 KB |
| `replay_chunks` | upload (at most one every `flush_interval` while something happens, and one per page load or `wire:navigate` page) | 175 bytes |
| `replay_markers` | page view, vital, error, failed request, rage click | 155 bytes |
| `replay_assets` | distinct stylesheet or shared snapshot | 200 bytes |
| `replay_session_assets` | shared snapshot a recording points at | 100 bytes |

In the lab's navigation run (19 page loads in 46 seconds) that came to 55 chunk rows and 75 marker rows, about 21 KB of rows next to 311 KB on the disk.

A page costs about its snapshot, and reading it costs next to nothing, so a rough plan goes by pages. Someone opening four to six pages a minute stores about 65 to 100 KB per minute on the disk, with full page loads or `wire:navigate`, and about 7 KB per minute in the database. That is an estimate scaled from the lab, not a measurement of people: about 5 MB of disk and 0.4 MB of rows per hour of continuous use. With the default 30 days of retention, 100 active hours a month hold about 0.5 GB on the disk and 40 MB in the database. `sample_rate`, `recordWhen()` and `retention.days` are the three dials.

**On the person's side.** The recorder is 32 KB of JavaScript (gzip), loaded with `defer`. With the CPU slowed down four times, the same scripted work with and without the recorder gave the same interaction latency (75th percentile 40 ms and 32 ms with it, 64 ms and 40 ms without it, which is noise) and about one second more main-thread work over 50 seconds of constant clicking. Uploads are compressed off the main thread by the browser (`CompressionStream`).

The lab behind these numbers ships with the Filament package (`workbench/lab/measure.mjs`), so you can run it against your own pages.

## Limits

| Key | Default | What happens at the limit |
| --- | --- | --- |
| `ingest.max_batch_kb` | 1536 | The browser splits a batch that compresses to more; the server answers 413 to a larger upload. A gzip batch that inflates to more than 40 times this is refused (422). A shared snapshot has the same limits; one that is refused stays inside its batch. |
| `ingest.max_session_mb` | 50 | The recording is marked `truncated` and the recorder is told to stop. |
| `ingest.max_asset_kb` | 1536 | The limit on a stylesheet as sent (compressed); it may inflate to eight times this. A larger one is refused and stays missing in the replay. |
| `ingest.throttle` | 240 | Uploads per minute, per signed-in person, or per rendered page for guests (a value in the signed token). Never per IP address. The recorder backs off on a 429. `null` turns it off. |

**PHP's upload limit.** Both defaults stay under PHP's default `upload_max_filesize` of `2M`; a file above PHP's limit never reaches the package. Typical batches are tens of kilobytes and a typical stylesheet compresses to well under 100 KB. If your `upload_max_filesize` or `post_max_size` is lower than 2M, lower both keys to match; to raise them, raise PHP's limits first.

## Pruning

```bash
php artisan session-replay:prune            # retention.days (default 30)
php artisan session-replay:prune --days=7
```

- Deletes recordings whose last activity is older than the window, one by one, with their files.
- Keeps pinned recordings: `$session->forceFill(['pinned' => true])->save()`.
- Deletes stylesheets that were last referenced before the window and before the oldest recording that is left (a pinned one included), so a kept recording never loses its styling.
- Deletes shared snapshots no recording that is left points at, a day after they were last sent. Sending one again moves that date once it is an hour old, so the batch that points at it always has at least 23 hours to arrive.
- Refuses a window under one day.

Schedule it daily:

```php
Schedule::command('session-replay:prune')->daily();
```
