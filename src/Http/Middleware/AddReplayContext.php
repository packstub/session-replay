<?php

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ServerErrors;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a recording runs the browser sends its id in a plain cookie. Put it,
 * and where to watch it, into Laravel's Context, so every log line, queued
 * job and error report of this request points at the replay.
 */
class AddReplayContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nothing of an earlier request carries over (a long-running server keeps the instance).
        app(ServerErrors::class)->forRecording(null);

        if (config('session-replay.enabled', true) && config('session-replay.context.enabled', true)) {
            // Read before cookie decryption runs: the recorder writes it in the clear.
            $id = $request->cookies->get((string) config('session-replay.context.cookie', 'session_replay_id'));

            if (is_string($id) && Str::isUuid($id)) {
                Context::add('session_replay', $id);

                // An unsaved model is enough to build the link; no query on the hot path.
                $url = SessionReplay::urlFor((new ReplaySession)->forceFill(['id' => $id]));

                if ($url !== null) {
                    Context::add('session_replay_url', $url);
                    // The same page, opened at this request's moment (the player turns ?at= into an offset).
                    Context::add('session_replay_moment', $url.(str_contains($url, '?') ? '&' : '?').'at='.now()->getTimestampMs());
                }

                // Not the package's own routes: an upload or the viewer failing is no error of the recorded page.
                $path = trim((string) config('session-replay.path', 'session-replay'), '/');

                if (! $request->is($path, $path.'/*')) {
                    app(ServerErrors::class)->forRecording(strtolower($id), $request);
                }
            }
        }

        return $next($request);
    }
}
