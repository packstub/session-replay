<?php

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The viewSessionReplay gate on every viewer and data route: without a
 * recording for the list, with it for anything that belongs to one — the
 * page, the manifest, a chunk, a stylesheet.
 *
 * @internal Wired by the package; not part of the public API.
 */
class AuthorizeViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->route('session');

        if (is_string($session)) {
            $session = ReplaySession::query()->findOrFail($session);
            $request->route()->setParameter('session', $session);
        }

        abort_unless(SessionReplay::check($session instanceof ReplaySession ? $session : null), 403);

        return $next($request);
    }
}
