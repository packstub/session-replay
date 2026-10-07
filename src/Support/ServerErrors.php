<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Exceptions Laravel reports during a recorded request, as "error" markers
 * on the replay (payload source "server"): the browser only ever sees "the
 * request failed (500)". Bound per request (scoped): AddReplayContext tells it
 * which recording the request belongs to, so a queued job that carries the
 * Context keys along is never put on a timeline.
 *
 * In mode "on_error" the failed request is what makes the browser upload, so
 * the recording is usually not stored yet: the marker waits in the cache for
 * idle_timeout and the recording's next batch takes it (takePending()).
 */
class ServerErrors
{
    public const MAX_PENDING = 20;

    protected ?string $recording = null;

    protected ?Request $request = null;

    /** Called by AddReplayContext on every request: the recording it belongs to, or none. */
    public function forRecording(?string $id, ?Request $request = null): void
    {
        $this->recording = $id;
        $this->request = $request;
    }

    /** The reportable callback: never in the way of the app's own reporting. */
    public function report(Throwable $exception): void
    {
        try {
            $this->record($exception);
        } catch (Throwable) {
            // The database that failed the request may fail this too.
        }
    }

    /** Markers kept for a recording before it was stored, for its owner only; taken once. */
    public function takePending(ReplaySession $session): void
    {
        if (! $this->mode()) {
            return;
        }

        $pending = Cache::pull($this->key($session->getKey()));

        if (! is_array($pending) || $pending === []) {
            return;
        }

        $markers = array_values(array_map(
            fn (array $entry): array => $entry['marker'],
            array_filter($pending, fn (mixed $entry): bool => is_array($entry) && is_array($entry['marker'] ?? null) && $this->sameOwner($session, $entry['user_type'] ?? null, $entry['user_id'] ?? null)),
        ));

        $this->store($session, $markers);
    }

    protected function record(Throwable $exception): void
    {
        $mode = $this->mode();

        if (! $mode || $this->recording === null || $this->request === null || ! SessionReplay::enabled()) {
            return;
        }

        $user = SessionReplay::user($this->request);
        $marker = $this->marker($exception, $mode === 'message');
        $session = ReplaySession::query()->find($this->recording);

        if ($session === null) {
            $this->keep($this->recording, $marker, $user);

            return;
        }

        if ($this->sameOwner($session, $user?->getMorphClass(), $user === null ? null : (string) $user->getKey())) {
            $this->store($session, [$marker]);
        }
    }

    /** @return array<string, mixed> */
    protected function marker(Throwable $exception, bool $withMessage): array
    {
        $label = 'Server error: '.class_basename($exception);
        $message = trim($exception->getMessage());

        // Off by default: a message often carries personal data (a query's values, an email address).
        if ($withMessage && $message !== '') {
            $label .= ': '.self::redactUrls($message, (array) config('session-replay.privacy.redact_query', []));
        }

        return [
            'type' => 'error',
            'label' => mb_substr($label, 0, 480),
            'payload' => json_encode([
                'source' => 'server',
                'exception' => $exception::class,
                'status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500,
                'method' => $this->request->method(),
                // The path only: the query may carry what privacy.redact_query is there for.
                'path' => '/'.ltrim($this->request->path(), '/'),
            ]),
            'at_ms' => now()->getTimestampMs(),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $markers */
    protected function store(ReplaySession $session, array $markers): void
    {
        if ($markers === []) {
            return;
        }

        ReplayMarker::query()->insert(array_map(fn (array $marker): array => [
            ...$marker,
            'replay_session_id' => $session->getKey(),
            'created_at' => now(),
        ], array_slice($markers, 0, self::MAX_PENDING)));

        ReplaySession::query()->whereKey($session->getKey())->increment('error_count', min(count($markers), self::MAX_PENDING));
    }

    /** @param  array<string, mixed>  $marker */
    protected function keep(string $id, array $marker, ?Model $user): void
    {
        $key = $this->key($id);
        $pending = Cache::get($key);
        $pending = is_array($pending) ? array_slice($pending, -(self::MAX_PENDING - 1)) : [];

        $pending[] = ['marker' => $marker, 'user_type' => $user?->getMorphClass(), 'user_id' => $user === null ? null : (string) $user->getKey()];

        Cache::put($key, $pending, now()->addMinutes(max(1, (int) config('session-replay.idle_timeout', 30))));
    }

    /**
     * The recording's person made the request. Recordings that name nobody (guests, privacy.anonymous) and requests
     * whose person this app cannot tell (a guard userUsing() does not know) are taken on the recording's id alone:
     * it is a random UUID only that browser and the logs know.
     */
    protected function sameOwner(ReplaySession $session, ?string $userType, ?string $userId): bool
    {
        if ($session->user_id === null || $userId === null) {
            return true;
        }

        return $session->user_type === $userType && (string) $session->user_id === $userId;
    }

    protected function mode(): string|false
    {
        $mode = config('session-replay.capture.server_errors', 'class');

        if ($mode === false || $mode === null) {
            return false;
        }

        return $mode === 'message' ? 'message' : 'class';
    }

    protected function key(string $id): string
    {
        return 'session-replay:server-errors:'.$id;
    }

    /** Every http(s) URL in a text with the values of the listed query parameters replaced, as the recorder does. */
    public static function redactUrls(string $text, array $names): string
    {
        $names = array_map('strtolower', array_map('strval', $names));

        if ($names === []) {
            return $text;
        }

        return (string) preg_replace_callback('~https?://[^\s"\'<>()]+~', function (array $match) use ($names): string {
            $url = $match[0];
            $query = parse_url($url, PHP_URL_QUERY);

            if (! is_string($query) || $query === '') {
                return $url;
            }

            $parts = array_map(function (string $pair) use ($names): string {
                [$name] = explode('=', $pair, 2);

                return in_array(strtolower(urldecode($name)), $names, true) ? $name.'=redacted' : $pair;
            }, explode('&', $query));

            return str_replace('?'.$query, '?'.implode('&', $parts), $url);
        }, $text);
    }
}
