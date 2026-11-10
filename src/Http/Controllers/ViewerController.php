<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;

/**
 * The built-in pages: a list and a player. Deliberately small; a panel does more.
 *
 * @internal Wired by the package; not part of the public API.
 */
class ViewerController
{
    /** GET {path} — ?live=1 is the Live tab: recordings with an upload or a heartbeat in the last two minutes. */
    public function index(Request $request): View
    {
        $live = $request->boolean('live');

        $sessions = SessionReplay::visibleTo(ReplaySession::query())
            ->with('user')
            ->withExists(['markers as reported' => fn ($query) => $query->where('type', ReplayMarker::REPORT)])
            ->when($this->text($request->query('user')), fn ($query, string $user) => $query->where('user_id', $user))
            ->when($request->boolean('errors'), fn ($query) => $query->withErrors())
            ->when($request->boolean('reported'), fn ($query) => $query->reported())
            ->when($this->date($request->query('from')), fn ($query, Carbon $from) => $query->where('started_at', '>=', $from->startOfDay()))
            ->when($this->date($request->query('to')), fn ($query, Carbon $to) => $query->where('started_at', '<=', $to->endOfDay()))
            ->when($live, fn ($query) => $query->live()->latest('last_activity_at'), fn ($query) => $query->latest('started_at'))
            ->paginate((int) config('session-replay.viewer.per_page', 25))
            ->withQueryString();

        return view('session-replay::viewer.index', [
            'sessions' => $sessions,
            'filters' => array_filter($request->only(['user', 'errors', 'reported', 'from', 'to']), 'is_string'),
            'live' => $live,
            'liveCount' => SessionReplay::visibleTo(ReplaySession::query())->live()->count(),
            'userLabel' => fn (ReplaySession $session): string => $this->userLabel($session),
            'assist' => $this->assist($sessions->getCollection()->all()),
        ]);
    }

    /** GET {path}/{session} */
    public function show(ReplaySession $session): View
    {
        $list = route('session-replay.index');
        $previous = url()->previous();

        return view('session-replay::viewer.show', [
            'session' => $session->load(['user', 'impersonator']),
            'userLabel' => $this->userLabel($session),
            // With impersonator_type the impersonator is a model and gets a label like the person; a bare key is shown as #key.
            'impersonatorLabel' => $session->impersonator_id === null ? null : $this->label($session->impersonator, $session->impersonator_id),
            // Back to the list as it was filtered; the previous URL comes from the Referer, so only the list itself.
            'back' => $previous === $list || str_starts_with($previous, $list.'?') ? $previous : $list,
            'assist' => $this->assist([$session]),
        ]);
    }

    /**
     * What the pages show about Assist for these recordings, or null while it is off: whether help can be offered
     * (Calls installed), the request going for each recording, and who is looking (their own requests open the stage).
     *
     * @param  array<int, ReplaySession>  $sessions
     * @return array{offer: bool, stage: bool, agent: ?string, holds: \Closure(AssistRequest): bool, requests: Collection<string, AssistRequest>}|null
     */
    protected function assist(array $sessions): ?array
    {
        $assist = app(Assist::class);

        if (! $assist->enabled() || ! Route::has('session-replay.assist.propose')) {
            return null;
        }

        $viewer = SessionReplay::viewer();
        $ids = array_map(fn (ReplaySession $session) => $session->getKey(), $sessions);

        return [
            'offer' => $assist->callsAvailable(),
            'stage' => Route::has('session-replay.assist.stage'),
            'agent' => $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null,
            // The same check as the agent routes: key, type and guard.
            'holds' => fn (AssistRequest $request): bool => $assist->holds($viewer, $request),
            'requests' => $ids === [] ? collect() : AssistRequest::query()->pending()->whereIn('replay_session_id', $ids)->get()->keyBy('replay_session_id'),
        ];
    }

    protected function userLabel(ReplaySession $session): string
    {
        if ($session->user_id === null) {
            return __('session-replay::viewer.guest');
        }

        return $this->label($session->user, $session->user_id);
    }

    /** viewer.user_label of the model, or #key when there is no model or the attribute is empty. */
    protected function label(mixed $person, string $key): string
    {
        $label = $person instanceof Model ? $person->getAttribute((string) config('session-replay.viewer.user_label', 'email')) : null;

        return is_scalar($label) && (string) $label !== '' ? (string) $label : '#'.$key;
    }

    /** A filter's value when it is one non-empty string; ?user[]=1 and the like are ignored. */
    protected function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
