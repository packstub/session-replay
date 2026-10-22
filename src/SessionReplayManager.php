<?php

namespace Packstub\SessionReplay;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;

/** What the app told the package (through the SessionReplay facade) and the decisions built on it. */
class SessionReplayManager
{
    public const ABILITY = 'viewSessionReplay';

    /** Marker types that may upload the window in mode "on_error". */
    public const TRIGGERS = ['error', 'request', 'console', 'rage-click', 'custom'];

    /** The most propertiesUsing() and the directive's properties may take, as JSON: they travel in every recorded page. */
    public const MAX_PROPERTIES_BYTES = 4096;

    /** @var array<string, true> The config values already logged as unknown, once per process. */
    protected array $warned = [];

    protected ?Closure $recordWhen = null;

    protected ?Closure $userUsing = null;

    protected ?Closure $tenantUsing = null;

    protected ?Closure $impersonatorUsing = null;

    protected ?Closure $propertiesUsing = null;

    protected ?Closure $urlUsing = null;

    protected ?Closure $logUrlUsing = null;

    protected ?Closure $visibleUsing = null;

    /** Narrow who is recorded: fn (?Model $user, Request $request): bool. */
    public function recordWhen(Closure $callback): static
    {
        $this->recordWhen = $callback;

        return $this;
    }

    /** Who is signed in, when it is not the default guard's user: fn (Request $request): ?Model. */
    public function userUsing(Closure $callback): static
    {
        $this->userUsing = $callback;

        return $this;
    }

    /** The workspace a recording belongs to: fn (Request $request): ?Model. Null means a single workspace. */
    public function tenantUsing(Closure $callback): static
    {
        $this->tenantUsing = $callback;

        return $this;
    }

    /**
     * The person impersonating the signed-in user, if any: fn (Request $request): Model|int|string|null. A model
     * stores its morph class next to its key (impersonator_type), so the recording links to them; a key stores the
     * key alone.
     */
    public function impersonatorUsing(Closure $callback): static
    {
        $this->impersonatorUsing = $callback;

        return $this;
    }

    /** Anything else worth keeping on the recording (plan, panel, release): fn (Request $request): array. */
    public function propertiesUsing(Closure $callback): static
    {
        $this->propertiesUsing = $callback;

        return $this;
    }

    /** Where a recording is watched, when it is not the built-in viewer: fn (ReplaySession $session): ?string. */
    public function urlUsing(Closure $callback): static
    {
        $this->urlUsing = $callback;

        return $this;
    }

    /**
     * Where the log lines of a marker are read: fn (ReplayMarker $marker): ?string. Called for every marker the
     * player shows; a URL puts an "Open in logs" button in the marker's details. A server error's payload carries
     * `report`, the id its log lines and error report carry as `session_replay_report`.
     */
    public function logUrlUsing(Closure $callback): static
    {
        $this->logUrlUsing = $callback;

        return $this;
    }

    /**
     * The URL logUrlUsing() gives for this marker, or null without one.
     *
     * @internal
     */
    public function logUrlFor(ReplayMarker $marker): ?string
    {
        if ($this->logUrlUsing === null) {
            return null;
        }

        $url = ($this->logUrlUsing)($marker);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Narrow the recordings a viewer finds in a list, in SQL so pages stay
     * full: fn (Builder $query, mixed $viewer): void. The gate still decides
     * about every recording that is opened; this only keeps the rows it would
     * refuse out of sight.
     */
    public function visibleUsing(Closure $callback): static
    {
        $this->visibleUsing = $callback;

        return $this;
    }

    /**
     * @param  Builder<ReplaySession>  $query
     * @return Builder<ReplaySession>
     */
    public function visibleTo(Builder $query, mixed $viewer = null): Builder
    {
        if ($this->visibleUsing) {
            $viewer ??= $this->viewer();

            // In a group of its own: an orWhere() in the app's rule must not open what the query already narrowed
            // (a panel's workspace, the list's filters).
            $query->where(fn (Builder $query) => ($this->visibleUsing)($query, $viewer));
        }

        return $query;
    }

    public function enabled(): bool
    {
        return (bool) config('session-replay.enabled', true);
    }

    public function user(?Request $request = null): ?Model
    {
        $request ??= request();

        $user = $this->userUsing ? ($this->userUsing)($request) : auth()->user();

        return $user instanceof Model ? $user : null;
    }

    public function shouldRecord(?Request $request = null): bool
    {
        $request ??= request();

        return $this->shouldRecordFor($this->user($request), $request);
    }

    /** @internal shouldRecord() for a user the caller already knows; recorder() uses it with the directive's "user". */
    public function shouldRecordFor(?Model $user, Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $own = trim((string) config('session-replay.path', 'session-replay'), '/');

        if ($request->is($own, $own.'/*') || $this->isExcluded($request)) {
            return false;
        }

        if ($user === null && ! config('session-replay.guests', false)) {
            return false;
        }

        return $this->recordWhen === null || (bool) ($this->recordWhen)($user, $request);
    }

    /**
     * Whether this page's snapshot is stored once per content hash (snapshots.share_routes, snapshots.share_paths).
     *
     * @internal
     */
    public function sharesSnapshots(?Request $request = null): bool
    {
        return $this->requestMatches($request ?? request(), config('session-replay.snapshots.share_paths', []), config('session-replay.snapshots.share_routes', []));
    }

    /**
     * Whether the app leaves this request's page out of recordings (except, except_routes). The route must be matched.
     *
     * @internal
     */
    public function isExcluded(Request $request): bool
    {
        return $this->requestMatches($request, config('session-replay.except', []), config('session-replay.except_routes', []));
    }

    /** The request's path or route name matches one of the Str::is patterns; keys in a published list are ignored. */
    protected function requestMatches(Request $request, mixed $paths, mixed $routes): bool
    {
        // array_values: a keyed entry in a published list would be spread as a named argument and throw.
        $paths = array_values(array_filter((array) $paths, 'is_string'));
        $routes = array_values(array_filter((array) $routes, 'is_string'));

        return ($paths !== [] && $request->is(...$paths)) || ($routes !== [] && $request->routeIs(...$routes));
    }

    /**
     * The recorder's script tags, or nothing when this request is not recorded.
     * Options: "user", "tenant", "impersonator" and "properties" override what
     * the resolvers would give (a panel knows its own guard and workspace),
     * "nonce" a CSP nonce. The token in them is signed, not encrypted: who is
     * signed in, the workspace, the impersonator and the properties can be
     * read in the page's source.
     *
     * @param  array<string, mixed>  $options
     */
    public function recorder(array $options = []): HtmlString
    {
        $request = request();
        $user = array_key_exists('user', $options) ? $options['user'] : $this->user($request);
        $user = $user instanceof Model ? $user : null;

        if (! $this->shouldRecordFor($user, $request)) {
            return new HtmlString('');
        }

        $tenant = array_key_exists('tenant', $options) ? $options['tenant'] : ($this->tenantUsing ? ($this->tenantUsing)($request) : null);
        $impersonator = array_key_exists('impersonator', $options) ? $options['impersonator'] : ($this->impersonatorUsing ? ($this->impersonatorUsing)($request) : null);
        $properties = $this->properties(array_merge(
            $this->propertiesUsing ? (array) ($this->propertiesUsing)($request) : [],
            (array) ($options['properties'] ?? []),
        ));

        $token = ContextToken::for(
            $user,
            $tenant instanceof Model ? $tenant : null,
            $impersonator instanceof Model || $impersonator === null ? $impersonator : (string) $impersonator,
            $properties,
        );

        if (config('session-replay.privacy.anonymous', false)) {
            $token = $token->withoutPerson();
        }

        $config = [
            'token' => $token->encode(),
            // Who and where the token was signed for, so the browser starts a new recording when the person, the
            // workspace or the impersonator changes: a recording belongs to one person in one workspace.
            // The impersonator's type only when there is one: the hash of every other page stays what it was before.
            'identity' => $token->isAnonymous() ? null : substr(hash('sha256', implode('|', [$token->userType, $token->userId, $token->tenantType, $token->tenantId, $token->impersonatorId, ...($token->impersonatorType === null ? [] : [$token->impersonatorType]), $token->pseudonym, config('app.key')])), 0, 16),
            'ingestUrl' => $this->route('ingest'),
            'assetUrl' => $this->route('ingest.asset'),
            'sampleRate' => (float) config('session-replay.sample_rate', 1.0),
            'idleTimeout' => $this->idleMinutes() * 60 * 1000,
            'flushInterval' => max(1000, (int) round((float) config('session-replay.flush_seconds', 5) * 1000)),
            'maxBatchBytes' => (int) config('session-replay.ingest.max_batch_kb', 1536) * 1024,
            'consent' => $this->consent(),
            'respectGpc' => (bool) config('session-replay.respect_gpc', false),
            'privacy' => [
                'maskAllInputs' => (bool) config('session-replay.privacy.mask_all_inputs', true),
                'maskAllText' => (bool) config('session-replay.privacy.mask_all_text', false),
                'maskTextSelector' => config('session-replay.privacy.mask_text_selector'),
                'unmaskTextSelector' => config('session-replay.privacy.unmask_text_selector'),
                'blockSelector' => config('session-replay.privacy.block_selector'),
                'ignoreSelector' => config('session-replay.privacy.ignore_selector'),
                'redactQuery' => array_values(array_map('strval', (array) config('session-replay.privacy.redact_query', []))),
            ],
            'capture' => [
                'console' => array_values((array) config('session-replay.capture.console', [])),
                'errors' => (bool) config('session-replay.capture.errors', true),
                'livewire' => (bool) config('session-replay.capture.livewire', true),
                'requests' => $this->requestThreshold(),
                'vitals' => (bool) config('session-replay.capture.vitals', true),
                'rageClicks' => (bool) config('session-replay.capture.rage_clicks', true),
            ],
            'size' => [
                'dedupeStylesheets' => (bool) config('session-replay.size.dedupe_stylesheets', true),
                'dedupeMinBytes' => (int) config('session-replay.size.dedupe_min_bytes', 2048),
                'stripAttributes' => array_values((array) config('session-replay.size.strip_attributes', [])),
                'keepAttributes' => array_values((array) config('session-replay.size.keep_attributes', [])),
                'sampling' => (array) config('session-replay.size.sampling', []),
            ],
            'snapshots' => [
                'share' => $this->sharesSnapshots($request),
                'volatileIds' => array_values(array_filter((array) config('session-replay.snapshots.volatile_ids', []), 'is_string')),
                'url' => $this->route('ingest.snapshot'),
            ],
            'cookie' => config('session-replay.context.enabled', true) ? config('session-replay.context.cookie', 'session_replay_id') : null,
            'mode' => $this->mode(),
            'onError' => $this->onErrorConfig($token),
            // The debug error page is titled with the app's name; the question leaves that title out.
            'appName' => (string) config('app.name', ''),
        ];

        $nonce = $options['nonce'] ?? Vite::cspNonce();
        $nonceAttribute = $nonce ? ' nonce="'.e($nonce).'"' : '';

        // The config runs again on every page Livewire's wire:navigate swaps in (the recorder reads it there); the
        // bundle only once per document, Livewire would otherwise run it again on every page for nothing.
        return new HtmlString(
            '<script'.$nonceAttribute.'>window.__sessionReplay='.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).';</script>'
            .'<script src="'.e($this->scriptUrl('recorder.js')).'" defer data-navigate-once'.$nonceAttribute.'></script>'
        );
    }

    /**
     * The properties as they go into the token, or none when they are larger than MAX_PROPERTIES_BYTES: every
     * recorded page carries them, and a recording stores them. Logged, so the missing properties have a reason.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    protected function properties(array $properties): array
    {
        $bytes = strlen((string) json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($bytes <= self::MAX_PROPERTIES_BYTES) {
            return $properties;
        }

        $this->warnOnce('properties', sprintf('Session Replay: the recording properties take %d bytes, more than the %d a page may carry; they were left out.', $bytes, self::MAX_PROPERTIES_BYTES));

        return [];
    }

    /** @internal Minutes without activity after which the browser starts a new recording (idle_minutes, at least 1). */
    public function idleMinutes(): int
    {
        return max(1, (int) config('session-replay.idle_minutes', 30));
    }

    /** The status from which a request is a failure (capture.requests), or false when requests are not watched. */
    protected function requestThreshold(): int|false
    {
        $threshold = config('session-replay.capture.requests', 500);

        if ($threshold === false || $threshold === null) {
            return false;
        }

        return $threshold === true ? 500 : max(400, min(599, (int) $threshold));
    }

    /**
     * "session" (every recorded tab uploads from its first page) or "on_error" (only when something goes wrong). Any
     * other value is read as "on_error", the one that uploads less, and logged.
     */
    public function mode(): string
    {
        return $this->choice('mode', 'session', ['session' => 'session', 'on_error' => 'on_error', 'on-error' => 'on_error', 'onerror' => 'on_error'], 'on_error');
    }

    /**
     * "always" (recording starts with the page) or "opt-in" (it waits for SessionReplay.consent(true)). Any other
     * value is read as "opt-in" and logged: a typo never records without consent.
     */
    public function consent(): string
    {
        return $this->choice('consent', 'always', ['always' => 'always', 'opt-in' => 'opt-in', 'opt_in' => 'opt-in', 'optin' => 'opt-in'], 'opt-in');
    }

    /**
     * A config value that is one of a few words, in any case and the usual spellings. Unset means the default; anything
     * else the strict choice, logged once per process so the misspelt value can be found.
     *
     * @param  array<string, string>  $spellings
     */
    protected function choice(string $key, string $default, array $spellings, string $strict): string
    {
        $value = config('session-replay.'.$key);

        if ($value === null || $value === '') {
            return $default;
        }

        $spelling = is_string($value) ? strtolower(trim($value)) : null;

        if ($spelling !== null && isset($spellings[$spelling])) {
            return $spellings[$spelling];
        }

        $this->warnOnce($key, sprintf('Session Replay: %s "%s" is none of %s; it is read as "%s".', $key, is_scalar($value) ? (string) $value : get_debug_type($value), implode(', ', array_unique($spellings)), $strict));

        return $strict;
    }

    protected function warnOnce(string $key, string $message): void
    {
        if (isset($this->warned[$key])) {
            return;
        }

        $this->warned[$key] = true;

        Log::warning($message);
    }

    /**
     * What the recorder needs for mode "on_error": the window, the marker
     * types that upload it and, with on_error.ask, the dialog's strings in
     * the app's locale. Null in mode "session".
     *
     * @return array<string, mixed>|null
     */
    protected function onErrorConfig(ContextToken $token): ?array
    {
        if ($this->mode() !== 'on_error') {
            return null;
        }

        $ask = $this->askStyle();

        return [
            'bufferMs' => max(5, min(600, (int) config('session-replay.on_error.buffer_seconds', 60))) * 1000,
            'maxWindowChars' => max(1, min(50, (int) config('session-replay.on_error.max_buffer_mb', 5))) * 1_000_000,
            'triggers' => array_values(array_intersect(self::TRIGGERS, array_map('strval', (array) config('session-replay.on_error.triggers', ['error', 'request'])))),
            'ask' => $ask,
            'askAgain' => $ask && (bool) config('session-replay.on_error.ask_again', false),
            'askTimeoutMs' => $ask === 'toast' ? $this->askTimeoutMs() : 0,
            'keepPending' => (bool) config('session-replay.on_error.keep_pending', true),
            'replaceLivewireModal' => $ask && $this->replacesLivewireModal(),
            // Only a token that names a person can be sent without them; an anonymous one already is.
            'offerAnonymous' => $ask && $token->userId !== null,
            'labels' => $ask ? [
                'title' => __('session-replay::recorder.ask.title'),
                'body' => __('session-replay::recorder.ask.body'),
                'anonymous' => __('session-replay::recorder.ask.anonymous'),
                'share' => __('session-replay::recorder.ask.share'),
                'decline' => __('session-replay::recorder.ask.decline'),
                'failed' => __('session-replay::recorder.ask.failed'),
                'sent' => __('session-replay::recorder.ask.sent'),
            ] : null,
        ];
    }

    /** How on_error.ask puts the question: in a modal "dialog", in a "toast" at the bottom corner, or not at all (false). */
    protected function askStyle(): string|false
    {
        $ask = config('session-replay.on_error.ask', false);

        return match (true) {
            $ask === 'toast' => 'toast',
            (bool) $ask => 'dialog',
            default => false,
        };
    }

    /** How long the toast waits for an answer (on_error.ask_timeout_seconds, 5 to 600; 0 for ever), in milliseconds. */
    protected function askTimeoutMs(): int
    {
        $seconds = (int) config('session-replay.on_error.ask_timeout_seconds', 60);

        return $seconds <= 0 ? 0 : max(5, min(600, $seconds)) * 1000;
    }

    /** Whether the question takes the place of Livewire's error modal (on_error.livewire_error_modal). */
    protected function replacesLivewireModal(): bool
    {
        return match ((string) config('session-replay.on_error.livewire_error_modal', 'production')) {
            'replace' => true,
            'keep' => false,
            default => ! config('app.debug'),
        };
    }

    /**
     * The player's stylesheet and script, for a page that embeds <x-session-replay::player>. The script runs once
     * per tab with wire:navigate (data-navigate-once) and mounts the players of every page Livewire swaps in.
     */
    public function playerAssets(): HtmlString
    {
        $nonce = Vite::cspNonce();
        $nonceAttribute = $nonce ? ' nonce="'.e($nonce).'"' : '';

        return new HtmlString(
            '<link rel="stylesheet" href="'.e($this->scriptUrl('player.css')).'"'.$nonceAttribute.'>'
            .'<script src="'.e($this->scriptUrl('player.js')).'" defer data-navigate-once'.$nonceAttribute.'></script>'
        );
    }

    /**
     * May this person watch — the list when no recording is given, that one
     * recording otherwise. The app's viewSessionReplay gate decides. Without
     * the gate, a policy registered for ReplaySession does (viewAny for the
     * list, view for a recording), the way Filament Shield sets one up.
     * Without either only the local environment is let in, so a forgotten
     * setup never exposes a recording.
     */
    public function check(?ReplaySession $session = null, mixed $user = null): bool
    {
        if (Gate::has(self::ABILITY)) {
            $gate = Gate::forUser($user ?? $this->viewer());

            return $session === null ? $gate->allows(self::ABILITY) : $gate->allows(self::ABILITY, [$session]);
        }

        if (Gate::getPolicyFor(ReplaySession::class) !== null) {
            $gate = Gate::forUser($user ?? $this->viewer());

            return $session === null ? $gate->allows('viewAny', ReplaySession::class) : $gate->allows('view', $session);
        }

        return app()->environment('local');
    }

    /** The person looking at the viewer (viewer.guard, or the default guard). */
    public function viewer(): mixed
    {
        return auth()->guard(config('session-replay.viewer.guard'))->user();
    }

    public function urlFor(ReplaySession $session): ?string
    {
        if ($this->urlUsing) {
            return ($this->urlUsing)($session);
        }

        return config('session-replay.viewer.enabled', true) ? $this->route('show', ['session' => $session->getKey()]) : null;
    }

    /** @param array<string, mixed> $parameters */
    public function route(string $name, array $parameters = []): string
    {
        return route('session-replay.'.$name, $parameters);
    }

    /** @internal */
    public function scriptUrl(string $file): string
    {
        return $this->route('script', ['file' => $file]).'?v='.$this->scriptVersion($file);
    }

    /** @internal */
    public function scriptPath(string $file): string
    {
        return __DIR__.'/../resources/dist/'.$file;
    }

    protected function scriptVersion(string $file): string
    {
        static $versions = [];

        return $versions[$file] ??= substr(md5((string) @filemtime($this->scriptPath($file)).(string) @filesize($this->scriptPath($file))), 0, 10);
    }

    /**
     * "desktop", "tablet" or "mobile" from a user agent; good enough for a filter.
     *
     * @internal
     */
    public function device(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            Str::contains($userAgent, ['iPad', 'Tablet'], true) || (Str::contains($userAgent, 'Android', true) && ! Str::contains($userAgent, 'Mobile', true)) => 'tablet',
            Str::contains($userAgent, ['Mobi', 'iPhone', 'Android'], true) => 'mobile',
            default => 'desktop',
        };
    }
}
