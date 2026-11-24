<?php

declare(strict_types=1);

namespace Packstub\SessionReplay\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The origins of a frontend served apart from the app (ingest.origins) that may upload recordings and fetch a token
 * across origins. Every entry is read as one exact origin: http or https, the host in lower case, the port only when
 * it is not the scheme's default. Anything else (a wildcard, "null", a path, a user name) is ignored and logged once:
 * a list that means more than it says is never guessed at. The app's own origin is always allowed.
 *
 * @internal
 */
final class AllowedOrigins
{
    /** @var array<string, true> Entries already logged as ignored, once per process. */
    private static array $warned = [];

    private static bool $warnedCors = false;

    /** @return list<string> The configured origins that are valid, normalised. */
    public function configured(): array
    {
        $origins = [];

        foreach ((array) config('session-replay.ingest.origins', []) as $entry) {
            $origin = is_string($entry) ? self::normalise($entry) : null;

            if ($origin === null) {
                $this->warnOnce(is_scalar($entry) ? (string) $entry : get_debug_type($entry));

                continue;
            }

            $origins[$origin] = $origin;
        }

        return array_values($origins);
    }

    /** Whether a cross-origin frontend is set up at all. */
    public function any(): bool
    {
        return $this->configured() !== [];
    }

    /**
     * The request's Origin header, normalised, when it is the app's own origin or a configured one; null for any other,
     * and for a missing or "null" one.
     */
    public function match(?string $origin, Request $request): ?string
    {
        $origin = $origin === null ? null : self::normalise($origin);

        if ($origin === null) {
            return null;
        }

        return $origin === $this->own($request) || in_array($origin, $this->configured(), true) ? $origin : null;
    }

    /** Whether the request comes from a page of another origin (an Origin header that is not the app's own). */
    public function crossOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return $origin !== null && self::normalise($origin) !== $this->own($request);
    }

    /** The origin this request was made to, the way a browser writes it. */
    public function own(Request $request): ?string
    {
        return self::normalise($request->getSchemeAndHttpHost());
    }

    /**
     * One origin as a browser sends it, or null when the value is none: scheme and host only (a port when it is not
     * the default), no path, query, fragment, user name or wildcard.
     */
    public static function normalise(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 255 || str_contains($value, '*') || strtolower($value) === 'null') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z|\A\[[0-9a-f:.]+\]\z/', $host)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port === null || $port === $default ? '' : ':'.$port);
    }

    /**
     * Laravel's own CORS middleware answers a path listed in config/cors.php before the package does, with that
     * file's rules: worth a warning once, since those rules usually allow more (credentials, any header).
     */
    public function warnAboutLaravelCors(string $path): void
    {
        if (self::$warnedCors || ! $this->any()) {
            return;
        }

        $patterns = array_values(array_filter((array) config('cors.paths', []), 'is_string'));

        foreach (['ingest', 'token'] as $route) {
            if ($patterns !== [] && Str::is($patterns, $path.'/'.$route)) {
                self::$warnedCors = true;

                Log::warning(sprintf('Session Replay: config/cors.php lists "%s/%s" in paths, so Laravel answers its CORS requests instead of the package; take the package path out of cors.paths.', $path, $route));

                return;
            }
        }
    }

    private function warnOnce(string $entry): void
    {
        if (isset(self::$warned[$entry])) {
            return;
        }

        self::$warned[$entry] = true;

        Log::warning(sprintf('Session Replay: ingest.origins entry "%s" is not an origin like "https://app.example.com" (no wildcards, paths or "null"); it is ignored.', Str::limit($entry, 100)));
    }
}
