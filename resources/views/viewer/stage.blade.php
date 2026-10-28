@extends('session-replay::viewer.layout')

@section('title', __('session-replay::viewer.stage.title', ['person' => $assist->userLabel($request)]))

@push('head')
    @php($nonce = \Illuminate\Support\Facades\Vite::cspNonce())
    <link rel="stylesheet" href="{{ \Packstub\SessionReplay\Facades\SessionReplay::scriptUrl('stage.css') }}" @if ($nonce) nonce="{{ $nonce }}" @endif>
    <script src="{{ \Packstub\SessionReplay\Facades\SessionReplay::scriptUrl('stage.js') }}" defer @if ($nonce) nonce="{{ $nonce }}" @endif></script>
@endpush

@section('content')
    @php($s = 'session-replay::viewer.stage.')
    <header class="top">
        <h1>{{ __($s.'title', ['person' => $assist->userLabel($request)]) }}</h1>
        <a href="{{ route('session-replay.assist.index') }}">← {{ __('session-replay::viewer.assist.title') }}</a>
    </header>

    @if (session('session-replay.assist'))
        <p class="card flash" role="status">{{ session('session-replay.assist') }}</p>
    @endif

    <div class="sr-stage" data-session-replay-stage data-config="{{ json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}">
        <section class="sr-stage__main" aria-label="{{ __($s.'view') }}">
            <p class="card sr-stage__status" role="status" aria-live="polite">
                <strong data-ref="status">{{ __($s.'status.connecting') }}</strong>
                <span class="muted" data-ref="seen"></span>
            </p>

            {{-- The visitor's page, replayed live in rrweb's sandboxed iframe (no scripts), or their screen as video. --}}
            <div class="card sr-stage__screen" data-ref="screen" data-replay-block>
                <p class="sr-stage__placeholder" data-ref="placeholder">{{ __($s.'placeholder.waiting') }}</p>
                <div class="sr-stage__mirror" data-ref="mirror"></div>
                <video class="sr-stage__video" data-ref="video" playsinline autoplay muted hidden aria-label="{{ __($s.'screen_video') }}"></video>
                <canvas class="sr-stage__ink" data-ref="ink" aria-hidden="true"></canvas>
                <div class="sr-stage__overlay" data-ref="overlay" tabindex="-1" role="application" aria-label="{{ __($s.'overlay') }}"></div>
                <div class="sr-stage__editor" data-ref="editor" hidden>
                    <input type="text" data-ref="editor-text" autocomplete="off" aria-label="{{ __($s.'editor') }}">
                    <select data-ref="editor-select" aria-label="{{ __($s.'editor') }}" hidden></select>
                    <small>{{ __($s.'editor_hint') }}</small>
                </div>
            </div>
        </section>

        <aside class="sr-stage__side">
            <div class="card sr-stage__tools">
                <h2>{{ __($s.'tools') }}</h2>

                <div class="sr-stage__group">
                    <div class="sr-stage__row" role="group" aria-label="{{ __($s.'view') }}">
                        <button type="button" data-action="mirror" aria-pressed="true">{{ __($s.'buttons.mirror') }}</button>
                        <button type="button" data-action="screen" aria-pressed="false" aria-describedby="sr-why-screen">{{ __($s.'buttons.ask_screen') }}</button>
                    </div>
                    <small class="sr-stage__why" id="sr-why-screen" data-why="screen" hidden></small>
                </div>

                <div class="sr-stage__group">
                    <div class="sr-stage__row">
                        <button type="button" class="primary" data-action="control" aria-pressed="false" aria-describedby="sr-why-control">{{ __($s.'buttons.control') }}</button>
                        <button type="button" data-action="draw" aria-pressed="false" aria-describedby="sr-why-draw">{{ __($s.'buttons.draw') }}</button>
                    </div>
                    <small class="sr-stage__why" id="sr-why-control" data-why="control" hidden></small>
                    <small class="sr-stage__why" id="sr-why-draw" data-why="draw" hidden></small>
                </div>

                <div class="sr-stage__group">
                    <div class="sr-stage__row">
                        <button type="button" data-action="audio" aria-describedby="sr-why-audio">{{ __($s.'buttons.audio') }}</button>
                        <button type="button" data-action="video" aria-describedby="sr-why-video" @unless ($config['video']) hidden @endunless>{{ __($s.'buttons.video') }}</button>
                        <button type="button" data-action="share" aria-pressed="false" aria-describedby="sr-why-share">{{ __($s.'buttons.share') }}</button>
                    </div>
                    <small class="sr-stage__why" id="sr-why-audio" data-why="audio" hidden></small>
                    <small class="sr-stage__why" id="sr-why-video" data-why="video" hidden></small>
                    <small class="sr-stage__why" id="sr-why-share" data-why="share" hidden></small>
                </div>

                <div class="sr-stage__row">
                    <button type="button" data-action="withdraw" hidden>{{ __($s.'buttons.withdraw') }}</button>
                    <button type="button" class="danger" data-action="end" hidden>{{ __($s.'buttons.end') }}</button>
                </div>
            </div>

            <div class="card sr-stage__audio" data-ref="audiobar" hidden>
                <h2>{{ __($s.'call') }}</h2>
                <video data-ref="face" playsinline autoplay muted hidden aria-label="{{ __($s.'camera_video') }}"></video>
                <audio data-ref="audio" autoplay></audio>
                <div class="sr-stage__row">
                    <button type="button" data-ref="mute" aria-pressed="false">{{ __($s.'buttons.mute') }}</button>
                    <button type="button" data-ref="end-audio">{{ __($s.'buttons.end_audio') }}</button>
                </div>
            </div>

            <div class="card sr-stage__about">
                <h2>{{ __($s.'about') }}</h2>
                <dl>
                    <div><dt>{{ __('session-replay::viewer.person') }}</dt><dd>{{ $assist->userLabel($request) }}</dd></div>
                    @if ($request->message)
                        {{-- The visitor's own words: escaped text, never markup. --}}
                        <div><dt>{{ __('session-replay::viewer.assist.message') }}</dt><dd>{{ $request->message }}</dd></div>
                    @endif
                    <div><dt>{{ __($s.'now_on') }}</dt><dd data-ref="url">{{ $request->page_url ?? '·' }}</dd></div>
                    @if ($request->session)
                        <div><dt>{{ __('session-replay::viewer.assist.recording') }}</dt><dd><a href="{{ route('session-replay.show', $request->session) }}" target="_blank" rel="noopener">{{ __('session-replay::viewer.watch') }}</a></dd></div>
                    @endif
                </dl>
            </div>

            <div class="card sr-stage__log">
                <h2>{{ __($s.'log') }}</h2>
                <ol data-ref="notes" aria-live="polite"></ol>
            </div>
        </aside>
    </div>
@endsection
