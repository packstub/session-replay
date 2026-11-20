<?php

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cap on uploads for the whole app (ingest.global_requests_per_minute):
 * one cache counter per minute, whoever sends. Past it the upload is answered
 * 503 with Retry-After, and the recorder pauses every upload of the tab for
 * that long, so after an outage the app serves its pages first and takes the
 * recordings as it can. The per-person limit runs first, so one person past
 * their own limit does not use up everybody's.
 *
 * @internal Wired by the package; not part of the public API.
 */
class CapIngest
{
    public function handle(Request $request, Closure $next): Response
    {
        $cap = (int) config('session-replay.ingest.global_requests_per_minute');

        if ($cap <= 0) {
            return $next($request);
        }

        $now = now();
        $key = 'session-replay|global|'.$now->format('Y-m-d-H-i');

        // add() then increment(): atomic on every store that has increment, unlike a get and a put.
        Cache::add($key, 0, 120);

        if ((int) Cache::increment($key) <= $cap) {
            return $next($request);
        }

        return response()->json(['ok' => false, 'message' => 'Recording uploads are paused for a moment.', 'stop' => false], 503, [
            'Retry-After' => (string) self::retryAfter((int) $now->format('s')),
        ]);
    }

    /**
     * Seconds until the next minute's budget, plus up to two minutes at random: every tab refused in the same
     * minute would otherwise come back on the same second.
     */
    public static function retryAfter(int $second): int
    {
        return (60 - $second) + random_int(0, 120);
    }
}
