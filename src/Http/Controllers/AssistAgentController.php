<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** POST {path}/assist/requests/{id}/claim */
    public function claim(Request $http, string $id): JsonResponse|RedirectResponse
    {
        return $this->act($http, $id, fn (Authenticatable $agent, AssistRequest $request) => $this->assist->claim($agent, $request), 'claimed');
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

        return $http->expectsJson()
            ? response()->json($this->assist->presentForAgent($request, $agent), 201)
            : back()->with('session-replay.assist', __('session-replay::viewer.assist.flash.proposed'));
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
