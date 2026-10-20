@extends('session-replay::viewer.layout')

@section('title', $userLabel)

@section('content')
    <header class="top">
        <h1>{{ $userLabel }} <span class="muted">· {{ $session->started_at?->toDayDateTimeString() }}</span></h1>
        <a href="{{ $back }}">← {{ __('session-replay::viewer.all_recordings') }}</a>
    </header>

    <dl class="facts card">
        <div><dt>{{ __('session-replay::viewer.length') }}</dt><dd>{{ $session->durationForHumans() }} @if ($session->isLive()) <span class="badge ok">{{ __('session-replay::viewer.live') }}</span> @endif</dd></div>
        <div><dt>{{ __('session-replay::viewer.pages') }}</dt><dd>{{ $session->page_count }}</dd></div>
        <div><dt>{{ __('session-replay::viewer.errors') }}</dt><dd>{{ $session->error_count }}</dd></div>
        <div><dt>{{ __('session-replay::viewer.device') }}</dt><dd>{{ $session->device ?? '·' }} @if ($session->viewport_width) <span class="muted">{{ $session->viewport_width }}×{{ $session->viewport_height }}</span> @endif</dd></div>
        <div><dt>{{ __('session-replay::viewer.size') }}</dt><dd>{{ \Illuminate\Support\Number::fileSize($session->bytes) }}</dd></div>
        @if ($session->impersonator_id)
            <div><dt>{{ __('session-replay::viewer.impersonated_by') }}</dt><dd>{{ $impersonatorLabel }}</dd></div>
        @endif
        <div><dt>{{ __('session-replay::viewer.first_page') }}</dt><dd title="{{ $session->entry_url }}">{{ $session->entry_url }}</dd></div>
    </dl>

    @if ($session->truncated)
        <p class="muted">{{ __('session-replay::viewer.truncated') }}</p>
    @endif

    <x-session-replay::player :session="$session" />
@endsection
