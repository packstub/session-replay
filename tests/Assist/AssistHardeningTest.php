<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\Fixtures\Models\Team;

beforeEach(function () {
    $this->withoutCalls();
    $this->staffGate();
});

it('keeps requests without a recording from every agent while visibleUsing narrows the list and nothing decides for them', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    // A Globex customer's tab that records nothing.
    $asked = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $globex), 'tab' => 'tab-1', 'message' => 'Our invoices'])->assertCreated();

    SessionReplay::visibleUsing(fn ($query) => $query->where('tenant_id', (string) $acme->id));

    $this->actingAs($this->staff());

    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toBe([]);
    $this->postJson(route('session-replay.assist.claim', $asked->json('id')))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', $asked->json('id')))->assertForbidden();

    // A canAssistUsing() rule decides for them: the app looks at the request's workspace.
    SessionReplay::canAssistUsing(fn ($agent, ?ReplaySession $session, AssistRequest $request) => $request->tenant_id === (string) $acme->id);

    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toBe([]);

    SessionReplay::canAssistUsing(fn () => true);

    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toHaveCount(1);
});

it('keeps an agent to the workspace the app\'s tenantUsing() names for their request', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    $theirs = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $globex), 'tab' => 'tab-1'])->assertCreated()->json('id');
    $recorded = $this->triple($globex);
    $theirsRecorded = $this->postJson(route('session-replay.assist.store'), $recorded)->assertCreated()->json('id');
    $ours = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $acme), 'tab' => 'tab-1'])->assertCreated()->json('id');

    // The agent works in Acme (a team in the URL, Filament's tenant…).
    SessionReplay::tenantUsing(fn () => $acme);

    $this->actingAs($this->staff());

    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->pluck('id')->all())->toBe([$ours]);

    $this->postJson(route('session-replay.assist.claim', $theirs))->assertForbidden();
    $this->postJson(route('session-replay.assist.claim', $theirsRecorded))->assertForbidden();
    $this->postJson(route('session-replay.assist.claim', $ours))->assertOk();
});

it('caps open requests per workspace, so one workspace cannot fill another\'s list', function () {
    config()->set('session-replay.assist.max_open_requests', 1);
    config()->set('session-replay.assist.requests_per_hour', null);

    $acme = $this->team('Acme');
    $globex = $this->team('Globex');
    $ask = fn (Team $team) => $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $team), 'tab' => 'tab-1']);

    $ask($acme)->assertCreated();
    $ask($acme)->assertStatus(429)->assertJson(['reason' => 'full']);
    $ask($globex)->assertCreated();
});

it('takes help requests from guests only while assist.guests is on', function () {
    config()->set('session-replay.guests', true);
    config()->set('session-replay.assist.guests', false);

    $this->postJson(route('session-replay.assist.store'), $this->triple(guest: true))->assertForbidden()->assertJson(['reason' => 'guests']);

    // A signed-in person still may.
    $this->postJson(route('session-replay.assist.store'), $this->triple())->assertCreated();

    expect(AssistRequest::query()->count())->toBe(1);
});

it('gives the guests of a workspace one budget per hour, and only from a recording with events', function () {
    config()->set('session-replay.guests', true);
    config()->set('session-replay.assist.guest_requests_per_hour', 2);

    $acme = $this->team('Acme');

    // A tab that records nothing, or a recording not stored yet: a token alone is anyone's for one page render.
    $this->postJson(route('session-replay.assist.store'), ['token' => $this->token(null, $acme), 'tab' => 'tab-1'])->assertForbidden()->assertJson(['reason' => 'no_recording']);
    $this->postJson(route('session-replay.assist.store'), ['token' => $this->token(null, $acme), 'session' => (string) Str::uuid(), 'tab' => 'tab-1'])->assertForbidden()->assertJson(['reason' => 'no_recording']);

    // Recordings that are not empty: two, then the workspace's guests wait, whatever page they ask from.
    $this->postJson(route('session-replay.assist.store'), $this->triple($acme, guest: true))->assertCreated();
    $this->postJson(route('session-replay.assist.store'), $this->triple($acme, guest: true))->assertCreated();
    $this->postJson(route('session-replay.assist.store'), $this->triple($acme, guest: true))->assertStatus(429)->assertJson(['reason' => 'too_many']);

    // Another workspace's guests have their own; signed-in people are not counted against it.
    $this->postJson(route('session-replay.assist.store'), $this->triple($this->team('Globex'), guest: true))->assertCreated();
    $this->postJson(route('session-replay.assist.store'), $this->triple($acme))->assertCreated();
});

it('keeps one request per tab without a recording, also when a cached page gave every visitor the same nonce', function () {
    // One token for two people's tabs: the page came out of a full-page cache.
    $token = $this->token($this->user());

    $first = $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'tab' => 'tab-a'])->assertCreated()->json('id');
    $second = $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'tab' => 'tab-b'])->assertCreated()->json('id');

    expect($second)->not->toBe($first);

    // The same tab asking again gets its own request back.
    $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'tab' => 'tab-a'])->assertOk()->assertJson(['id' => $first, 'secret' => null]);
    $this->postJson(route('session-replay.assist.store'), ['token' => $token, 'tab' => 'tab-b'])->assertOk()->assertJson(['id' => $second, 'secret' => null]);

    expect(AssistRequest::query()->pluck('lock_key')->every(fn (string $key) => str_starts_with($key, 'tab:') && strlen($key) <= 80))->toBeTrue();
});

it('throttles by the token in the body only, never one in the query string', function () {
    $token = ContextToken::for($this->user())->encode();
    $limiter = RateLimiter::limiter('session-replay');

    $inUrl = Request::create('/session-replay/ingest?token='.urlencode($token), 'POST');
    $inBody = Request::create('/session-replay/ingest', 'POST', ['token' => $token]);

    expect($limiter($inUrl)->key)->toBe('session-replay|invalid')
        ->and($limiter($inBody)->key)->not->toBe('session-replay|invalid');
});
