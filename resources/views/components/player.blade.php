@props(['session', 'markers' => true, 'copyLink' => true, 'assets' => true])

@php
    /** @var \Packstub\SessionReplay\Models\ReplaySession|string $session */
    $sessionId = $session instanceof \Packstub\SessionReplay\Models\ReplaySession ? $session->getKey() : (string) $session;
@endphp

{{-- The data routes check the viewSessionReplay gate for this recording on every request, wherever this is embedded. --}}
@if ($assets)
    @once
        {{ \Packstub\SessionReplay\Facades\SessionReplay::playerAssets() }}
    @endonce
@endif

<div
    {{ $attributes }}
    data-session-replay-player
    data-manifest="{{ \Packstub\SessionReplay\Facades\SessionReplay::route('manifest', ['session' => $sessionId]) }}"
    data-markers="{{ $markers ? 'true' : 'false' }}"
    data-copy-link="{{ $copyLink ? 'true' : 'false' }}"
    data-labels="{{ json_encode(__('session-replay::player'), JSON_UNESCAPED_UNICODE) }}"
    data-replay-block
></div>
