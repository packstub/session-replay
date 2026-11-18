<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;
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

it('tells the agent who holds a request apart by type and guard, not only by key', function () {
    [, $asked, $agent] = $this->claimed();
    $id = $asked['id'];

    $this->actingAs($agent);
    expect($this->getJson(route('session-replay.assist.status', $id))->json('mine'))->toBeTrue();

    // Admin #12 is not User #12: the same key under another type holds nothing.
    AssistRequest::query()->whereKey($id)->update(['agent_type' => 'App\\Models\\Admin', 'agent_seen_at' => now()->subMinute()]);

    $status = $this->getJson(route('session-replay.assist.status', $id))->assertOk();

    expect($status->json('mine'))->toBeFalse()
        ->and(AssistRequest::query()->find($id)->agent_seen_at->lt(now()->subSeconds(30)))->toBeTrue();
    $this->get(route('session-replay.assist.stage', $id))->assertForbidden();
    $this->postJson(route('session-replay.assist.end', $id))->assertForbidden();
    $this->postJson(route('session-replay.assist.asks', $id), ['what' => 'control'])->assertForbidden();
    $this->postJson(route('session-replay.assist.withdraw', $id))->assertForbidden();
    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->firstWhere('id', $id)['mine'])->toBeFalse();

    // Nor the same person claimed through another guard.
    AssistRequest::query()->whereKey($id)->update(['agent_type' => $agent->getMorphClass(), 'agent_guard' => 'admin']);

    expect($this->getJson(route('session-replay.assist.status', $id))->json('mine'))->toBeFalse();
    $this->postJson(route('session-replay.assist.end', $id))->assertForbidden();

    AssistRequest::query()->whereKey($id)->update(['agent_guard' => config('auth.defaults.guard')]);

    expect($this->getJson(route('session-replay.assist.status', $id))->json('mine'))->toBeTrue();
    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->firstWhere('id', $id)['mine'])->toBeTrue();
});

it('treats a recording from before write keys as no recording: the heartbeat keeps nothing open, attaches nothing and knocks for nobody', function () {
    $browser = $this->browser();
    $session = $browser['session'];
    $seen = now()->subMinutes(5)->startOfSecond();
    // A recording made before write keys: no key stored, still inside ingest's grace window (twice idle_minutes).
    $session->forceFill(['write_key' => null, 'last_activity_at' => $seen])->save();

    $this->travel(30)->seconds();

    // The heartbeat answers, but moves nothing: otherwise anyone with a token for this person keeps it open forever.
    $this->postJson(route('session-replay.assist.heartbeat'), ['token' => $browser['token'], 'session' => $session->id, 'key' => 'anything'])
        ->assertOk()
        ->assertExactJson(['ok' => true, 'assist' => null]);

    expect($session->fresh()->last_activity_at->equalTo($seen))->toBeTrue();

    // A request from that tab is made without the recording.
    $asked = $this->ask($browser, ['key' => null, 'tab' => 'tab-1'])->assertCreated();

    expect(AssistRequest::query()->sole()->replay_session_id)->toBeNull();

    // A request going elsewhere is not re-bound to it.
    $other = $this->browser($session->user);
    $mine = $this->asked($other);
    $this->poll($mine['id'], $mine['secret'], $other['token'], ['session' => $session->id, 'key' => null])->assertOk();

    expect(AssistRequest::query()->find($mine['id'])->replay_session_id)->toBe($other['session']->id)
        ->and($session->fresh()->last_activity_at->equalTo($seen))->toBeTrue();

    // An agent's offer on it is never knocked on, and its secret is never issued.
    $offer = AssistRequest::query()->find($asked->json('id'));
    $offer->forceFill(['replay_session_id' => $session->id, 'opened_by' => 'agent', 'lock_key' => 'offer-'.$session->id])->save();

    expect(app(Assist::class)->knock($session->id))->toBeNull();
    $this->poll($offer->id, null, $browser['token'], ['session' => $session->id])->assertForbidden();
});

it('runs the timeouts over the queue at most once every few seconds, and only over the agent\'s workspace', function () {
    $this->freezeSecond();
    config()->set('session-replay.assist.requests_per_hour', null);

    $acme = $this->team('Acme');
    $globex = $this->team('Globex');
    $ask = fn (Team $team, string $tab) => $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $team), 'tab' => $tab])->assertCreated()->json('id');
    $quiet = fn (string $id) => AssistRequest::query()->whereKey($id)->update(['visitor_seen_at' => now()->subMinutes(2)]);
    $status = fn (string $id) => AssistRequest::query()->find($id)->status;

    SessionReplay::tenantUsing(fn () => $acme);
    $this->actingAs($this->staff());

    $first = $ask($acme, 'tab-1');
    $theirs = $ask($globex, 'tab-1');
    $quiet($first);
    $quiet($theirs);

    // Asking swept the workspace a moment ago. The next read after the interval sweeps Acme: its quiet request
    // expires; Globex's is not this agent's to sweep.
    $this->travel(Assist::SWEEP_EVERY_SECONDS)->seconds();
    $this->getJson(route('session-replay.assist.queue'))->assertOk();

    expect($status($first))->toBe('expired')
        ->and($status($theirs))->toBe('open');

    // Reads right after it do not sweep again (the table polls every 5 s, the notifier every 10 s, the badge too).
    $second = $ask($acme, 'tab-2');
    $quiet($second);
    $this->travel(2)->seconds();
    $this->getJson(route('session-replay.assist.queue'))->assertOk();

    expect($status($second))->toBe('open');

    // Once the interval has passed, the next read sweeps.
    $this->travel(Assist::SWEEP_EVERY_SECONDS)->seconds();
    $this->getJson(route('session-replay.assist.queue'))->assertOk();

    expect($status($second))->toBe('expired')
        ->and($status($theirs))->toBe('open');
});

it('narrows the queue to the workspace the caller names, in the query', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    $ours = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $acme), 'tab' => 'tab-1'])->assertCreated()->json('id');
    $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $globex), 'tab' => 'tab-1'])->assertCreated();

    $agent = $this->staff();
    $this->actingAs($agent);

    // Without a workspace, requests of a workspace that have no recording are nobody's on the gate alone.
    expect(app(Assist::class)->queue($agent))->toHaveCount(0)
        ->and(app(Assist::class)->queue($agent, $acme)->pluck('id')->all())->toBe([$ours]);
});

it('keeps lock_key unique while letting any number of finished requests have none', function () {
    $unique = collect(Schema::connection(config('session-replay.storage.connection'))->getIndexes('replay_assist_requests'))
        ->first(fn (array $index) => $index['columns'] === ['lock_key']);

    expect($unique)->not->toBeNull()->and($unique['unique'])->toBeTrue();

    $row = fn (?string $key) => AssistRequest::query()->forceCreate(['id' => (string) Str::uuid(), 'status' => 'ended', 'opened_by' => 'visitor', 'lock_key' => $key]);

    $row(null);
    $row(null);
    $row('one');

    expect(fn () => $row('one'))->toThrow(UniqueConstraintViolationException::class)
        ->and(AssistRequest::query()->whereNull('lock_key')->count())->toBe(2);
});

it('keeps a workspace\'s requests without a recording from an agent no rule places in that workspace', function () {
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    // A Globex customer's tab that records nothing; a request of no workspace beside it.
    $theirs = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user(), $globex), 'tab' => 'tab-1', 'message' => 'Our invoices'])->assertCreated()->json('id');
    $plain = $this->postJson(route('session-replay.assist.store'), ['token' => $this->token($this->user()), 'tab' => 'tab-1'])->assertCreated()->json('id');

    // No tenantUsing(), no visibleUsing(), no canAssistUsing(): the gate alone says this agent may watch.
    $agent = $this->staff();
    $this->actingAs($agent);

    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->pluck('id')->all())->toBe([$plain]);
    $this->postJson(route('session-replay.assist.claim', $theirs))->assertForbidden();
    $this->getJson(route('session-replay.assist.status', $theirs))->assertForbidden();

    // A caller that knows the agent's workspace (a panel scoped to its tenant) passes it.
    expect(app(Assist::class)->queue($agent, $globex)->pluck('id')->all())->toBe([$theirs])
        ->and(app(Assist::class)->visibleTo(AssistRequest::query()->find($theirs), $agent, $acme))->toBeFalse();

    app(Assist::class)->claim($agent, AssistRequest::query()->find($theirs), $globex);

    // The holder keeps it on the routes outside the panel (the stage's status poll), as long as the gate says yes.
    $this->getJson(route('session-replay.assist.status', $theirs))->assertOk()->assertJson(['status' => 'claimed']);

    // tenantUsing() naming the workspace does it too, and so does a canAssistUsing() rule.
    SessionReplay::tenantUsing(fn () => $globex);
    expect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->toHaveCount(1);
});

it('hands the request a tab has going back only to the same person in the same workspace', function () {
    $ada = $this->user();
    $first = ContextToken::for($ada);
    $sameNonce = fn (?Model $user, ?Model $team = null) => (new ContextToken($user?->getMorphClass(), $user === null ? null : (string) $user->getKey(), $team?->getMorphClass(), $team === null ? null : (string) $team->getKey(), null, [], time(), $first->nonce))->encode();

    $id = $this->postJson(route('session-replay.assist.store'), ['token' => $first->encode(), 'tab' => 'tab-1'])->assertCreated()->json('id');

    // A page out of a full-page cache gave someone else the same nonce, and they know the tab id: not Ada's request.
    $this->postJson(route('session-replay.assist.store'), ['token' => $sameNonce($this->user()), 'tab' => 'tab-1'])->assertStatus(409)->assertJsonMissing(['id' => $id]);
    $this->postJson(route('session-replay.assist.store'), ['token' => $sameNonce($ada, $this->team()), 'tab' => 'tab-1'])->assertStatus(409)->assertJsonMissing(['id' => $id]);

    // Ada herself gets it back.
    $this->postJson(route('session-replay.assist.store'), ['token' => $sameNonce($ada), 'tab' => 'tab-1'])->assertOk()->assertJson(['id' => $id, 'secret' => null]);
});
