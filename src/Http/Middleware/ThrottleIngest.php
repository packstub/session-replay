<?php

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * The framework's throttle, patient with a locked counter. A page that goes
 * away uploads twice within milliseconds (pagehide, then the late web
 * vitals), and on the default stack (SQLite with the database cache) the
 * second counter update fails with "database is locked". That upload cannot
 * be retried by the browser, so the count is retried here instead.
 *
 * @internal Wired by the package; not part of the public API.
 */
class ThrottleIngest extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // The parent tells a named limiter from its argument count, so the arguments go through as they came.
        $arguments = array_slice(func_get_args(), 2);
        $reached = false;

        $through = function ($request) use ($next, &$reached) {
            $reached = true;

            return $next($request);
        };

        // Only the counting is retried, never the upload itself.
        return retry(
            4,
            fn () => parent::handle($request, $through, ...$arguments),
            fn (int $attempt): int => $attempt * 40,
            fn ($exception): bool => ! $reached && $exception instanceof QueryException,
        );
    }
}
