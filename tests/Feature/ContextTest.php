<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Route::get('context', fn () => response()->json(Context::all()));
});

it('adds the running recording and where to watch it to the log context', function () {
    $id = (string) Str::uuid();

    $this->withUnencryptedCookie('session_replay_id', $id)->get('context')
        ->assertJson(['session_replay' => $id, 'session_replay_url' => route('session-replay.show', $id)]);
});

it('ignores a cookie that is not a recording id, and stays quiet when turned off', function () {
    $this->withUnencryptedCookie('session_replay_id', '<script>')->get('context')->assertJsonMissingPath('session_replay');

    config()->set('session-replay.context.enabled', false);

    $this->withUnencryptedCookie('session_replay_id', (string) Str::uuid())->get('context')->assertJsonMissingPath('session_replay');
});

it('leaves the context out of a page the app does not record, cookie or not', function () {
    Route::get('password/forgot', fn () => response()->json(Context::all()));
    Route::get('account/forgotten', fn () => response()->json(Context::all()))->name('password.request');
    Route::post('livewire/update', fn () => response()->json(Context::all()));
    $id = (string) Str::uuid();

    // An excluded page inside a wire:navigate session: the tab's cookie is still set, the log lines must not link to it.
    $this->withUnencryptedCookie('session_replay_id', $id)->get('password/forgot')->assertOk()->assertJsonMissingPath('session_replay')->assertJsonMissingPath('session_replay_url');
    $this->withUnencryptedCookie('session_replay_id', $id)->get('account/forgotten')->assertOk()->assertJsonMissingPath('session_replay');

    // Livewire's requests from that page are not the page: they keep the keys.
    $this->withUnencryptedCookie('session_replay_id', $id)->post('livewire/update')->assertOk()->assertJson(['session_replay' => $id]);
    $this->withUnencryptedCookie('session_replay_id', $id)->get('context')->assertJson(['session_replay' => $id]);
});

it('reads the recording from the X-Session-Replay header of a frontend on another origin', function () {
    $id = (string) Str::uuid();

    $this->getJson('context', ['X-Session-Replay' => strtoupper($id).'.abc123DEF'])
        ->assertJson(['session_replay' => $id, 'session_replay_url' => route('session-replay.show', $id)]);

    $this->getJson('context', ['X-Session-Replay' => $id])->assertJson(['session_replay' => $id]);
});

it('ignores a header that is not a recording id with an optional key', function (string $value) {
    $this->getJson('context', ['X-Session-Replay' => $value])->assertJsonMissingPath('session_replay');
})->with([
    'markup' => '<script>',
    'a key that is not letters and digits' => Str::uuid().'.<b>',
    'an empty key' => Str::uuid().'.',
    'a key that is too long' => Str::uuid().'.'.str_repeat('a', 129),
    'two ids' => Str::uuid().'.'.Str::uuid(),
    'oversized' => str_repeat('a', 200),
    'surrounding text' => 'id='.Str::uuid(),
]);

it('lets the cookie win over the header, and turns the header off with context.header null', function () {
    $cookie = (string) Str::uuid();
    $header = (string) Str::uuid();

    $this->withUnencryptedCookie('session_replay_id', $cookie)->get('context', ['X-Session-Replay' => $header])->assertJson(['session_replay' => $cookie]);

    config()->set('session-replay.context.header', null);

    // The context of the request before stays in the test's app.
    Context::flush();

    $this->getJson('context', ['X-Session-Replay' => $header])->assertJsonMissingPath('session_replay');
});
