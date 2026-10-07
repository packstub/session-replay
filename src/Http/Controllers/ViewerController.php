<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;

/** The built-in pages: a list and a player. Deliberately small; a panel does more. */
class ViewerController
{
    /** GET {path} */
    public function index(Request $request): View
    {
        $sessions = SessionReplay::visibleTo(ReplaySession::query())
            ->with('user')
            ->when($this->text($request->query('user')), fn ($query, string $user) => $query->where('user_id', $user))
            ->when($request->boolean('errors'), fn ($query) => $query->withErrors())
            ->when($this->date($request->query('from')), fn ($query, Carbon $from) => $query->where('started_at', '>=', $from->startOfDay()))
            ->when($this->date($request->query('to')), fn ($query, Carbon $to) => $query->where('started_at', '<=', $to->endOfDay()))
            ->latest('started_at')
            ->paginate((int) config('session-replay.viewer.per_page', 25))
            ->withQueryString();

        return view('session-replay::viewer.index', [
            'sessions' => $sessions,
            'filters' => array_filter($request->only(['user', 'errors', 'from', 'to']), 'is_string'),
            'userLabel' => fn (ReplaySession $session): string => $this->userLabel($session),
        ]);
    }

    /** GET {path}/{session} */
    public function show(ReplaySession $session): View
    {
        $list = route('session-replay.index');
        $previous = url()->previous();

        return view('session-replay::viewer.show', [
            'session' => $session->load('user'),
            'userLabel' => $this->userLabel($session),
            // Back to the list as it was filtered; the previous URL comes from the Referer, so only the list itself.
            'back' => $previous === $list || str_starts_with($previous, $list.'?') ? $previous : $list,
        ]);
    }

    protected function userLabel(ReplaySession $session): string
    {
        if ($session->user_id === null) {
            return __('session-replay::viewer.guest');
        }

        $attribute = (string) config('session-replay.viewer.user_label', 'email');
        $label = $session->user?->getAttribute($attribute);

        return is_scalar($label) && (string) $label !== '' ? (string) $label : '#'.$session->user_id;
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
