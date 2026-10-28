<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Events\AssistClaimed;
use Packstub\SessionReplay\Events\AssistEnded;
use Packstub\SessionReplay\Events\AssistRequested;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;

beforeEach(function () {
    $this->withoutCalls();
    $this->staffGate();
});

it('takes a request from the browser that makes the recording and hands the secret out once', function () {
    Event::fake([AssistRequested::class]);

    $user = $this->user();
    $browser = $this->browser($user);

    $response = $this->ask($browser)->assertCreated()->assertJson(['status' => 'open', 'opened_by' => 'visitor', 'agent' => null, 'call' => null]);

    $secret = $response->json('secret');
    $request = AssistRequest::query()->sole();

    expect($secret)->toMatch('/^[a-f0-9]{32}$/')
        ->and($request->secret_hash)->toBe(hash('sha256', $secret))
        ->and($request->replay_session_id)->toBe($browser['session']->id)
        ->and($request->user_id)->toBe((string) $user->id)
        ->and($request->message)->toBe('The checkout button does nothing')
        // privacy.redact_query applies, like every URL the recorder sends.
        ->and($request->page_url)->toBe('https://shop.test/checkout?step=2&token=redacted')
        ->and($request->identity)->not->toBeNull()
        ->and($request->visitor_seen_at)->not->toBeNull();

    // The same recording asks again: the request that is going comes back, without its secret.
    $this->ask($browser)->assertOk()->assertJson(['id' => $request->id, 'secret' => null]);

    expect(AssistRequest::query()->count())->toBe(1);
    Event::assertDispatchedTimes(AssistRequested::class, 1);
});

it('never attaches a request to someone else\'s recording', function () {
    $browser = $this->browser($this->user());

    // Another person's token, the right key.
    $this->ask($browser, ['token' => $this->token($this->user())])->assertForbidden();
    // The right person, the wrong key (the recording's id is in every viewer URL and log line).
    $this->ask($browser, ['key' => bin2hex(random_bytes(16))])->assertForbidden();
    $this->ask($browser, ['key' => null])->assertForbidden();
    // Another workspace.
    $owner = $browser['session']->user;
    $this->ask($browser, ['token' => $this->token($owner, $this->team())])->assertForbidden();

    $this->ask($browser, ['token' => 'forged.token'])->assertUnauthorized();
    $this->ask($browser, ['session' => 'not-a-uuid'])->assertUnprocessable();

    expect(AssistRequest::query()->count())->toBe(0);
});

it('turns guests away while guests are not recorded', function () {
    $guest = $this->token();

    $this->postJson(route('session-replay.assist.store'), ['token' => $guest])->assertForbidden();

    config()->set('session-replay.guests', true);

    $this->postJson(route('session-replay.assist.store'), ['token' => $guest])->assertCreated();
});

it('keeps the message to 500 characters and the page URL to http(s)', function () {
    $browser = $this->browser();

    $this->ask($browser, ['message' => str_repeat('a', 501)])->assertUnprocessable();
    $this->ask($browser, ['message' => ['not', 'text']])->assertUnprocessable();
    $this->ask($browser, ['message' => str_repeat('ä', 500), 'url' => 'javascript:alert(1)'])->assertCreated();

    expect(AssistRequest::query()->sole()->page_url)->toBeNull();
});

it('lets a tab that records nothing queue, one request per rendered page', function () {
    $token = $this->token($this->user());

    $first = $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'message' => 'Help'])->assertCreated();
    $this->postJson(route('session-replay.assist.store'), ['token' => $token])->assertOk()->assertJson(['id' => $first->json('id'), 'secret' => null]);

    // A recording id that is not stored yet (the first batch on its way) counts as none.
    $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'session' => (string) Str::uuid()])->assertOk()->assertJson(['id' => $first->json('id')]);

    expect(AssistRequest::query()->sole())
        ->replay_session_id->toBeNull()
        ->lock_key->toStartWith('nonce:');
});

it('limits new requests per person per hour', function () {
    config()->set('session-replay.assist.requests_per_hour', 2);

    $user = $this->user();
    // A new page each time (a new nonce): a request each.
    $ask = fn () => $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($user)]);

    $ask()->assertCreated();
    $ask()->assertCreated();
    $ask()->assertStatus(429)->assertJson(['reason' => 'too_many']);

    // Someone else is counted apart.
    $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user())])->assertCreated();
});

it('refuses new requests past max_open_requests, so a script cannot flood the list', function () {
    config()->set('session-replay.assist.max_open_requests', 2);
    config()->set('session-replay.assist.requests_per_hour', null);

    $ask = fn () => $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user())]);

    $ask()->assertCreated();
    $ask()->assertCreated();
    $ask()->assertStatus(429)->assertJson(['reason' => 'full']);

    // Requests whose pages are gone stop counting.
    $this->travel(2)->minutes();

    $ask()->assertCreated();
});

it('answers an unknown request and a wrong secret the same way', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->poll($asked['id'], 'wrong', $browser['token'])->assertForbidden()->assertJson(['message' => 'Unknown request, or the wrong secret.']);
    $this->poll((string) Str::uuid(), $asked['secret'], $browser['token'])->assertForbidden()->assertJson(['message' => 'Unknown request, or the wrong secret.']);
    $this->poll($asked['id'], null, $browser['token'])->assertForbidden();
    $this->visitorPost('cancel', $asked['id'], str_repeat('0', 32), $browser['token'])->assertForbidden();

    expect(AssistRequest::query()->sole()->status)->toBe('open');
});

it('refuses an expired token and a token of another workspace, even with the secret', function () {
    $user = $this->user();
    $browser = $this->browser($user);
    $asked = $this->asked($browser);

    $old = (new ContextToken($user->getMorphClass(), (string) $user->id, issuedAt: time() - 8 * 86400))->encode();

    $this->poll($asked['id'], $asked['secret'], $old)->assertUnauthorized();
    $this->poll($asked['id'], $asked['secret'], $this->token($user, $this->team()))->assertForbidden();

    expect(AssistRequest::query()->sole()->status)->toBe('open');
});

it('ends the request when the token names someone else', function () {
    Event::fake([AssistEnded::class]);

    $browser = $this->browser($this->user());
    $asked = $this->asked($browser);

    $this->poll($asked['id'], $asked['secret'], $this->token($this->user()))
        ->assertStatus(409)
        ->assertJson(['status' => 'cancelled', 'end_reason' => 'identity_changed']);

    Event::assertDispatched(AssistEnded::class, fn ($event) => $event->request->end_reason === 'identity_changed');
});

it('ends a guest\'s request when they sign in', function () {
    config()->set('session-replay.guests', true);

    $guest = $this->token();
    $asked = $this->postJson(route('session-replay.assist.store'), ['token' => $guest])->assertCreated();

    $this->poll($asked->json('id'), $asked->json('secret'), $this->token())->assertOk();
    $this->poll($asked->json('id'), $asked->json('secret'), $this->token($this->user()))->assertStatus(409);

    expect(AssistRequest::query()->sole()->status)->toBe('cancelled');
});

it('moves a request to the tab\'s next recording with the new triple, and only then', function () {
    $user = $this->user();
    $browser = $this->browser($user);
    $asked = $this->asked($browser);

    // The tab started a new recording (idle timeout, wire:navigate).
    $next = $this->browser($user);
    $someoneElse = $this->browser($this->user());

    $this->poll($asked['id'], $asked['secret'], $next['token'], ['session' => $someoneElse['session']->id, 'key' => $someoneElse['key']])->assertForbidden();
    $this->poll($asked['id'], $asked['secret'], $next['token'], ['session' => $next['session']->id, 'key' => 'wrong'])->assertForbidden();

    expect(AssistRequest::query()->sole()->replay_session_id)->toBe($browser['session']->id);

    $this->poll($asked['id'], $asked['secret'], $next['token'], ['session' => $next['session']->id, 'key' => $next['key']])->assertOk();

    expect(AssistRequest::query()->sole())
        ->replay_session_id->toBe($next['session']->id)
        ->lock_key->toBe($next['session']->id);
});

it('lets the visitor cancel while they wait', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->visitorPost('cancel', $asked['id'], $asked['secret'], $browser['token'])->assertOk()->assertJson(['status' => 'cancelled']);
    $this->visitorPost('cancel', $asked['id'], $asked['secret'], $browser['token'])->assertStatus(409);

    // Finished: the recording may ask again.
    $this->ask($browser)->assertCreated();
});

it('refuses to accept or decline what nobody offered', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->visitorPost('accept', $asked['id'], $asked['secret'], $browser['token'])->assertStatus(409);
    $this->visitorPost('decline', $asked['id'], $asked['secret'], $browser['token'])->assertStatus(409);
    $this->visitorPost('hangup', $asked['id'], $asked['secret'], $browser['token'])->assertStatus(409);
    $this->visitorPost('signal', $asked['id'], $asked['secret'], $browser['token'], ['type' => 'offer', 'payload' => ['sdp' => 'x']])->assertStatus(409);
});

it('lists waiting requests for whoever may assist, with the visitor\'s words as text', function () {
    $customer = $this->user(['email' => 'customer@example.com']);
    $browser = $this->browser($customer);
    $this->asked($browser, ['message' => '<script>alert("x")</script>']);

    $this->actingAs($this->staff());

    $this->get(route('session-replay.assist.index'))
        ->assertOk()
        ->assertSee('customer@example.com')
        ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert("x")</script>', false)
        ->assertSee('shop.test/checkout?step=2&amp;token=redacted', false)
        ->assertSee(route('session-replay.show', $browser['session']), false);

    $queue = $this->getJson(route('session-replay.assist.queue'))->assertOk();

    expect($queue->json('requests'))->toHaveCount(1)
        ->and($queue->json('requests.0'))->toMatchArray(['status' => 'open', 'message' => '<script>alert("x")</script>', 'visitor_here' => true, 'user' => ['id' => (string) $customer->id, 'label' => 'customer@example.com']])
        ->and($queue->json('requests.0'))->not->toHaveKeys(['secret_hash', 'identity', 'nonce', 'lock_key', 'secret']);

    $this->actingAs($customer);

    $this->get(route('session-replay.assist.index'))->assertForbidden();
    $this->getJson(route('session-replay.assist.queue'))->assertForbidden();
});

it('links the list to the requests page while Assist is on', function () {
    $this->actingAs($this->staff())->get(route('session-replay.index'))->assertSee(route('session-replay.assist.index'), false);
});

it('lets one agent claim a request, once', function () {
    Event::fake([AssistClaimed::class]);

    $browser = $this->browser();
    $asked = $this->asked($browser);
    $ana = $this->staff();
    $bob = $this->staff(['name' => 'Bob']);

    $this->actingAs($ana)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk()->assertJson(['status' => 'claimed', 'mine' => true, 'agent' => ['id' => (string) $ana->id, 'name' => 'Ana Support']]);
    $this->actingAs($bob)->postJson(route('session-replay.assist.claim', $asked['id']))->assertStatus(409)->assertJson(['reason' => 'taken', 'holder' => 'Ana Support']);

    Event::assertDispatchedTimes(AssistClaimed::class, 1);

    // The visitor learns who is coming: name and avatar, nothing else about them.
    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertOk()->assertJson(['status' => 'claimed', 'agent' => ['id' => (string) $ana->id, 'name' => 'Ana Support', 'avatar' => null]]);

    expect(array_keys($poll->json('agent')))->toBe(['id', 'name', 'avatar'])
        ->and($poll->json())->not->toHaveKeys(['user_id', 'session', 'message', 'page_url', 'grants']);
});

it('claims from the requests page with a form and comes back to it', function () {
    $asked = $this->asked($this->browser());

    $this->actingAs($this->staff())
        ->post(route('session-replay.assist.claim', $asked['id']))
        ->assertRedirect(route('session-replay.assist.index'))
        ->assertSessionHas('session-replay.assist');

    $this->post(route('session-replay.assist.claim', $asked['id']))->assertRedirect()->assertSessionHas('session-replay.assist-error');
});

it('asks the gate and canAssist on every agent endpoint, never only in the list', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);
    $customer = $this->user();

    foreach (['claim', 'withdraw', 'end'] as $action) {
        $this->actingAs($customer)->postJson(route('session-replay.assist.'.$action, $asked['id']))->assertForbidden();
    }

    $this->getJson(route('session-replay.assist.status', $asked['id']))->assertForbidden();

    // The gate lets staff watch, the app's rule narrows who may help.
    SessionReplay::canAssistUsing(fn ($agent, ?ReplaySession $session, AssistRequest $request) => $agent->name === 'Senior');

    $junior = $this->staff(['name' => 'Junior']);

    $this->actingAs($junior)->postJson(route('session-replay.assist.claim', $asked['id']))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', $asked['id']))->assertForbidden();
    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toBe([]);

    $senior = $this->staff(['name' => 'Senior']);

    $this->actingAs($senior)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    // Taken away mid-assist: the next call is refused.
    SessionReplay::canAssistUsing(fn () => false);

    $this->postJson(route('session-replay.assist.end', $asked['id']))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', $asked['id']))->assertForbidden();
});

it('never lets an agent assist their own visit', function () {
    $staff = $this->staff();
    $asked = $this->asked($this->browser($staff));

    $this->actingAs($staff)->postJson(route('session-replay.assist.claim', $asked['id']))->assertForbidden();
});

it('keeps requests of recordings outside the agent\'s list out of reach', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');
    $customer = $this->user();
    $token = $this->token($customer, $globex);
    $key = bin2hex(random_bytes(16));
    $id = (string) Str::uuid();

    $this->ingest(['token' => $token, 'session' => $id, 'key' => $key])->assertCreated();
    $asked = $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'session' => $id, 'key' => $key])->assertCreated();

    SessionReplay::visibleUsing(fn ($query) => $query->where('tenant_id', (string) $acme->id));

    $this->actingAs($this->staff());

    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toBe([]);
    $this->postJson(route('session-replay.assist.claim', $asked->json('id')))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', $asked->json('id')))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', (string) Str::uuid()))->assertForbidden();
});

it('works as a plain queue without Calls: claimed, then ended by the agent', function () {
    Event::fake([AssistEnded::class]);

    $browser = $this->browser();
    $asked = $this->asked($browser);
    $ana = $this->staff();

    $this->actingAs($ana)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    // No call to create: the page's polls keep it claimed, past the claim timeout.
    $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'claimed', 'call' => null]);
    $this->travel(40)->seconds();
    $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'claimed']);
    $this->getJson(route('session-replay.assist.status', $asked['id']))->assertJson(['status' => 'claimed', 'visitor_here' => true]);

    // Someone else cannot end it.
    $this->actingAs($this->staff(['name' => 'Bob']))->postJson(route('session-replay.assist.end', $asked['id']))->assertForbidden();

    $this->actingAs($ana)->postJson(route('session-replay.assist.end', $asked['id']))->assertOk()->assertJson(['status' => 'ended', 'end_reason' => 'ended_by_agent']);
    $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'ended', 'end_reason' => 'ended_by_agent']);

    Event::assertDispatched(AssistEnded::class, fn ($event) => $event->request->status === 'ended');

    // On the recording's timeline, without who the agent is.
    $marker = ReplayMarker::query()->where('type', 'assist')->sole();

    expect($marker->replay_session_id)->toBe($browser['session']->id)
        ->and($marker->label)->toBe('Help requested: ended (ended_by_agent)')
        ->and($marker->payload)->toMatchArray(['opened_by' => 'visitor', 'status' => 'ended'])
        ->and(json_encode($marker->payload))->not->toContain('Ana');
});

it('puts a claim the page never acknowledged back in the list', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->actingAs($this->staff())->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    $this->travel(31)->seconds();

    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->firstWhere('id', $asked['id']))
        ->toMatchArray(['status' => 'open', 'agent' => null]);
});

it('expires a request whose page stopped polling', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->travel(61)->seconds();

    $this->actingAs($this->staff());

    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toBe([]);
    expect(AssistRequest::query()->sole())->status->toBe('expired')->end_reason->toBe('visitor_timeout')->lock_key->toBeNull();
});

it('keeps a polling visitor in the list', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    foreach (range(1, 4) as $step) {
        $this->travel(40)->seconds();
        $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'open']);
    }

    expect(AssistRequest::query()->sole()->status)->toBe('open');
});

it('ends an assist at max_duration_minutes', function () {
    config()->set('session-replay.assist.max_duration_minutes', 5);

    $browser = $this->browser();
    $asked = $this->asked($browser);

    $this->actingAs($this->staff())->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    foreach (range(1, 11) as $step) {
        $this->travel(30)->seconds();
        $last = $this->poll($asked['id'], $asked['secret'], $browser['token']);
    }

    $last->assertJson(['status' => 'ended', 'end_reason' => 'max_duration']);
});

it('lets the agent withdraw a claim, and nobody else; the request goes back to the list', function () {
    Event::fake([AssistEnded::class]);

    $asked = $this->asked($this->browser());
    $ana = $this->staff();
    $bob = $this->staff(['name' => 'Bob']);

    $this->actingAs($ana)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();
    $this->actingAs($bob)->postJson(route('session-replay.assist.withdraw', $asked['id']))->assertForbidden();
    $this->actingAs($ana)->postJson(route('session-replay.assist.withdraw', $asked['id']))->assertOk()->assertJson(['status' => 'open', 'agent' => null]);

    // The visitor still waits: nothing ended, and someone else may take it now.
    Event::assertNotDispatched(AssistEnded::class);
    expect(AssistRequest::query()->sole())->claimed_at->toBeNull()->agent_seen_at->toBeNull();

    $this->actingAs($bob)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk()->assertJson(['status' => 'claimed', 'agent' => ['name' => 'Bob']]);

    // Ana let go: it is not hers to withdraw again.
    $this->actingAs($ana)->postJson(route('session-replay.assist.withdraw', $asked['id']))->assertForbidden();
});

it('logs what the agent asked for next to what the visitor allowed', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);
    $ana = $this->staff();

    // Not while nobody holds it, and not by someone who does not.
    $this->actingAs($ana)->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'control'])->assertForbidden();

    $this->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    $this->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'root'])->assertUnprocessable();
    $this->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'watch'])->assertUnprocessable();
    $this->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'control'])->assertOk();
    $this->actingAs($this->staff(['name' => 'Bob']))->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'audio'])->assertForbidden();

    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'control', 'granted' => true])->assertOk();

    $grants = AssistRequest::query()->sole()->grants;

    expect($grants)->toHaveCount(2)
        ->and($grants[0])->toMatchArray(['kind' => 'control', 'asked' => true, 'by' => 'agent'])
        ->and($grants[0])->not->toHaveKey('granted')
        ->and($grants[1])->toMatchArray(['kind' => 'control', 'granted' => true, 'by' => 'visitor']);

    // An ask is not a grant: the marker names what the visitor allowed only.
    $this->actingAs($ana)->postJson(route('session-replay.assist.asks', $asked['id']), ['what' => 'audio'])->assertOk();
    $this->postJson(route('session-replay.assist.end', $asked['id']))->assertOk();

    expect(ReplayMarker::query()->where('type', 'assist')->sole()->payload['granted'])->toBe('control');
});

it('logs what the visitor allowed, with a timestamp, and only known grants', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);

    // Nothing to grant while nobody is helping.
    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'control', 'granted' => true])->assertStatus(409);

    $this->actingAs($this->staff())->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'control', 'granted' => true])->assertOk();
    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'control', 'granted' => '0'])->assertOk();
    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'root', 'granted' => true])->assertUnprocessable();
    $this->visitorPost('grants', $asked['id'], $asked['secret'], $browser['token'], ['kind' => 'audio'])->assertUnprocessable();

    $grants = AssistRequest::query()->sole()->grants;

    expect($grants)->toHaveCount(2)
        ->and($grants[0])->toMatchArray(['kind' => 'control', 'granted' => true, 'by' => 'visitor'])
        ->and($grants[1])->toMatchArray(['kind' => 'control', 'granted' => false])
        ->and($grants[0]['at'])->toBeString();
});

it('answers the heartbeat of the browser that makes the recording, and keeps the recording live', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);
    $browser['session']->forceFill(['last_activity_at' => now()->subMinutes(5)])->save();

    $this->travel(50)->seconds();

    $this->postJson(route('session-replay.assist.heartbeat'), ['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => 'wrong'])->assertForbidden();
    $this->postJson(route('session-replay.assist.heartbeat'), ['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key']])
        ->assertOk()
        ->assertExactJson(['ok' => true, 'assist' => null]);

    expect($browser['session']->fresh()->isLive())->toBeTrue()
        ->and(AssistRequest::query()->sole()->visitor_seen_at->gt(now()->subSecond()))->toBeTrue();
});

it('leaves the ingest response as it was while Assist is off, and adds the knock while it is on', function () {
    $browser = $this->browser();

    $this->ingest(['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key'], 'seq' => 1])->assertOk()->assertJson(['assist' => null]);
});

it('never lets a browser write an assist marker', function () {
    $browser = $this->browser();

    $this->ingest(['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key'], 'seq' => 1, 'meta' => ['markers' => [
        ['type' => 'assist', 'label' => 'Remote control granted', 'at' => now()->getTimestampMs()],
    ]]])->assertOk();

    expect(ReplayMarker::query()->where('type', 'assist')->count())->toBe(0);
});

it('refuses an offer of help without packstub/calls', function () {
    $browser = $this->browser();

    $this->actingAs($this->staff())->postJson(route('session-replay.assist.propose', $browser['session']))->assertStatus(422)->assertJson(['reason' => 'no_calls']);
});

it('prunes requests with their recording, and old ones that never had one', function () {
    $browser = $this->browser($this->user(), ['last_activity_at' => now()->subDays(40)]);
    $this->asked($browser);
    AssistRequest::query()->update(['status' => 'cancelled', 'lock_key' => null]);

    $pageOnly = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user())])->assertCreated()->json('id');
    $waiting = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user())])->assertCreated()->json('id');
    AssistRequest::query()->whereKey($pageOnly)->update(['status' => 'expired', 'lock_key' => null, 'updated_at' => now()->subDays(40)]);

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(AssistRequest::query()->pluck('id')->all())->toBe([$waiting]);
});
