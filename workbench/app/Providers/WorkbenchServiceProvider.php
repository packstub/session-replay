<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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

        Gate::define('viewSessionReplay', fn ($user) => true);
    }
}
