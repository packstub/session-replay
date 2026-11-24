<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Support\AllowedOrigins;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\StandaloneTestCase;

/** The token route as a frontend on another origin asks for it. */
function fetchToken(?string $origin = StandaloneTestCase::ORIGIN): TestResponse
{
    return test()->flushHeaders()->withHeaders(array_filter(['Origin' => $origin]))->getJson(route('session-replay.token'));
}

it('answers record false for whoever the app does not record', function () {
    fetchToken()->assertOk()->assertExactJson(['record' => false]);

    $this->actingAs($this->user());

    SessionReplay::recordWhen(fn () => false);
    fetchToken()->assertOk()->assertExactJson(['record' => false]);

    SessionReplay::recordWhen(fn () => true);
    config()->set('session-replay.enabled', false);
    fetchToken()->assertOk()->assertExactJson(['record' => false]);
});

it('hands a signed-in person the config and a token that names them, their workspace and impersonator', function () {
    $user = $this->user();
    $team = $this->team();
    SessionReplay::tenantUsing(fn () => $team)->impersonatorUsing(fn () => 42)->propertiesUsing(fn () => ['plan' => 'pro']);

    $response = $this->actingAs($user)->withHeaders(['Origin' => StandaloneTestCase::ORIGIN])->getJson(route('session-replay.token'))->assertOk();
    $token = ContextToken::decode($response->json('config.token'));

    expect($response->json('record'))->toBeTrue()
        ->and($response->json('config.ingestUrl'))->toBe(route('session-replay.ingest'))
        ->and($response->json('config.identity'))->toBeString()
        ->and($response->json('expires_at'))->toBeString()
        ->and($response->json('expires_in'))->toBe(7 * 24 * 60 * 60)
        ->and($token->userId)->toBe((string) $user->id)
        ->and($token->tenantId)->toBe((string) $team->id)
        ->and($token->impersonatorId)->toBe('42')
        ->and($token->properties)->toBe(['plan' => 'pro']);
});

it('says for how many seconds the token is taken, so the package can renew it without the server\'s clock', function () {
    config()->set('session-replay.ingest.token_days', 2);
    $this->travelTo('2026-11-24 10:00:00');

    $response = $this->actingAs($this->user())->withHeaders(['Origin' => StandaloneTestCase::ORIGIN])->getJson(route('session-replay.token'))->assertOk();
    expect($response->json('expires_in'))->toBe(172800)
        ->and($response->json('expires_at'))->toBe('2026-11-26T10:00:00+00:00');

    // The same count the upload routes go by (the token's own clock is time(), not the test's).
    $aged = fn (int $seconds): string => (new ContextToken(issuedAt: time() - $seconds))->encode();

    expect(ContextToken::decode($aged(172800 - 60)))->not->toBeNull()
        ->and(ContextToken::decode($aged(172800 + 1)))->toBeNull();
});

it('signs a pseudonym instead of the person with privacy.anonymous', function () {
    config()->set('session-replay.privacy.anonymous', true);

    $token = ContextToken::decode($this->actingAs($this->user())->withHeaders(['Origin' => StandaloneTestCase::ORIGIN])->getJson(route('session-replay.token'))->json('config.token'));

    expect($token->userId)->toBeNull()->and($token->pseudonym)->toBeString();
});

it('records guests only with guests on, and their token never expires with guest_tokens_expire off', function () {
    config()->set(['session-replay.guests' => true, 'session-replay.ingest.guest_tokens_expire' => false]);

    $response = fetchToken()->assertOk();

    expect($response->json('record'))->toBeTrue()
        ->and($response->json('config.identity'))->toBeNull()
        ->and($response->json('expires_at'))->toBeNull()
        ->and($response->json('expires_in'))->toBeNull();
});

it('leaves out Assist, shared snapshots and, across origins, the cookie', function () {
    config()->set(['session-replay.assist.enabled' => true, 'session-replay.snapshots.share_paths' => ['*']]);
    $this->actingAs($this->user());

    $cross = fetchToken()->assertOk();

    expect($cross->json('config.assist'))->toBeNull()
        ->and($cross->json('config.snapshots.share'))->toBeFalse()
        ->and($cross->json('config.cookie'))->toBeNull();

    // The app's own origin (or none, a same-origin GET) keeps the cookie.
    expect(fetchToken(null)->json('config.cookie'))->toBe('session_replay_id')
        ->and(fetchToken(rtrim(url('/'), '/'))->json('config.cookie'))->toBe('session_replay_id');
});

it('is never cached and varies with the origin and the sign-in', function () {
    $response = fetchToken()->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Vary'))->toContain('Origin')->toContain('Cookie')->toContain('Authorization');
});

it('names a listed origin with credentials on the token route', function () {
    fetchToken()->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    // Listed as "HTTP://LocalHost:5173/": read as the origin a browser sends.
    fetchToken(StandaloneTestCase::DEV_ORIGIN)->assertOk()->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::DEV_ORIGIN);
});

it('refuses the token to any other origin, a near miss or "null", before the app\'s middleware runs', function (string $origin) {
    $this->actingAs($this->user());

    fetchToken($origin)->assertForbidden()
        ->assertJson(['record' => false])
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
})->with([
    'another site' => 'https://evil.test',
    'another port' => 'https://app.example.com:8443',
    'another scheme' => 'http://app.example.com',
    'a subdomain' => 'https://www.app.example.com',
    'a suffix' => 'https://app.example.com.evil.test',
    'null' => 'null',
    'a wildcard' => '*',
]);

it('refuses a request another page made without an Origin header (an img or script tag, a link)', function (string $site) {
    $this->actingAs($this->user());

    test()->flushHeaders()->getJson(route('session-replay.token'), ['Sec-Fetch-Site' => $site])
        ->assertForbidden()
        ->assertJson(['record' => false])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->with(['cross-site', 'same-site', 'Cross-Site']);

it('still answers the app\'s own pages, a typed address and a client that is no browser', function (?string $site) {
    $this->actingAs($this->user());

    test()->flushHeaders()->getJson(route('session-replay.token'), array_filter(['Sec-Fetch-Site' => $site]))
        ->assertOk()
        ->assertJson(['record' => true])
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with(['same-origin', 'none', null]);

it('answers a listed origin whose browser says cross-site', function () {
    test()->flushHeaders()->getJson(route('session-replay.token'), ['Origin' => StandaloneTestCase::ORIGIN, 'Sec-Fetch-Site' => 'cross-site'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);
});

it('ignores entries that are no origin, and logs each once', function () {
    config()->set('session-replay.ingest.origins', [StandaloneTestCase::ORIGIN, 'https://*.unique-'.Str::random(8).'.test']);
    Log::spy();

    expect(app(AllowedOrigins::class)->configured())->toBe([StandaloneTestCase::ORIGIN]);
    app(AllowedOrigins::class)->configured();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'is not an origin'));

    // "*" was in the list at boot and lets nobody in.
    fetchToken('https://anything.test')->assertForbidden();
});

it('lets a listed origin read the upload answers, never with credentials', function () {
    $this->withHeaders(['Origin' => StandaloneTestCase::ORIGIN]);

    $this->ingest()->assertCreated()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN)
        ->assertHeader('Access-Control-Expose-Headers', 'Retry-After')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    expect($this->ingest()->headers->get('Vary'))->toContain('Origin');
});

it('still takes nothing but the signed token on the upload routes', function () {
    $this->withHeaders(['Origin' => StandaloneTestCase::ORIGIN]);

    $this->ingest(['token' => 'forged'])->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);
});

it('names no other origin on an upload, which the server still answers as before', function () {
    $this->withHeaders(['Origin' => 'https://evil.test']);

    $this->ingest()->assertCreated()->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('keeps the CORS headers on a refusal from the limiters, so the recorder can read Retry-After', function () {
    config()->set('session-replay.ingest.requests_per_minute', 1);
    $token = $this->token($this->user());
    $this->withHeaders(['Origin' => StandaloneTestCase::ORIGIN]);

    $this->ingest(['token' => $token])->assertCreated();

    $this->ingest(['token' => $token])->assertTooManyRequests()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN)
        ->assertHeader('Access-Control-Expose-Headers', 'Retry-After')
        ->assertHeader('Retry-After');
});

it('answers the preflight of a listed origin with what each route takes', function () {
    $ingest = $this->call('OPTIONS', route('session-replay.ingest'), server: [
        'HTTP_ORIGIN' => StandaloneTestCase::ORIGIN,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, X-Evil',
    ]);

    $ingest->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN)
        ->assertHeader('Access-Control-Allow-Methods', 'POST')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type')
        ->assertHeader('Access-Control-Max-Age', '600')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    foreach (['ingest.asset', 'ingest.snapshot'] as $name) {
        $this->call('OPTIONS', route('session-replay.'.$name), server: ['HTTP_ORIGIN' => StandaloneTestCase::ORIGIN, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);
    }

    $this->call('OPTIONS', route('session-replay.token'), server: [
        'HTTP_ORIGIN' => StandaloneTestCase::ORIGIN,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-requested-with,cookie',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Methods', 'GET')
        ->assertHeader('Access-Control-Allow-Headers', 'authorization, x-requested-with')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('answers the preflight of any other origin without a permission', function () {
    $this->call('OPTIONS', route('session-replay.ingest'), server: ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
        ->assertNoContent()
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Methods');
});

it('never counts a preflight against the upload limits', function () {
    config()->set('session-replay.ingest.requests_per_minute', 1);

    foreach (range(1, 3) as $ignored) {
        $this->call('OPTIONS', route('session-replay.ingest'), server: ['HTTP_ORIGIN' => StandaloneTestCase::ORIGIN, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])->assertNoContent();
    }

    // The bucket of uploads without a token is untouched: the first one is refused for its token, not the limit.
    $this->withHeaders(['Origin' => StandaloneTestCase::ORIGIN])->ingest(['token' => 'forged'])->assertUnauthorized();
});

it('limits tokens per person, and for all guests together', function () {
    config()->set(['session-replay.guests' => true, 'session-replay.ingest.guest_tokens_per_minute' => 2]);

    fetchToken()->assertOk();
    fetchToken()->assertOk();
    fetchToken()->assertTooManyRequests()->assertHeader('Access-Control-Allow-Origin', StandaloneTestCase::ORIGIN);

    // A signed-in person has a bucket of their own.
    $this->actingAs($this->user());
    fetchToken()->assertOk()->assertJson(['record' => true]);
});
