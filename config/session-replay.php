<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | The master switch, the share of browser sessions that are recorded
    | (decided once per session, in the browser) and whether people who are
    | not signed in are recorded at all. SessionReplay::recordWhen() narrows
    | it further per request.
    |
    */

    'enabled' => env('SESSION_REPLAY_ENABLED', true),

    'sample_rate' => (float) env('SESSION_REPLAY_SAMPLE_RATE', 1.0),

    'guests' => (bool) env('SESSION_REPLAY_GUESTS', false),

    // Request paths (Str::is patterns) that never get the recorder. The package's own pages are always left out,
    // and so are, by default, the pages that carry a password-reset or verification link in their URL.
    'except' => [
        '*password-reset*',
        '*reset-password*',
        '*forgot-password*',
        'password/*',
        '*email-verification*',
        '*verify-email*',
        'email/verify*',
    ],

    // Route names (Str::is patterns) that never get the recorder, next to the paths above: the same pages by the
    // name Laravel's starter kits and Filament's panels give them, whatever their URL.
    'except_routes' => [
        'password.*',
        'verification.*',
        'filament.*.auth.password-reset.*',
        'filament.*.auth.email-verification.*',
        'filament.*.auth.email-change-verification.*',
    ],

    // Minutes without activity after which the browser starts a new recording.
    'idle_timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | "session": a recorded tab uploads from its first page. "on_error": the
    | browser keeps only the last moments in memory and uploads nothing until
    | something goes wrong (on_error.triggers); then it uploads those moments
    | and records the rest of the tab's session as usual. A full page load
    | while waiting starts the memory over, so the window holds the pages a
    | tab went through with wire:navigate, not the ones before a reload.
    |
    */

    'mode' => env('SESSION_REPLAY_MODE', 'session'),

    'on_error' => [
        // Seconds kept before the trigger (5 to 600). The window starts at a full page snapshot, taken every half
        // window (at most every 30 s), so a replay starts up to that much earlier.
        'buffer_seconds' => 60,
        // Marker types that upload the window: error, request (a failed Livewire request), console, rage-click,
        // custom (SessionReplay.mark()). A type must be captured too (capture.*) to fire.
        'triggers' => ['error', 'request'],
        // Ask the person first, in a small dialog the recorder draws: send the replay or not, and, when someone is
        // signed in, without their name. A "no" drops what was kept and leaves the tab alone until its session ends.
        'ask' => false,
        // Keep the uploaded window in the browser (IndexedDB) while its upload fails, so a page reload during the
        // outage does not lose it: the tab's next page load sends it first. Dropped after idle_timeout, when the
        // person changes or when the tab stops. false keeps nothing recorded at rest in the browser.
        'keep_pending' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent
    |--------------------------------------------------------------------------
    |
    | "always": recording starts with the page (a product used under a
    | contract that covers it). "opt-in": the recorder loads but waits for
    | window.SessionReplay.consent(true), and remembers the answer.
    |
    */

    'consent' => env('SESSION_REPLAY_CONSENT', 'always'),

    // Leave people alone who send the Global Privacy Control signal.
    'respect_gpc' => false,

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Inputs are masked by default and password inputs always are. Anything
    | matching mask_text_selector has its text replaced with asterisks,
    | anything matching block_selector is recorded as an empty box of the
    | same size, anything matching ignore_selector records no input events.
    | mask_all_text records layout only. Text typed into rich editors
    | (contenteditable) is masked like an input. The query parameters in
    | redact_query keep their name and lose their value in every URL the
    | recorder sends, links and form actions in the page included.
    |
    */

    'privacy' => [
        'mask_all_inputs' => true,
        'mask_all_text' => false,
        'mask_text_selector' => '[data-replay-mask], [data-replay-mask] *, [contenteditable], [contenteditable] *',
        'block_selector' => '[data-replay-block]',
        'ignore_selector' => '[data-replay-ignore]',
        // true: recordings are not linked to the signed-in person. Who is signed in still decides whether a page is
        // recorded (guests, recordWhen()), and a change of person still starts a new recording, but no person and no
        // impersonator are stored; the workspace and your properties stay. Signed-in people are recorded with
        // guests off, and their uploads are limited per person like anyone else's.
        'anonymous' => false,
        // false: the browser's user agent is not stored; the device class (desktop, tablet, mobile) still is.
        'store_user_agent' => true,
        'redact_query' => ['token', 'access_token', 'refresh_token', 'id_token', 'signature', 'code', 'state', 'password', 'secret', 'key', 'api_key', 'email'],
    ],

    /*
    |--------------------------------------------------------------------------
    | What is captured besides the DOM
    |--------------------------------------------------------------------------
    */

    'capture' => [
        // console.* levels recorded with the session ("error" calls become "console" markers); [] turns the console off.
        'console' => ['error'],
        // Uncaught errors and unhandled promise rejections as "error" markers.
        'errors' => true,
        // Failed Livewire requests as "request" markers (when Livewire is on the page).
        'livewire' => true,
        // LCP, INP and CLS as "vital" markers.
        'vitals' => true,
        // Three or more clicks on the same spot within 700 ms.
        'rage_clicks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Size
    |--------------------------------------------------------------------------
    |
    | Stylesheets are stored once per content hash instead of inside every
    | snapshot, and attributes the player never uses (Livewire snapshots,
    | Alpine expressions) are dropped. Both only change what is stored, not
    | what the replay looks like.
    |
    */

    'size' => [
        'dedupe_stylesheets' => true,
        // Stylesheets smaller than this many bytes stay inline.
        'dedupe_min_bytes' => 2048,
        // Attribute names to drop; * is a wildcard. What a stylesheet selects on stays: x-cloak, and the
        // attributes Livewire hides loading, offline and dirty indicators by (or every spinner would show).
        'strip_attributes' => ['wire:*', 'x-*', '@*', ':*', 'ax-load*'],
        'keep_attributes' => ['x-cloak', 'wire:loading*', 'wire:offline*', 'wire:dirty*'],
        // rrweb sampling: mousemove and scroll in ms, input "last" keeps the final value of a burst.
        'sampling' => [
            'mousemove' => 50,
            'scroll' => 150,
            'media' => 800,
            'input' => 'last',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared snapshots
    |--------------------------------------------------------------------------
    |
    | Pages that look the same for everyone who opens them (a pricing page,
    | the docs, a sign-in form) can have their snapshot stored once per
    | SHA-256 of its content, like a stylesheet, instead of once per page
    | view. Opt in per route name or path (Str::is patterns); a page that
    | shows anything personal never matches another page view anyway, and
    | only belongs here if the same person reloads it a lot.
    |
    */

    'snapshots' => [
        'share_routes' => [],
        'share_paths' => [],
        // Ids a script makes up on every page load (prefix*): renamed in order in a shared snapshot, so two loads of
        // the same page still match. Filament's dropdown panels get one each.
        'volatile_ids' => ['fi-dropdown-panel-*'],
    ],

    // Milliseconds between uploads while the page is open.
    'flush_interval' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Everything lives under one prefix: the recorder and player scripts,
    | the ingest endpoints (ingest.middleware) and the viewer
    | (viewer.middleware plus the viewSessionReplay gate).
    |
    */

    'path' => 'session-replay',

    'domain' => null,

    'ingest' => [
        // Extra middleware for the upload routes. None is needed, and "web" would ask for a CSRF token
        // the recorder does not send: identity comes from the token the page was rendered with.
        'middleware' => [],
        // Requests per minute per person (for guests: per rendered page); null turns the limiter off.
        'throttle' => 240,
        // Megabytes one person may upload per day, as sent (compressed); null turns it off. A day of steady
        // work in a panel is well under 100 MB.
        'daily_mb' => 250,
        // Megabytes all guests together may upload per day: guests cannot be told apart, so this is the cap
        // that keeps a script from filling the disk. Raise it for a busy public site; null turns it off.
        'guest_daily_mb' => 2048,
        // Largest upload the endpoint accepts, as sent (compressed), in kilobytes. Keep it under PHP's
        // upload_max_filesize (2M by default), or PHP drops the file before the package sees it.
        'max_batch_kb' => 1536,
        // A recording stops growing here; the browser is told to stop.
        'max_session_mb' => 50,
        // Largest stylesheet upload, as sent (compressed); it may inflate to eight times this.
        'max_asset_kb' => 1536,
        // Days the token a page was rendered with stays valid. A page served from a full-page cache carries
        // the token it was cached with, so keep this longer than the cache's lifetime.
        'token_days' => 7,
        // false: a token that names nobody (no person, no workspace, no impersonator) never expires, for guest
        // pages behind a long-lived cache. Such uploads are still throttled, size-limited and need guests on.
        'guest_tokens_expire' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Event chunks and stylesheets go to a filesystem disk as gzip files; the
    | index (sessions, chunks, markers, assets) goes to the database. Point
    | connection at your central connection in a database-per-tenant app.
    |
    */

    'storage' => [
        'disk' => env('SESSION_REPLAY_DISK', 'local'),
        'directory' => 'session-replay',
        'connection' => env('SESSION_REPLAY_DB_CONNECTION'),
    ],

    // Auto-run the package migrations; set false to publish and run them yourself.
    'run_migrations' => true,

    'retention' => [
        // session-replay:prune deletes recordings older than this, except pinned ones.
        'days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Viewer
    |--------------------------------------------------------------------------
    |
    | The built-in list and player. Who may open them is decided by the
    | viewSessionReplay gate; until your app defines it, only the local
    | environment is let in. enabled=false removes the pages and keeps the
    | <x-session-replay::player> component and its data routes.
    |
    */

    'viewer' => [
        'enabled' => true,
        'middleware' => ['web', 'auth'],
        'guard' => null,
        'per_page' => 25,
        // The attribute shown for a person in the list; falls back to the key.
        'user_label' => 'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log context
    |--------------------------------------------------------------------------
    |
    | While a recording runs, every request carries its id in a cookie and the
    | package adds the id and the viewer URL to Laravel's Context, so log
    | lines and error reports point at the replay.
    |
    */

    'context' => [
        'enabled' => true,
        'cookie' => 'session_replay_id',
    ],

];
