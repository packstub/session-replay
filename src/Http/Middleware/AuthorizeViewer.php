<?php

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;
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
            // An id that is no recording answers like one the gate denies: the viewer of one workspace must not learn
            // whether an id they came by is a recording of another.
            $session = ReplaySession::query()->find($session);

            abort_if($session === null, 403);

            $request->route()->setParameter('session', $session);
        }

        if (! SessionReplay::check($session instanceof ReplaySession ? $session : null)) {
            $this->letGoOfHeldAssist($request);

            abort(403);
        }

        return $next($request);
    }

    /**
     * The gate turned away an agent on one of Assist's request routes (the stage's status poll, most often): when they
     * hold that request, it does not go on without them having access (a live assist ends with its call). The answer
     * stays a bare 403 either way.
     */
    protected function letGoOfHeldAssist(Request $request): void
    {
        $id = $request->route('id');

        if (! is_string($id) || ! str_starts_with((string) $request->route()?->getName(), 'session-replay.assist.')) {
            return;
        }

        $agent = SessionReplay::viewer();
        $assist = app(Assist::class);
        $held = $agent === null ? null : AssistRequest::query()->find(strtolower($id));

        if ($held !== null && $held->isPending() && $assist->holds($agent, $held)) {
            $assist->notAllowedAnyMore($held);
        }
    }
}
