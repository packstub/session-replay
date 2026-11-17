{{-- How long ago the recording last uploaded or sent its heartbeat: "live" is a two-minute window, this says where in it. --}}
@php($seen = $session->lastSeenSeconds())
@if ($seen !== null)
    <span class="muted">{{ $seen < 60 ? __('session-replay::viewer.seen.seconds', ['value' => $seen]) : __('session-replay::viewer.seen.minutes', ['value' => intdiv($seen, 60)]) }}</span>
@endif
