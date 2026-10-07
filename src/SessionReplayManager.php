<?php

namespace Packstub\SessionReplay;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;

/** What the app told the package (through the SessionReplay facade) and the decisions built on it. */
class SessionReplayManager
{
    public const ABILITY = 'viewSessionReplay';

    /** Marker types that may upload the window in mode "on_error". */
    public const TRIGGERS = ['error', 'request', 'console', 'rage-click', 'custom'];

    protected ?Closure $recordWhen = null;

    protected ?Closure $userUsing = null;

    protected ?Closure $tenantUsing = null;

    protected ?Closure $impersonatorUsing = null;

    protected ?Closure $propertiesUsing = null;

    protected ?Closure $urlUsing = null;

    protected ?Closure $visibleUsing = null;

    /** Narrow who is recorded: fn (?Authenticatable $user, Request $request): bool. */
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

    /** The key of the person impersonating the signed-in user, if any: fn (Request $request): int|string|null. */
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
            ($this->visibleUsing)($query, $viewer ?? $this->viewer());
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

    public function shouldRecordFor(?Model $user, Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $own = trim((string) config('session-replay.path', 'session-replay'), '/');

        if ($request->is($own, $own.'/*') || $this->requestMatches($request, config('session-replay.except', []), config('session-replay.except_routes', []))) {
            return false;
        }

        if ($user === null && ! config('session-replay.guests', false)) {
            return false;
        }

        return $this->recordWhen === null || (bool) ($this->recordWhen)($user, $request);
    }

    /** Whether this page's snapshot is stored once per content hash (snapshots.share_routes, snapshots.share_paths). */
    public function sharesSnapshots(?Request $request = null): bool
    {
        return $this->requestMatches($request ?? request(), config('session-replay.snapshots.share_paths', []), config('session-replay.snapshots.share_routes', []));
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
     * "nonce" a CSP nonce.
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
        $properties = array_merge(
            $this->propertiesUsing ? (array) ($this->propertiesUsing)($request) : [],
            (array) ($options['properties'] ?? []),
        );

        $token = ContextToken::for(
            $user,
            $tenant instanceof Model ? $tenant : null,
            $impersonator === null ? null : (string) $impersonator,
            $properties,
        );

        if (config('session-replay.privacy.anonymous', false)) {
            $token = $token->withoutPerson();
        }

        $config = [
            'token' => $token->encode(),
            // Who and where the token was signed for, so the browser starts a new recording when the person, the
            // workspace or the impersonator changes: a recording belongs to one person in one workspace.
            'identity' => $token->isAnonymous() ? null : substr(hash('sha256', implode('|', [$token->userType, $token->userId, $token->tenantType, $token->tenantId, $token->impersonatorId, $token->pseudonym, config('app.key')])), 0, 16),
            'ingestUrl' => $this->route('ingest'),
            'assetUrl' => $this->route('ingest.asset'),
            'sampleRate' => (float) config('session-replay.sample_rate', 1.0),
            'idleTimeout' => (int) config('session-replay.idle_timeout', 30) * 60 * 1000,
            'flushInterval' => max(1000, (int) config('session-replay.flush_interval', 5000)),
            'maxBatchBytes' => (int) config('session-replay.ingest.max_batch_kb', 1536) * 1024,
            'consent' => config('session-replay.consent', 'always') === 'opt-in' ? 'opt-in' : 'always',
            'respectGpc' => (bool) config('session-replay.respect_gpc', false),
            'privacy' => [
                'maskAllInputs' => (bool) config('session-replay.privacy.mask_all_inputs', true),
                'maskAllText' => (bool) config('session-replay.privacy.mask_all_text', false),
                'maskTextSelector' => config('session-replay.privacy.mask_text_selector'),
                'blockSelector' => config('session-replay.privacy.block_selector'),
                'ignoreSelector' => config('session-replay.privacy.ignore_selector'),
                'redactQuery' => array_values(array_map('strval', (array) config('session-replay.privacy.redact_query', []))),
            ],
            'capture' => [
                'console' => array_values((array) config('session-replay.capture.console', [])),
                'errors' => (bool) config('session-replay.capture.errors', true),
                'livewire' => (bool) config('session-replay.capture.livewire', true),
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
        ];

        $nonce = $options['nonce'] ?? Vite::cspNonce();
        $nonceAttribute = $nonce ? ' nonce="'.e($nonce).'"' : '';

        return new HtmlString(
            '<script'.$nonceAttribute.'>window.__sessionReplay='.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).';</script>'
            .'<script src="'.e($this->scriptUrl('recorder.js')).'" defer'.$nonceAttribute.'></script>'
        );
    }

    /** "session" (every recorded tab uploads from its first page) or "on_error" (only when something goes wrong). */
    public function mode(): string
    {
        return config('session-replay.mode', 'session') === 'on_error' ? 'on_error' : 'session';
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

        $ask = (bool) config('session-replay.on_error.ask', false);

        return [
            'bufferMs' => max(5, min(600, (int) config('session-replay.on_error.buffer_seconds', 60))) * 1000,
            'triggers' => array_values(array_intersect(self::TRIGGERS, array_map('strval', (array) config('session-replay.on_error.triggers', ['error', 'request'])))),
            'ask' => $ask,
            'keepPending' => (bool) config('session-replay.on_error.keep_pending', true),
            // Only a token that names a person can be sent without them; an anonymous one already is.
            'offerAnonymous' => $ask && $token->userId !== null,
            'labels' => $ask ? [
                'title' => __('session-replay::recorder.ask.title'),
                'body' => __('session-replay::recorder.ask.body'),
                'anonymous' => __('session-replay::recorder.ask.anonymous'),
                'share' => __('session-replay::recorder.ask.share'),
                'decline' => __('session-replay::recorder.ask.decline'),
            ] : null,
        ];
    }

    /** The player's stylesheet and script, for a page that embeds <x-session-replay::player>. */
    public function playerAssets(): HtmlString
    {
        $nonce = Vite::cspNonce();
        $nonceAttribute = $nonce ? ' nonce="'.e($nonce).'"' : '';

        return new HtmlString(
            '<link rel="stylesheet" href="'.e($this->scriptUrl('player.css')).'"'.$nonceAttribute.'>'
            .'<script src="'.e($this->scriptUrl('player.js')).'" defer'.$nonceAttribute.'></script>'
        );
    }

    /**
     * May this person watch — the list when no recording is given, that one
     * recording otherwise. The app's viewSessionReplay gate decides; until it
     * is defined only the local environment is let in, so a forgotten setup
     * never exposes a recording.
     */
    public function check(?ReplaySession $session = null, mixed $user = null): bool
    {
        if (! Gate::has(self::ABILITY)) {
            return app()->environment('local');
        }

        $gate = $user === null ? Gate::forUser($this->viewer()) : Gate::forUser($user);

        return $session === null ? $gate->allows(self::ABILITY) : $gate->allows(self::ABILITY, [$session]);
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

    public function scriptUrl(string $file): string
    {
        return $this->route('script', ['file' => $file]).'?v='.$this->scriptVersion($file);
    }

    public function scriptPath(string $file): string
    {
        return __DIR__.'/../resources/dist/'.$file;
    }

    protected function scriptVersion(string $file): string
    {
        static $versions = [];

        return $versions[$file] ??= substr(md5((string) @filemtime($this->scriptPath($file)).(string) @filesize($this->scriptPath($file))), 0, 10);
    }

    /** "desktop", "tablet" or "mobile" from a user agent; good enough for a filter. */
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
