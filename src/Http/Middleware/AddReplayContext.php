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
 *
 * @internal Wired by the package; not part of the public API.
 */
class AddReplayContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nothing of an earlier request carries over (a long-running server keeps the instance).
        app(ServerErrors::class)->forRecording(null);

        if (config('session-replay.enabled', true) && config('session-replay.context.enabled', true)) {
            // Read before cookie decryption runs: the recorder writes it in the clear, as "<id>.<write key>" (the id
            // alone from a recorder from before write keys).
            $cookie = $request->cookies->get((string) config('session-replay.context.cookie', 'session_replay_id'));
            [$id, $key] = is_string($cookie) ? array_pad(explode('.', $cookie, 2), 2, null) : [null, null];

            // A frontend on another origin cannot send the cookie; it sends the same value as a header. The cookie wins.
            if (! (is_string($id) && Str::isUuid($id))) {
                [$id, $key] = self::fromHeader($request) ?? [null, null];
            }

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
                    app(ServerErrors::class)->forRecording(strtolower($id), $request, is_string($key) && $key !== '' ? $key : null);
                }
            }
        }

        return $next($request);
    }

    /**
     * The recording id and write key from the context.header header ("<uuid>" or "<uuid>.<key>", the key 1 to 128
     * letters and digits), the id in lower case; null for a header that is off, missing or anything else. Strict: a
     * page script chooses this value, and it ends up in every log line of the request.
     *
     * @return array{0: string, 1: ?string}|null
     */
    protected static function fromHeader(Request $request): ?array
    {
        $name = config('session-replay.context.header', 'X-Session-Replay');
        $value = is_string($name) && $name !== '' ? $request->headers->get($name) : null;

        if (! is_string($value) || strlen($value) > 165) {
            return null;
        }

        if (! preg_match('/\A([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\.([A-Za-z0-9]{1,128}))?\z/i', $value, $match)) {
            return null;
        }

        return [strtolower($match[1]), ($match[2] ?? '') !== '' ? $match[2] : null];
    }

    /**
     * Once the route is known: a page the app does not record (except,
     * except_routes) gets no replay link in its log lines and raises no
     * server-error marker, even while the tab's recording runs around it (an
     * excluded page inside a wire:navigate session, whose cookie is still
     * set). Global middleware runs before routing, so this waits for
     * RouteMatched. Livewire's update requests from such a page keep the
     * keys: their path is not the page's.
     */
    public static function forgetOnExcludedPage(Request $request): void
    {
        if (Context::has('session_replay') && SessionReplay::isExcluded($request)) {
            Context::forget(['session_replay', 'session_replay_url', 'session_replay_moment']);
            app(ServerErrors::class)->forRecording(null);
        }
    }
}
