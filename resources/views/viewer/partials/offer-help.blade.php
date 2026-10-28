{{--
    Assist on a live recording: "Offer help" (the agent's proposal, with Calls), the stage of the agent's own request,
    or what the request going for it is. $assist is null while Assist is off.
--}}
@if ($assist && $session->isLive())
    @php($pending = $assist['requests']->get($session->getKey()))
    @if ($pending)
        @if ($assist['holds']($pending) && $assist['stage'] && $assist['offer'])
            <a class="button primary" href="{{ route('session-replay.assist.stage', $pending->id) }}">{{ __('session-replay::viewer.assist.open_stage') }}</a>
        @elseif (\Illuminate\Support\Facades\Route::has('session-replay.assist.index'))
            <a class="badge warn" href="{{ route('session-replay.assist.index') }}">{{ $pending->openedByAgent() ? __('session-replay::viewer.assist.offered_by', ['name' => $pending->agent_name]) : __('session-replay::viewer.assist.asked') }}</a>
        @endif
    @elseif ($assist['offer'] && ! ($session->user_id !== null && $session->user_id === $assist['agent']))
        <form method="post" action="{{ route('session-replay.assist.propose', $session) }}">
            @csrf
            <button class="primary" type="submit">{{ __('session-replay::viewer.assist.offer') }}</button>
        </form>
    @endif
@endif
