<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Packstub\SessionReplay\Support\Assist;

it('registers no assist route and keeps the ingest response as it was while Assist is off', function () {
    expect(app(Assist::class)->enabled())->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name) => str_starts_with($name, 'session-replay.assist.'))->all())->toBe([]);

    $this->ingest()->assertCreated()->assertJsonMissingPath('assist');
    $this->post('session-replay/assist/requests', ['token' => $this->token($this->user())])->assertNotFound();
});

it('keeps the Live tab and offers no help while Assist is off', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording();

    $this->actingAs($this->user())->get(route('session-replay.index', ['live' => 1]))
        ->assertOk()
        ->assertSee(route('session-replay.show', $session), false)
        ->assertSee('Live now')
        ->assertDontSee('Offer help');

    $this->get(route('session-replay.show', $session))->assertOk()->assertDontSee('Offer help');
});
