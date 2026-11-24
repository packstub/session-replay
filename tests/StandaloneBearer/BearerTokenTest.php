<?php

use Illuminate\Support\Facades\Auth;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\StandaloneTestCase;

it('hands a token to whoever the bearer token signs in, without a session or a cookie', function () {
    $user = $this->user();

    $response = $this->getJson(route('session-replay.token'), ['Origin' => StandaloneTestCase::ORIGIN, 'Authorization' => 'Bearer user-'.$user->id])->assertOk();

    expect($response->json('record'))->toBeTrue()
        ->and(ContextToken::decode($response->json('config.token'))->userId)->toBe((string) $user->id)
        ->and($response->headers->getCookies())->toBe([]);

    $response->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);
});

it('answers 401 without a valid bearer token, readable by the frontend, guests on or not', function (array $headers) {
    config()->set('session-replay.guests', true);
    $this->user();

    $this->getJson(route('session-replay.token'), ['Origin' => StandaloneTestCase::ORIGIN] + $headers)
        ->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN)
        ->assertJsonMissingPath('config');
})->with([
    'no token' => [[]],
    'a token that signs nobody in' => [['Authorization' => 'Bearer nobody']],
]);

it('lets the preflight of a listed origin send the Authorization header, and of no other', function () {
    $server = ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-requested-with'];

    $this->call('OPTIONS', route('session-replay.token'), server: ['HTTP_ORIGIN' => StandaloneTestCase::ORIGIN] + $server)
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Headers', 'authorization, x-requested-with');

    $this->call('OPTIONS', route('session-replay.token'), server: ['HTTP_ORIGIN' => 'https://evil.test'] + $server)
        ->assertNoContent()
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Headers');
});

it('refuses another origin before the bearer token is looked at', function () {
    $user = $this->user();

    $this->getJson(route('session-replay.token'), ['Origin' => 'https://evil.test', 'Authorization' => 'Bearer user-'.$user->id])
        ->assertForbidden()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('limits tokens per person with a bearer token too', function () {
    config()->set('session-replay.ingest.requests_per_minute', 1);
    $headers = ['Origin' => StandaloneTestCase::ORIGIN, 'Authorization' => 'Bearer user-'.$this->user()->id];

    $this->getJson(route('session-replay.token'), $headers)->assertOk();
    $this->getJson(route('session-replay.token'), $headers)->assertTooManyRequests()->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);

    // Someone else has a bucket of their own (the guard keeps the person of the request before in the test's app).
    Auth::forgetGuards();
    $this->getJson(route('session-replay.token'), ['Origin' => StandaloneTestCase::ORIGIN, 'Authorization' => 'Bearer user-'.$this->user()->id])->assertOk();
});
