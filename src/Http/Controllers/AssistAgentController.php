<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Packstub\Calls\Calls;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;

/**
 * The agent's side of Assist, behind the viewer's middleware and gate (see
 * AuthorizeViewer): the agent is the viewer guard's user, every request-bound
 * endpoint asks visibleUsing() and canAssist again, and the POSTs carry the
 * CSRF token. JSON for a client of its own (the Filament package, a stage);
 * the built-in Requests page posts forms and is sent back to the list.
 *
 * @internal Wired by the package; not part of the public API.
 */
class AssistAgentController
{
    public function __construct(protected Assist $assist) {}

    /** GET {path}/assist — the built-in Requests page. */
    public function index(): View
    {
        $agent = $this->agent();

        return view('session-replay::viewer.assist', [
            'requests' => $this->assist->queue($agent),
            'agentId' => (string) $agent->getAuthIdentifier(),
            'assist' => $this->assist,
            'visitorTimeout' => (int) config('session-replay.assist.visitor_timeout_seconds', 60),
        ]);
    }

    /** GET {path}/assist/queue — the requests that wait, and the agent's own. */
    public function queue(): JsonResponse
    {
        $agent = $this->agent();

        return response()->json([
            'requests' => $this->assist->queue($agent)->map(fn (AssistRequest $request) => $this->assist->presentForAgent($request, $agent))->all(),
        ]);
    }

    /** GET {path}/assist/requests/{id}/status — the agent's status poll. */
    public function status(string $id): JsonResponse
    {
        $agent = $this->agent();
        $request = $this->find($id, $agent);

        return response()->json($this->assist->presentForAgent($this->assist->agentPoll($agent, $request), $agent));
    }

    /**
     * GET {path}/assist/requests/{id}/stage — the live stage of a request this agent holds (claimed, offered or
     * live): the visitor's page mirrored, remote control, annotations, audio, screen share. Behind the viewer's
     * middleware and gate like every agent route, with visibleUsing() and canAssist asked again.
     */
    public function stage(string $id): View|RedirectResponse
    {
        $agent = $this->agent();
        $request = $this->find($id, $agent);

        abort_unless($request->agent_id === (string) $agent->getAuthIdentifier(), 403);

        if (! $request->isPending()) {
            return redirect()->route('session-replay.assist.index')->with('session-replay.assist-error', __('session-replay::viewer.assist.not_open'));
        }

        return view('session-replay::viewer.stage', [
            'request' => $request,
            'assist' => $this->assist,
            'config' => $this->stageConfig($request, $agent),
        ]);
    }

    /** POST {path}/assist/requests/{id}/claim — the form goes on to the stage (with Calls), the JSON stays JSON. */
    public function claim(Request $http, string $id): JsonResponse|RedirectResponse
    {
        if ($http->expectsJson() || ! $this->hasStage()) {
            return $this->act($http, $id, fn (Authenticatable $agent, AssistRequest $request) => $this->assist->claim($agent, $request), 'claimed');
        }

        $agent = $this->agent();
        $request = $this->find($id, $agent);

        try {
            $this->assist->claim($agent, $request);
        } catch (AssistRefused $refused) {
            return $this->refused($http, $refused);
        }

        return redirect()->route('session-replay.assist.stage', $request->id)->with('session-replay.assist', __('session-replay::viewer.assist.flash.claimed'));
    }

    /** POST {path}/assist/requests/{id}/withdraw */
    public function withdraw(Request $http, string $id): JsonResponse|RedirectResponse
    {
        return $this->act($http, $id, fn (Authenticatable $agent, AssistRequest $request) => $this->assist->withdraw($agent, $request), 'withdrawn');
    }

    /**
     * POST {path}/assist/requests/{id}/asks — what (control, audio, video, screen, control_masked): the agent asked the
     * visitor for it over the channel, logged next to the visitor's answer.
     */
    public function ask(Request $http, string $id): JsonResponse|RedirectResponse
    {
        $what = $http->input('what');

        if (! in_array($what, AssistRequest::ASKS, true)) {
            return response()->json(['message' => 'Ask for control, audio, video, screen or control_masked.', 'reason' => 'invalid'], 422);
        }

        return $this->act($http, $id, fn (Authenticatable $agent, AssistRequest $request) => $this->assist->ask($agent, $request, $what), 'asked');
    }

    /** POST {path}/assist/requests/{id}/end */
    public function end(Request $http, string $id): JsonResponse|RedirectResponse
    {
        return $this->act($http, $id, fn (Authenticatable $agent, AssistRequest $request) => $this->assist->end($agent, $request), 'ended');
    }

    /** POST {path}/assist/sessions/{session}/propose — the recording is bound and gated by AuthorizeViewer. */
    public function propose(Request $http, ReplaySession $session): JsonResponse|RedirectResponse
    {
        $agent = $this->agent();

        // Inside the agent's list, like any recording they open from it.
        abort_unless(SessionReplay::visibleTo(ReplaySession::query(), $agent)->whereKey($session->getKey())->exists(), 403);

        try {
            $request = $this->assist->propose($agent, $session);
        } catch (AssistRefused $refused) {
            return $this->refused($http, $refused);
        }

        if ($http->expectsJson()) {
            return response()->json($this->assist->presentForAgent($request, $agent), 201);
        }

        return $this->hasStage()
            ? redirect()->route('session-replay.assist.stage', $request->id)->with('session-replay.assist', __('session-replay::viewer.assist.flash.proposed'))
            : back()->with('session-replay.assist', __('session-replay::viewer.assist.flash.proposed'));
    }

    /** The built-in stage needs the viewer's pages and Calls (without it there is no live view to go to). */
    protected function hasStage(): bool
    {
        return Route::has('session-replay.assist.stage') && $this->assist->callsAvailable();
    }

    /**
     * What the stage's script needs: the request as the agent sees it, the agent routes, Calls' own routes (the
     * stage runs its own CallClient as the agent, kind=assist) and the strings.
     *
     * @return array<string, mixed>
     */
    protected function stageConfig(AssistRequest $request, Authenticatable $agent): array
    {
        $prefix = (string) config('session-replay.assist.calls_routes', 'calls.');
        $calls = $this->assist->callsAvailable();
        $routes = null;
        $policy = 'all';

        if ($calls && Route::has($prefix.'poll') && Route::has($prefix.'signal')) {
            $routes = [
                'poll' => route($prefix.'poll'),
                'store' => route($prefix.'store'),
                'answer' => route($prefix.'answer', ['call' => '__ID__']),
                'decline' => route($prefix.'decline', ['call' => '__ID__']),
                'hangup' => route($prefix.'hangup', ['call' => '__ID__']),
                'signal' => route($prefix.'signal', ['call' => '__ID__']),
                'leave' => route($prefix.'leave', ['call' => '__ID__']),
                'ice' => route($prefix.'ice'),
            ];
            $policy = app(Calls::class)->rtcConfiguration()['iceTransportPolicy'];
        }

        return [
            'request' => $this->assist->presentForAgent($request, $agent),
            'routes' => [
                'status' => route('session-replay.assist.status', $request->id),
                'asks' => route('session-replay.assist.asks', $request->id),
                'end' => route('session-replay.assist.end', $request->id),
                'withdraw' => route('session-replay.assist.withdraw', $request->id),
                'list' => route('session-replay.assist.index'),
            ],
            'calls' => [
                'available' => $calls,
                'routes' => $routes,
                'iceTransportPolicy' => $policy,
                'reconnectTimeout' => (int) config('packstub-calls.reconnect_timeout', 15) * 1000,
                'pollMs' => 1000,
            ],
            'video' => $this->assist->videoAllowed(),
            'csrf' => csrf_token(),
            'labels' => __('session-replay::viewer.stage'),
        ];
    }

    // ------------------------------------------------------------------

    /** @param  Closure(Authenticatable, AssistRequest): void  $action */
    protected function act(Request $http, string $id, Closure $action, string $flash): JsonResponse|RedirectResponse
    {
        $agent = $this->agent();
        $request = $this->find($id, $agent);

        try {
            $action($agent, $request);
        } catch (AssistRefused $refused) {
            return $this->refused($http, $refused);
        }

        return $http->expectsJson()
            ? response()->json($this->assist->presentForAgent($request->refresh(), $agent))
            : $this->backToList()->with('session-replay.assist', __('session-replay::viewer.assist.flash.'.$flash));
    }

    /**
     * The request, when this agent may see it: an id that is no request answers like one they may not see, so a
     * viewer of one workspace does not learn whether an id is a request of another.
     */
    protected function find(string $id, Authenticatable $agent): AssistRequest
    {
        $request = AssistRequest::query()->with('session')->find(strtolower($id));

        abort_if($request === null || ! $this->assist->visibleTo($request, $agent), 403);

        return $request;
    }

    protected function agent(): Authenticatable
    {
        $agent = SessionReplay::viewer();

        abort_unless($agent instanceof Authenticatable, 403);

        return $agent;
    }

    protected function refused(Request $http, AssistRefused $refused): JsonResponse|RedirectResponse
    {
        return $http->expectsJson()
            ? response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason, ...$refused->extra], $refused->status)
            : $this->backToList()->with('session-replay.assist-error', $refused->getMessage());
    }

    protected function backToList(): RedirectResponse
    {
        return config('session-replay.viewer.enabled', true) ? redirect()->route('session-replay.assist.index') : back();
    }
}
