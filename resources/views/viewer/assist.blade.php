@extends('session-replay::viewer.layout')

@section('title', __('session-replay::viewer.assist.title'))

@section('content')
    <header class="top">
        <h1>{{ __('session-replay::viewer.assist.title') }}</h1>
        <a href="{{ route('session-replay.index') }}">{{ __('session-replay::viewer.all_recordings') }}</a>
    </header>

    @if (session('session-replay.assist'))
        <p class="card flash" role="status">{{ session('session-replay.assist') }}</p>
    @endif
    @if (session('session-replay.assist-error'))
        <p class="card flash flash--error" role="alert">{{ session('session-replay.assist-error') }}</p>
    @endif

    @php($hasStage = \Illuminate\Support\Facades\Route::has('session-replay.assist.stage') && $assist->callsAvailable())

    <div class="card table-scroll">
        @if ($requests->isEmpty())
            <p class="empty">{{ __('session-replay::viewer.assist.empty') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('session-replay::viewer.person') }}</th>
                        <th>{{ __('session-replay::viewer.assist.message') }}</th>
                        <th>{{ __('session-replay::viewer.assist.page') }}</th>
                        <th>{{ __('session-replay::viewer.assist.waiting') }}</th>
                        <th>{{ __('session-replay::viewer.assist.status') }}</th>
                        <th>{{ __('session-replay::viewer.assist.recording') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $request)
                        @php($mine = $assist->holds($agent, $request))
                        <tr>
                            <td>{{ $assist->userLabel($request) }}</td>
                            {{-- The visitor's own words: escaped text, never markup. --}}
                            <td class="message">{{ $request->message ?? '·' }}</td>
                            <td class="url muted" title="{{ $request->page_url }}">{{ $request->page_url ? \Illuminate\Support\Str::after($request->page_url, '://') : '·' }}</td>
                            <td title="{{ $request->created_at->toDayDateTimeString() }}">
                                {{ $request->created_at->diffForHumans(null, true) }}
                                @if ($request->visitorIsHere())
                                    <span class="badge ok">{{ __('session-replay::viewer.assist.here') }}</span>
                                @else
                                    <span class="badge warn">{{ __('session-replay::viewer.assist.gone') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $request->status === 'active' ? 'ok' : '' }}">{{ __('session-replay::viewer.assist.statuses.'.$request->status) }}</span>
                                @if ($request->agent_name)
                                    <span class="muted">{{ __($request->openedByAgent() ? 'session-replay::viewer.assist.offered_by' : 'session-replay::viewer.assist.claimed_by', ['name' => $request->agent_name]) }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($request->session)
                                    <a href="{{ route('session-replay.show', $request->session) }}">{{ __('session-replay::viewer.watch') }}</a>
                                @else
                                    <span class="muted">{{ __('session-replay::viewer.assist.no_recording') }}</span>
                                @endif
                            </td>
                            <td class="actions">
                                @if ($mine && $hasStage && in_array($request->status, ['open', 'claimed', 'active'], true))
                                    <a class="button primary" href="{{ route('session-replay.assist.stage', $request->id) }}">{{ __('session-replay::viewer.assist.open_stage') }}</a>
                                @endif
                                @if ($request->status === 'open' && ! $request->openedByAgent())
                                    <form method="post" action="{{ route('session-replay.assist.claim', $request->id) }}">
                                        @csrf
                                        <button class="primary" type="submit">{{ __('session-replay::viewer.assist.claim') }}</button>
                                    </form>
                                @elseif ($mine && in_array($request->status, ['open', 'claimed'], true))
                                    <form method="post" action="{{ route('session-replay.assist.withdraw', $request->id) }}">
                                        @csrf
                                        <button type="submit">{{ __('session-replay::viewer.assist.withdraw') }}</button>
                                    </form>
                                @endif
                                @if ($mine && in_array($request->status, ['claimed', 'active'], true))
                                    <form method="post" action="{{ route('session-replay.assist.end', $request->id) }}">
                                        @csrf
                                        <button type="submit">{{ __('session-replay::viewer.assist.end') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
