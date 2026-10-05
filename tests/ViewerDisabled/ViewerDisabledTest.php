<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;

beforeEach(fn () => Gate::define('viewSessionReplay', fn ($user) => true));

it('removes the pages and keeps what the player component needs', function () {
    expect(Route::has('session-replay.index'))->toBeFalse()
        ->and(Route::has('session-replay.show'))->toBeFalse()
        ->and(Route::has('session-replay.manifest'))->toBeTrue()
        ->and(Route::has('session-replay.chunk'))->toBeTrue()
        ->and(Route::has('session-replay.snapshot'))->toBeTrue();

    $session = $this->recording($user = $this->user());

    $this->actingAs($user)->getJson(route('session-replay.manifest', $session))->assertOk();

    // Nowhere to link to, unless the app says where replays are watched.
    expect($session->url())->toBeNull();

    SessionReplay::urlUsing(fn (ReplaySession $session) => 'https://app.test/support/replays/'.$session->id);

    expect($session->url())->toBe('https://app.test/support/replays/'.$session->id);
});
