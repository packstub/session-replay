@extends('session-replay::viewer.layout')

@section('content')
    <header class="top">
        <h1>{{ __('session-replay::viewer.title') }}</h1>
        <span class="muted">
            @if (config('session-replay.assist.enabled') && \Illuminate\Support\Facades\Route::has('session-replay.assist.index'))
                <a href="{{ route('session-replay.assist.index') }}">{{ __('session-replay::viewer.assist.nav') }}</a> ·
            @endif
            {{ trans_choice('session-replay::viewer.count', $sessions->total(), ['count' => $sessions->total()]) }}
        </span>
    </header>

    @if (session('session-replay.assist'))
        <p class="card flash" role="status">{{ session('session-replay.assist') }}</p>
    @endif
    @if (session('session-replay.assist-error'))
        <p class="card flash flash--error" role="alert">{{ session('session-replay.assist-error') }}</p>
    @endif

    {{-- The Live tab: recordings with an upload or a heartbeat in the last two minutes, saying how long ago. --}}
    <nav class="tabs" aria-label="{{ __('session-replay::viewer.tabs') }}">
        <a href="{{ route('session-replay.index', array_filter($filters)) }}" @if (! $live) aria-current="page" @endif>{{ __('session-replay::viewer.all_recordings') }}</a>
        <a href="{{ route('session-replay.index', ['live' => 1] + array_filter($filters)) }}" @if ($live) aria-current="page" @endif>
            {{ __('session-replay::viewer.live_tab') }} <span class="badge {{ $liveCount ? 'ok' : '' }}">{{ $liveCount }}</span>
        </a>
    </nav>

    <form class="filters card" method="get">
        @if ($live) <input type="hidden" name="live" value="1"> @endif
        <label>{{ __('session-replay::viewer.user_id') }} <input type="text" name="user" value="{{ $filters['user'] ?? '' }}" size="10"></label>
        <label>{{ __('session-replay::viewer.from') }} <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>{{ __('session-replay::viewer.to') }} <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label class="check"><input type="checkbox" name="errors" value="1" @checked($filters['errors'] ?? false)> {{ __('session-replay::viewer.with_errors') }}</label>
        <label class="check"><input type="checkbox" name="reported" value="1" @checked($filters['reported'] ?? false)> {{ __('session-replay::viewer.reported') }}</label>
        <button class="primary" type="submit">{{ __('session-replay::viewer.filter') }}</button>
        @if (array_filter($filters))
            <a class="button" href="{{ route('session-replay.index', $live ? ['live' => 1] : []) }}">{{ __('session-replay::viewer.reset') }}</a>
        @endif
    </form>

    <div class="card table-scroll">
        @if ($sessions->isEmpty())
            <p class="empty">{{ $live ? __('session-replay::viewer.live_empty') : __('session-replay::viewer.empty') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('session-replay::viewer.person') }}</th>
                        <th>{{ __('session-replay::viewer.started') }}</th>
                        <th>{{ __('session-replay::viewer.length') }}</th>
                        <th>{{ __('session-replay::viewer.pages') }}</th>
                        <th>{{ __('session-replay::viewer.errors') }}</th>
                        <th>{{ __('session-replay::viewer.vitals') }}</th>
                        <th>{{ __('session-replay::viewer.first_page') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>
                                {{ $userLabel($session) }}
                                @if ($session->impersonator_id) <span class="badge warn" title="{{ __('session-replay::viewer.impersonated_by_id', ['id' => $session->impersonator_id]) }}">{{ __('session-replay::viewer.impersonated') }}</span> @endif
                                @if ($session->reported) <span class="badge danger">{{ __('session-replay::viewer.reported_badge') }}</span> @endif
                            </td>
                            <td title="{{ $session->started_at?->toDayDateTimeString() }}">
                                {{ $session->started_at?->diffForHumans() }}
                                @if ($session->isLive())
                                    <span class="badge ok">{{ __('session-replay::viewer.live') }}</span>
                                    @include('session-replay::viewer.partials.last-seen', ['session' => $session])
                                @endif
                            </td>
                            <td>{{ $session->durationForHumans() }}</td>
                            <td>{{ $session->page_count }}</td>
                            <td>
                                @if ($session->error_count) <span class="badge danger">{{ $session->error_count }}</span> @else <span class="muted">0</span> @endif
                                @if ($session->rage_click_count) <span class="badge warn" title="{{ __('session-replay::viewer.rage_clicks') }}">{{ $session->rage_click_count }}×</span> @endif
                            </td>
                            <td>
                                @php($rating = $session->vitalsRating())
                                @if ($rating) <span class="badge {{ ['good' => 'ok', 'needs-improvement' => 'warn', 'poor' => 'danger'][$rating] }}">{{ __('session-replay::viewer.ratings.'.$rating) }}</span> @else <span class="muted">·</span> @endif
                            </td>
                            <td class="url muted" title="{{ $session->entry_url }}">{{ \Illuminate\Support\Str::after((string) $session->entry_url, '://') }}</td>
                            <td class="actions">
                                <a href="{{ route('session-replay.show', $session) }}">{{ __('session-replay::viewer.watch') }}</a>
                                @include('session-replay::viewer.partials.offer-help', ['session' => $session, 'assist' => $assist])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($sessions->hasPages())
        <nav class="pages">
            <span>@if ($sessions->previousPageUrl()) <a href="{{ $sessions->previousPageUrl() }}">← {{ __('session-replay::viewer.newer') }}</a> @endif</span>
            <span class="muted">{{ __('session-replay::viewer.page_of', ['page' => $sessions->currentPage(), 'pages' => $sessions->lastPage()]) }}</span>
            <span>@if ($sessions->nextPageUrl()) <a href="{{ $sessions->nextPageUrl() }}">{{ __('session-replay::viewer.older') }} →</a> @endif</span>
        </nav>
    @endif
@endsection
