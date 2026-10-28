<?php

use Illuminate\Support\Facades\Event;
use Packstub\Calls\Calls as CallsService;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\Models\Call;
use Packstub\SessionReplay\Events\AssistEnded;
use Packstub\SessionReplay\Events\AssistStarted;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Tests\Fixtures\Models\Admin;

beforeEach(function () {
    $this->needsCalls();
    $this->staffGate();
});

it('creates the call when the page acknowledges the claim, not before', function () {
    Event::fake([AssistStarted::class]);

    [$browser, $asked, $agent] = $this->claimed();

    expect(Call::query()->count())->toBe(0);

    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk()->assertJson(['status' => 'active']);

    $call = Call::query()->sole();

    expect($call->kind)->toBe('assist')
        ->and($call->status)->toBe('active')
        ->and($call->caller_id)->toBe((string) $agent->id)
        ->and($call->callee_id)->toBe('guest:'.$asked['id'])
        ->and(AssistRequest::query()->sole()->call_id)->toBe($call->id)
        // The call as the guest sees it: the agent is the peer the page checks against agent.id.
        ->and($poll->json('call.id'))->toBe($call->id)
        ->and($poll->json('call.peer'))->toBe(['id' => (string) $agent->id, 'name' => 'Ana Support', 'avatar' => null])
        ->and($poll->json('agent.id'))->toBe((string) $agent->id)
        ->and($poll->json('call.peers'))->toHaveCount(1);

    Event::assertDispatchedTimes(AssistStarted::class, 1);

    // What the page's RTCPeerConnection needs comes with the call, not before.
    expect($poll->json('rtc'))->toBe(['iceServers' => Calls::rtcConfiguration()['iceServers'], 'iceTransportPolicy' => 'all']);

    // Polls after it reuse the call, held by the tab.
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'call' => ['id' => $call->id, 'held' => false]]);

    expect(Call::query()->count())->toBe(1);
});

it('keeps the assist call away from the Calls widget, which polls for kind=call', function () {
    [$browser, $asked, $agent] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active']);

    expect(Calls::poll((string) $agent->id, kind: 'call')['call'])->toBeNull()
        ->and(Calls::poll((string) $agent->id, kind: 'assist')['call']['kind'])->toBe('assist');
});

it('relays signals both ways, with a session description left as it was sent', function () {
    [$browser, $asked, $agent] = $this->claimed();

    $call = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');
    $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\n";

    $this->visitorPost('signal', $asked['id'], $asked['secret'], $browser['token'], ['type' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => $sdp]])->assertCreated();

    $theirs = Calls::poll((string) $agent->id, 0, $call, 'agent-tab', hold: true, kind: 'assist');

    expect($theirs['signals'])->toHaveCount(1)
        ->and($theirs['signals'][0]['payload']['sdp'])->toBe($sdp)
        ->and($theirs['signals'][0]['from'])->toBe('guest:'.$asked['id']);

    Calls::signal(Call::query()->with('participants')->find($call), (string) $agent->id, 'answer', ['type' => 'answer', 'sdp' => 'v=0']);

    $mine = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();

    expect($mine->json('signals'))->toHaveCount(1)
        ->and($mine->json('signals.0.type'))->toBe('answer')
        ->and($mine->json('cursor'))->toBe($mine->json('signals.0.id'));

    $this->visitorPost('signal', $asked['id'], $asked['secret'], $browser['token'], ['type' => 'script', 'payload' => []])->assertUnprocessable();
    $this->visitorPost('signal', $asked['id'], $asked['secret'], $browser['token'], ['type' => 'ice', 'payload' => 'text'])->assertUnprocessable();
    $this->visitorPost('signal', $asked['id'], 'wrong', $browser['token'], ['type' => 'ice', 'payload' => ['candidate' => 'x']])->assertForbidden();
});

it('caps a signal\'s size the way Calls does', function () {
    config()->set('packstub-calls.max_signal_kb', 1);

    [$browser, $asked] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1']);

    $this->visitorPost('signal', $asked['id'], $asked['secret'], $browser['token'], ['type' => 'offer', 'payload' => ['sdp' => str_repeat('a', 2048)]])
        ->assertStatus(413)
        ->assertJson(['reason' => 'too_large']);
});

it('ends the request with Calls\' reason when the agent hangs up', function () {
    Event::fake([AssistEnded::class]);

    [$browser, $asked, $agent] = $this->claimed();

    $call = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');

    Calls::hangup(Call::query()->with('participants')->find($call), (string) $agent->id);

    expect(AssistRequest::query()->sole())->status->toBe('ended')->end_reason->toBe('hung_up');

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'ended', 'call' => ['status' => 'ended']]);

    Event::assertDispatchedTimes(AssistEnded::class, 1);
});

it('hangs up the call when the visitor hangs up or the agent ends it', function () {
    [$browser, $asked, $agent] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1']);
    $this->visitorPost('hangup', $asked['id'], $asked['secret'], $browser['token'])->assertOk()->assertJson(['status' => 'ended', 'end_reason' => 'hung_up']);

    expect(Call::query()->sole()->status)->toBe('ended');

    $second = $this->asked($browser);
    $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $second['id']))->assertOk();
    $this->poll($second['id'], $second['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active']);

    $this->postJson(route('session-replay.assist.end', $second['id']))->assertOk()->assertJson(['status' => 'ended', 'end_reason' => 'ended_by_agent']);

    expect(Call::query()->latest('id')->first()->status)->toBe('ended')
        ->and(ReplayMarker::query()->where('type', 'assist')->count())->toBe(2);
});

it('frees the guest\'s side when the page unloads, so the next page takes it', function () {
    [$browser, $asked] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1']);
    $this->visitorPost('leave', $asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertNoContent();

    expect(Call::query()->sole()->participants->firstWhere('user_id', 'guest:'.$asked['id'])->tab)->toBeNull();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-2'])->assertJson(['call' => ['held' => false]]);
});

it('refuses a claim when the viewer and Calls see different people', function () {
    config()->set('auth.guards.calls', ['driver' => 'session', 'provider' => 'users']);
    config()->set('packstub-calls.guard', 'calls');

    $asked = $this->asked($this->browser());

    $this->actingAs($this->staff())->postJson(route('session-replay.assist.claim', $asked['id']))
        ->assertStatus(409)
        ->assertJson(['reason' => 'guard_mismatch']);

    expect(AssistRequest::query()->sole()->status)->toBe('open');
});

it('asks before the agent joins with watch_consent', function () {
    config()->set('session-replay.assist.watch_consent', true);

    [$browser, $asked] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'claimed', 'consent' => 'watch', 'call' => null]);

    // Waiting for the answer is not a lost page: the claim stays past the claim timeout.
    $this->travel(40)->seconds();
    $this->poll($asked['id'], $asked['secret'], $browser['token'])->assertJson(['status' => 'claimed']);

    expect(Call::query()->count())->toBe(0);

    $this->visitorPost('accept', $asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk()->assertJson(['status' => 'active']);

    expect(Call::query()->sole()->status)->toBe('active');
});

it('puts an unacknowledged claim back in the list when a call was due', function () {
    [, $asked] = $this->claimed();

    $this->travel(31)->seconds();

    expect(collect($this->getJson(route('session-replay.assist.queue'))->json('requests'))->firstWhere('id', $asked['id'])['status'])->toBe('open');
});

it('offers help from a live recording, knocks on the page and issues the secret once', function () {
    $browser = $this->browser();
    $agent = $this->staff();

    $offer = $this->actingAs($agent)->postJson(route('session-replay.assist.propose', $browser['session']))->assertCreated()->assertJson(['status' => 'open', 'opened_by' => 'agent', 'mine' => true]);
    $id = $offer->json('id');

    // A second agent: 409 with the holder's name.
    $this->actingAs($this->staff(['name' => 'Bob']))->postJson(route('session-replay.assist.propose', $browser['session']))
        ->assertStatus(409)
        ->assertJson(['reason' => 'exists', 'holder' => 'Ana Support']);

    // The knock, in the next upload of that recording only.
    $this->ingest(['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key'], 'seq' => 1])
        ->assertOk()
        ->assertJson(['assist' => ['id' => $id, 'agent' => ['id' => (string) $agent->id, 'name' => 'Ana Support', 'avatar' => null]]]);

    $other = $this->browser();
    $this->ingest(['token' => $other['token'], 'session' => $other['session']->id, 'key' => $other['key'], 'seq' => 1])->assertJson(['assist' => null]);

    $this->postJson(route('session-replay.assist.heartbeat'), ['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key']])
        ->assertJson(['assist' => ['id' => $id]]);

    // Someone without the recording's key gets nothing.
    $this->poll($id, null, $browser['token'], ['session' => $browser['session']->id, 'key' => 'wrong'])->assertForbidden();
    $this->poll($id, null, $other['token'], ['session' => $other['session']->id, 'key' => $other['key']])->assertForbidden();

    $first = $this->poll($id, null, $browser['token'], ['session' => $browser['session']->id, 'key' => $browser['key']])->assertOk()->assertJson(['status' => 'open', 'consent' => 'watch']);
    $secret = $first->json('secret');

    expect($secret)->toMatch('/^[a-f0-9]{32}$/');

    $this->poll($id, null, $browser['token'], ['session' => $browser['session']->id, 'key' => $browser['key']])->assertStatus(409);
    $this->poll($id, $secret, $browser['token'])->assertOk()->assertJsonMissing(['secret' => $secret]);

    $this->visitorPost('accept', $id, $secret, $browser['token'], ['tab' => 'tab-1'])->assertOk()->assertJson(['status' => 'active']);

    expect(Call::query()->sole())->kind->toBe('assist')->status->toBe('active');
});

it('lets the visitor say not now to an offer, and expires one nobody answers', function () {
    $browser = $this->browser();
    $agent = $this->staff();

    $id = $this->actingAs($agent)->postJson(route('session-replay.assist.propose', $browser['session']))->json('id');
    $secret = $this->poll($id, null, $browser['token'], ['session' => $browser['session']->id, 'key' => $browser['key']])->json('secret');

    $this->visitorPost('decline', $id, $secret, $browser['token'])->assertOk()->assertJson(['status' => 'declined']);
    $this->getJson(route('session-replay.assist.status', $id))->assertJson(['status' => 'declined']);

    $id = $this->postJson(route('session-replay.assist.propose', $browser['session']))->assertCreated()->json('id');

    $this->travel(61)->seconds();
    $browser['session']->forceFill(['last_activity_at' => now()])->save();

    $this->getJson(route('session-replay.assist.status', $id))->assertJson(['status' => 'expired', 'end_reason' => 'proposal_timeout']);
});

it('asks canAssist again when the visitor accepts an offer', function () {
    $browser = $this->browser();
    $agent = $this->staff();

    $id = $this->actingAs($agent)->postJson(route('session-replay.assist.propose', $browser['session']))->assertCreated()->json('id');
    $secret = $this->poll($id, null, $browser['token'], ['session' => $browser['session']->id, 'key' => $browser['key']])->json('secret');

    // Between the offer and the answer the agent lost the right to help.
    SessionReplay::canAssistUsing(fn () => false);

    $this->visitorPost('accept', $id, $secret, $browser['token'], ['tab' => 'tab-1'])->assertStatus(409)->assertJson(['reason' => 'not_allowed']);

    expect(AssistRequest::query()->sole())->status->toBe('withdrawn')->end_reason->toBe('not_allowed')
        ->and(Call::query()->count())->toBe(0);
});

it('puts a claim back in the list when the agent may not help any more by the time the visitor accepts', function () {
    config()->set('session-replay.assist.watch_consent', true);

    [$browser, $asked] = $this->claimed();

    SessionReplay::canAssistUsing(fn () => false);

    $this->visitorPost('accept', $asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertStatus(409)->assertJson(['reason' => 'not_allowed']);

    expect(AssistRequest::query()->sole())->status->toBe('open')->agent_id->toBeNull()
        ->and(Call::query()->count())->toBe(0);
});

it('lets the agent take an offer back', function () {
    $browser = $this->browser();
    $agent = $this->staff();

    $id = $this->actingAs($agent)->postJson(route('session-replay.assist.propose', $browser['session']))->json('id');

    $this->postJson(route('session-replay.assist.withdraw', $id))->assertOk()->assertJson(['status' => 'withdrawn']);

    $this->ingest(['token' => $browser['token'], 'session' => $browser['session']->id, 'key' => $browser['key'], 'seq' => 1])->assertJson(['assist' => null]);
});

it('offers help only while the recording is live, to someone else, behind the gate', function () {
    $staff = $this->staff();
    $browser = $this->browser(null, ['last_activity_at' => now()->subMinutes(10)]);

    $this->actingAs($staff)->postJson(route('session-replay.assist.propose', $browser['session']))->assertStatus(422)->assertJson(['reason' => 'not_live']);

    $mine = $this->browser($staff);
    $this->postJson(route('session-replay.assist.propose', $mine['session']))->assertForbidden();

    $live = $this->browser();
    $this->actingAs($this->user())->postJson(route('session-replay.assist.propose', $live['session']))->assertForbidden();
});

it('ends the assist when the agent starts another call, since an agent helps one visitor at a time', function () {
    [$browser, $asked, $agent] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active']);

    Calls::start($agent, $this->user());

    expect(AssistRequest::query()->sole()->status)->toBe('ended');
});

it('rings the agent on the guard they claimed through, not the default guard of the visitor\'s request', function () {
    // A panel on an "admin" guard with a table of its own: admin 1 and user 1 are two people.
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => Admin::class]);
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);

    $browser = $this->browser();
    $asked = $this->asked($browser);
    $customer = $browser['session']->user;
    $admin = Admin::query()->create(['name' => 'Ana Admin', 'email' => 'ana@admin.test', 'password' => 'secret', 'is_staff' => true]);

    expect((string) $admin->id)->toBe((string) $customer->id);

    // The agent's request: the panel made its guard the default (Filament does), the viewer and Calls follow it.
    $this->actingAs($admin, 'admin')->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

    expect(AssistRequest::query()->sole())
        ->agent_type->toBe($admin->getMorphClass())
        ->agent_guard->toBe('admin')
        ->agent_scope->toBe('admin');

    // The visitor's request: no panel, the app's default guard.
    auth()->shouldUse('web');

    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk()->assertJson(['status' => 'active']);

    $call = Call::query()->sole();

    expect($call->caller_id)->toBe((string) $admin->id)
        ->and($call->scope)->toBe('admin')
        ->and($poll->json('call.peer.name'))->toBe('Ana Admin');

    // The panel's poll (its scope) sees the call; the visitor's own default guard never named anyone in it.
    auth()->shouldUse('admin');

    expect(Calls::poll((string) $admin->id, kind: 'assist')['call']['id'])->toBe($call->id);
});

it('refuses claims and offers while Calls sits on another database than Session Replay', function () {
    config()->set('session-replay.storage.connection', 'sqlite');
    config()->set('packstub-calls.connection', null);

    $live = $this->browser();
    $asked = $this->asked($this->browser());
    $agent = $this->staff();

    $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $asked['id']))->assertStatus(409)->assertJson(['reason' => 'connection_mismatch']);
    $this->postJson(route('session-replay.assist.propose', $live['session']))->assertStatus(409)->assertJson(['reason' => 'connection_mismatch']);

    expect(AssistRequest::query()->sole()->status)->toBe('open');

    config()->set('packstub-calls.connection', 'sqlite');

    $this->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();
});

it('ends a call it made when starting the assist fails after it, and the request with it', function () {
    [$browser, $asked] = $this->claimed();

    app()->instance(CallsService::class, new class extends CallsService
    {
        public function answer(Call $call, string $userId, ?string $tab = null): Call
        {
            throw new RuntimeException('The database went away.');
        }
    });

    $this->withoutExceptionHandling();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk()->assertJson(['status' => 'ended', 'end_reason' => 'call_failed']);

    expect(Call::query()->sole()->status)->toBe('ended');
});

it('ends a live request whose call ended without telling it', function () {
    [$browser, $asked] = $this->claimed();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active']);

    // Ended where no CallEnded listener heard it (another connection's process, a listener that failed).
    Call::query()->update(['status' => 'ended', 'end_reason' => 'dropped', 'ended_at' => now()]);

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'ended', 'end_reason' => 'dropped']);

    expect(ReplayMarker::query()->where('type', 'assist')->count())->toBe(1);
});

it('keeps the request when the agent leaves the live view, and puts both on a new call once the agent is back', function () {
    Event::fake([AssistStarted::class, AssistEnded::class]);

    [$browser, $asked, $agent, $first, $last] = $this->agentLeavesTheLiveView();
    $startedAt = AssistRequest::query()->sole()->started_at;

    // The poll that dropped the agent already says what became of the request; the old call is over.
    $last->assertOk()->assertJson(['status' => 'claimed', 'reconnecting' => true, 'call' => ['id' => $first, 'status' => 'ended', 'end_reason' => 'dropped']]);

    $request = AssistRequest::query()->sole();

    expect($request->status)->toBe('claimed')
        ->and($request->call_id)->toBeNull()
        ->and($request->agent_id)->toBe((string) $agent->id)
        ->and(ReplayMarker::query()->where('type', 'assist')->count())->toBe(0);

    // While the agent is away the page waits: no new call rings an agent who is not there.
    $this->travel(2)->seconds();
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'claimed', 'reconnecting' => true, 'call' => null]);

    expect(Call::query()->count())->toBe(1);

    // The agent's stage is back: its status poll says so.
    $this->actingAs($agent)->getJson(route('session-replay.assist.status', $asked['id']))->assertOk()->assertJson(['status' => 'claimed', 'reconnecting' => true, 'mine' => true]);

    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'reconnecting' => false]);
    $second = Call::query()->latest('id')->first();

    expect($poll->json('call.id'))->toBe($second->id)
        ->and($second->id)->not->toBe($first)
        ->and($second->status)->toBe('active')
        ->and($second->kind)->toBe('assist')
        ->and(Calls::poll((string) $agent->id, kind: 'assist')['call']['id'])->toBe($second->id);

    $request->refresh();

    // The same assist: its start (max_duration, the marker's duration) stays, and the app hears of it once.
    expect($request->status)->toBe('active')
        ->and($request->call_id)->toBe($second->id)
        ->and($request->started_at->equalTo($startedAt))->toBeTrue();

    Event::assertDispatchedTimes(AssistStarted::class, 1);
    Event::assertNotDispatched(AssistEnded::class);
});

it('lets the claim lapse when the agent does not come back to the live view in time', function () {
    [$browser, $asked] = $this->agentLeavesTheLiveView();

    $this->travel(31)->seconds();
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'open', 'agent' => null, 'reconnecting' => false, 'call' => null]);

    $request = AssistRequest::query()->sole();

    expect($request->started_at)->toBeNull()
        ->and($request->agent_id)->toBeNull()
        ->and(Call::query()->count())->toBe(1);
});

it('counts max_duration from the start of the assist while it waits for the agent to come back', function () {
    config()->set('session-replay.assist.max_duration_minutes', 1);

    [$browser, $asked] = $this->agentLeavesTheLiveView();

    // Started 8 s ago, then the claim was renewed; 55 s later the assist is past its minute.
    $this->travel(55)->seconds();
    AssistRequest::query()->update(['claimed_at' => now()]);

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'ended', 'end_reason' => 'max_duration']);
});

it('still ends the request when it is the visitor\'s page that Calls drops', function () {
    config()->set('packstub-calls.reconnect_timeout', 6);
    $this->freezeSecond();

    [$browser, $asked, $agent] = $this->claimed();

    $call = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');

    // The page is gone; the agent's stage polls on.
    for ($i = 0; $i < 4; $i++) {
        $this->travel(2)->seconds();
        Calls::poll((string) $agent->id, 0, $call, 'agent-tab', hold: true, kind: 'assist');
    }

    expect(AssistRequest::query()->sole())->status->toBe('ended')->end_reason->toBe('dropped');
});
