<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/** A page to record and a viewer anyone may open: `composer serve`, click around, then visit /session-replay. */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config()->set('auth.providers.users.model', User::class);

        // The second page looks the same on every visit: its snapshot is stored once, whoever records it.
        config()->set('session-replay.snapshots.share_paths', ['second']);

        // SESSION_REPLAY_MODE=on_error SESSION_REPLAY_ASK=toast composer serve: the question as a toast, and the
        // "Report a problem" button that sends the window without it.
        config()->set('session-replay.on_error.ask', env('SESSION_REPLAY_ASK', false));
        // SESSION_REPLAY_ASK_AGAIN=1 asks again after a "no"; SESSION_REPLAY_ASK_TIMEOUT=5 and SESSION_REPLAY_BUFFER=10 (seconds)
        // make the toast and the window quick to try.
        config()->set('session-replay.on_error.ask_again', (bool) env('SESSION_REPLAY_ASK_AGAIN', false));
        config()->set('session-replay.on_error.ask_timeout_seconds', (int) env('SESSION_REPLAY_ASK_TIMEOUT', 60));
        config()->set('session-replay.on_error.buffer_seconds', (int) env('SESSION_REPLAY_BUFFER', 60));
        // SESSION_REPLAY_MASK_ALL_TEXT=1: layout-only recordings, to check what stays readable.
        config()->set('session-replay.privacy.mask_all_text', (bool) env('SESSION_REPLAY_MASK_ALL_TEXT', false));
        config()->set('session-replay.privacy.mask_all_inputs', (bool) env('SESSION_REPLAY_MASK_ALL_INPUTS', true));

        Gate::define('viewSessionReplay', fn ($user) => true);

        // "Open in logs" on a server error's details: a Log Viewer search for the report id, as an app would register it.
        SessionReplay::logUrlUsing(fn (ReplayMarker $marker): ?string => isset($marker->payload['report']) ? url('/log-viewer?query='.$marker->payload['report']) : null);
    }
}
