<?php

use Illuminate\Support\Facades\Route;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallParticipant;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Support\Assist;

/*
 * Assist and Holler's hold (a participant status, `on_hold`: someone stepped out of a call without leaving it).
 * Assist honours it: the request stays live while its agent has the call on hold, the visitor's page keeps its side
 * and is told the agent stepped away, and the hold lasts for as long as the agent's live view is open. What a window
 * does to keep its side of a call is a *claim* (Call::CLAIM_SECONDS), which is what the poll ceilings follow.
 */

beforeEach(function () {
    $this->needsCalls();
    $this->staffGate();
    $this->freezeSecond();
});

it('hands the stage Holler\'s hold and resume routes next to the others', function () {
    Holler::routes(middleware: ['web', 'auth']);

    [, $asked, $agent] = $this->claimed();

    $response = $this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))->assertOk();
    preg_match('/data-config="([^"]*)"/', $response->getContent(), $matches);
    $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    expect($config['calls']['routes']['hold'])->toBe(route('holler.hold', ['call' => '__ID__']))
        ->and($config['calls']['routes']['resume'])->toBe(route('holler.resume', ['call' => '__ID__']))
        ->and($config['labels']['buttons'])->toHaveKeys(['hold', 'resume', 'pointer'])
        ->and($config['labels']['status'])->toHaveKey('on_hold');

    $response->assertSee('data-action="hold"', false)->assertSee('data-action="pointer"', false);
});

it('leaves the Hold button out for a Holler whose routes have no hold', function () {
    // Routes registered by hand under another prefix, without hold and resume (an app's own set).
    Route::middleware(['web', 'auth'])->name('support.calls.')->group(function () {
        foreach (['poll', 'store', 'ice'] as $name) {
            Route::get('support/calls/'.$name, fn () => [])->name($name);
        }

        foreach (['answer', 'decline', 'hangup', 'signal', 'leave'] as $name) {
            Route::post('support/calls/{call}/'.$name, fn () => [])->name($name);
        }
    });
    config()->set('session-replay.assist.calls_routes', 'support.calls.');

    [, $asked, $agent] = $this->claimed();

    preg_match('/data-config="([^"]*)"/', $this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))->assertOk()->getContent(), $matches);
    $routes = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['calls']['routes'];

    expect($routes)->toHaveKeys(['poll', 'signal'])->not->toHaveKeys(['hold', 'resume']);
});

it('keeps the request live while its agent has the assist on hold, and the visitor\'s page on its side of the call', function () {
    [$browser, $asked, $agent, $call] = $this->liveAssist();

    Holler::hold($call, (string) $agent->id);

    // The page polls on (2 s) and the stage stays open (its status polls): nothing ends, however long.
    for ($i = 0; $i < 20; $i++) {
        $this->travel(2)->seconds();
        expect($this->stagePolls($asked))->toMatchArray(['status' => 'active', 'mine' => true, 'call' => $call->id]);
        $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();
    }

    // The page sees the agent as away with the call on hold (its engine says "waiting", the panel "right back").
    expect($poll->json('status'))->toBe('active')
        ->and($poll->json('reconnecting'))->toBeFalse()
        ->and($poll->json('call.status'))->toBe('active')
        ->and($poll->json('call.peers.0.status'))->toBe('on_hold')
        ->and($poll->json('call.claimed_elsewhere'))->toBeFalse();

    // On the stage's own poll the call is the one on hold, not a call to run.
    $theirs = Holler::poll((string) $agent->id, 0, $call->id, 'agent-tab', claim: true, kind: 'assist');
    expect($theirs['call'])->toBeNull()
        ->and($theirs['on_hold']['id'])->toBe($call->id);

    // Resume: the same call and the same request, nothing asked of the visitor again.
    Holler::resume($call, (string) $agent->id, 'agent-tab');

    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'consent' => null]);
    expect($poll->json('call.id'))->toBe($call->id)
        ->and($poll->json('call.peers.0.status'))->toBe('joined')
        ->and(Call::query()->count())->toBe(1);
});

it('lets a hold last only while the live view is open: an agent who leaves it with the assist on hold has left', function () {
    config()->set('holler.reconnect_timeout', 6);
    config()->set('holler.hold_timeout', 6);

    [$browser, $asked, $agent, $call] = $this->liveAssist();

    Holler::hold($call, (string) $agent->id);

    // The stage is closed, but a panel tab of the agent's still polls Holler: Holler alone would keep the hold.
    for ($i = 0; $i < 4; $i++) {
        $this->travel(2)->seconds();
        Holler::poll((string) $agent->id, kind: 'call');
        $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();
    }

    // As when Holler drops the agent's side: back to claimed without a call, the page says they are reconnecting.
    $poll->assertJson(['status' => 'claimed', 'reconnecting' => true, 'call' => null]);

    $call->refresh()->load('participants');
    expect($call->status)->toBe('ended')
        ->and($call->end_reason)->toBe('dropped')
        ->and($call->participant((string) $agent->id)->status)->toBe(CallParticipant::DROPPED)
        ->and(AssistRequest::query()->sole()->agent_id)->toBe((string) $agent->id);

    // Back on the live view: a new call for the same request.
    $this->travel(2)->seconds();
    $this->stagePolls($asked);
    $again = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'reconnecting' => false]);

    expect($again->json('call.id'))->not->toBe($call->id);
});

it('leaves a call the agent put on hold alone when they take an assist, and keeps it from the stage', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);
    $agent = $this->staff();
    $colleague = $this->staff(['name' => 'Bo Colleague']);

    // A call with a colleague in the Holler widget, put on hold before claiming.
    $widget = Holler::start($agent, $colleague, 'widget-tab');
    Holler::answer($widget, (string) $colleague->id, 'their-tab');
    Holler::hold($widget, (string) $agent->id);

    $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();
    $assist = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active'])->json('call.id');

    // The call on hold waits for the agent (a call they were *in* would have been left for the assist).
    $widget->refresh()->load('participants');
    expect($widget->status)->toBe('active')
        ->and($widget->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD);

    // Each side polls for its own kind: the stage never hears of the widget's call, the widget never of the assist.
    $stage = Holler::poll((string) $agent->id, 0, $assist, 'agent-tab', claim: true, kind: 'assist');
    $panel = Holler::poll((string) $agent->id, kind: 'call');

    expect($stage['call']['id'])->toBe($assist)
        ->and($stage['on_hold'])->toBeNull()
        ->and($panel['call'])->toBeNull()
        ->and($panel['on_hold']['id'])->toBe($widget->id);
});

it('puts the assist on hold when the agent resumes another call in its place, and never shows it to the widget', function () {
    $browser = $this->browser();
    $asked = $this->asked($browser);
    $agent = $this->staff();
    $colleague = $this->staff(['name' => 'Bo Colleague']);

    $widget = Holler::start($agent, $colleague, 'widget-tab');
    Holler::answer($widget, (string) $colleague->id, 'their-tab');
    Holler::hold($widget, (string) $agent->id);

    $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();
    $assist = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');
    Holler::poll((string) $agent->id, 0, $assist, 'agent-tab', claim: true, kind: 'assist');

    // Resume in the widget: Holler swaps, the call the agent was in (the assist) goes on hold.
    Holler::resume($widget, (string) $agent->id, 'widget-tab');

    $call = Call::query()->with('participants')->findOrFail($assist);
    expect($call->status)->toBe('active')
        ->and($call->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD);

    // The widget (kind=call) has its own call back and nothing of the assist, not even as "on hold".
    $panel = Holler::poll((string) $agent->id, 0, $widget->id, 'widget-tab', claim: true, kind: 'call');
    expect($panel['call']['id'])->toBe($widget->id)
        ->and($panel['on_hold'])->toBeNull()
        ->and($panel['incoming'])->toBeNull();

    // The stage learns its call is on hold; the request is live and the page waits.
    $stage = Holler::poll((string) $agent->id, 0, $assist, 'agent-tab', claim: true, kind: 'assist');
    expect($stage['call'])->toBeNull()
        ->and($stage['on_hold']['id'])->toBe($assist);

    $this->stagePolls($asked);
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'call' => ['id' => $assist, 'peers' => [['status' => 'on_hold']]]]);

    // Resume on the stage: the swap the other way, the colleague's call waits on hold.
    Holler::resume($call, (string) $agent->id, 'agent-tab');

    expect($widget->refresh()->load('participants')->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD)
        ->and($call->refresh()->load('participants')->participant((string) $agent->id)->status)->toBe(CallParticipant::JOINED)
        ->and(AssistRequest::query()->sole()->status)->toBe('active');
});

it('ends the assist as usual when the visitor ends it while it is on hold', function () {
    [$browser, $asked, $agent, $call] = $this->liveAssist();

    Holler::hold($call, (string) $agent->id);

    $this->visitorPost('hangup', $asked['id'], $asked['secret'], $browser['token'])->assertOk();

    expect(AssistRequest::query()->sole()->status)->toBe('ended')
        ->and($call->refresh()->status)->toBe('ended')
        // Nothing is left on hold for the agent.
        ->and(Holler::poll((string) $agent->id, kind: 'assist')['on_hold'])->toBeNull();
});

it('takes the visitor\'s poll as a claim of the guest\'s side by default, and without one when the page says so', function () {
    [$browser, $asked] = $this->claimed();

    $call = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');
    $guest = fn () => Call::query()->with('participants')->findOrFail($call)->participant('guest:'.$asked['id']);

    expect($guest()->tab)->toBe('tab-1')
        ->and($guest()->isClaimed())->toBeTrue();

    // A look without claiming (claim: false) from another window changes nothing and gets no signals.
    Holler::signal(Call::query()->with('participants')->findOrFail($call), (string) AssistRequest::query()->sole()->agent_id, 'offer', ['type' => 'offer', 'sdp' => 'v=0']);

    $look = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-2', 'claim' => false])->assertOk();
    expect($look->json('signals'))->toBe([])
        ->and($look->json('call.claimed_elsewhere'))->toBeTrue()
        ->and($guest()->tab)->toBe('tab-1');

    // The window with the side reads them; once it polls past them they are gone (Holler deletes read signals).
    $mine = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();
    expect($mine->json('signals'))->toHaveCount(1);

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1', 'after' => $mine->json('cursor')])->assertOk();
    expect(Call::query()->findOrFail($call)->signals()->count())->toBe(0);

    // Past Holler's claim the side is free for the tab's next page.
    $this->travel(Call::CLAIM_SECONDS + 1)->seconds();
    expect($guest()->isClaimed())->toBeFalse();
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-2'])->assertJson(['call' => ['claimed_elsewhere' => false]]);
    expect($guest()->tab)->toBe('tab-2');
});

it('gives an assist on hold Holler\'s hold_timeout, longer than a dropped side gets: a stage tab the browser froze is not an agent who left', function () {
    config()->set('holler.reconnect_timeout', 6);

    [$browser, $asked, $agent, $call] = $this->liveAssist();

    Holler::hold($call, (string) $agent->id);

    expect(app(Assist::class)->holdPatienceSeconds())->toBe(120);

    // A minute and a half without the stage's status poll (the tab is hidden and frozen); a panel tab polls Holler.
    for ($i = 0; $i < 45; $i++) {
        $this->travel(2)->seconds();
        Holler::poll((string) $agent->id, kind: 'call');
        $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();
    }

    $poll->assertJson(['status' => 'active', 'call' => ['id' => $call->id, 'peers' => [['status' => 'on_hold']]]]);

    // Past it, the agent has left.
    for ($i = 0; $i < 20; $i++) {
        $this->travel(2)->seconds();
        Holler::poll((string) $agent->id, kind: 'call');
        $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertOk();
    }

    $poll->assertJson(['status' => 'claimed', 'reconnecting' => true]);

    // Never shorter than Holler's reconnect_timeout, and Holler's "for good" (0) leaves it to max_duration.
    config()->set('holler.hold_timeout', 3);
    expect(app(Assist::class)->holdPatienceSeconds())->toBe(6);
    config()->set('holler.hold_timeout', 0);
    expect(app(Assist::class)->holdPatienceSeconds())->toBe(0);
});

it('leaves an agent alone who resumed between the look and the lock', function () {
    config()->set('holler.reconnect_timeout', 6);
    config()->set('holler.hold_timeout', 6);

    [, $asked, $agent, $call] = $this->liveAssist();

    Holler::hold($call, (string) $agent->id);

    // What reconcile() read: the agent on hold, the stage quiet for longer than the patience.
    $this->travel(10)->seconds();
    $request = AssistRequest::query()->findOrFail($asked['id']);
    $stale = Call::query()->with('participants')->findOrFail($call->id);

    expect($stale->participant((string) $agent->id)->isOnHold())->toBeTrue();

    // Meanwhile they clicked Resume.
    Holler::resume($call, (string) $agent->id, 'agent-tab');

    app(Assist::class)->letGoOfAbandonedHold($request, $stale);

    $call->refresh()->load('participants');
    expect($call->status)->toBe('active')
        ->and($call->participant((string) $agent->id)->status)->toBe(CallParticipant::JOINED)
        ->and(AssistRequest::query()->sole())->status->toBe('active')->call_id->toBe($call->id);

    // The same for a stage that polled the status between the two: still on hold, and still theirs.
    Holler::hold($call, (string) $agent->id);
    $this->travel(10)->seconds();
    $request = AssistRequest::query()->findOrFail($asked['id']);
    $stale = Call::query()->with('participants')->findOrFail($call->id);
    $this->stagePolls($asked);

    app(Assist::class)->letGoOfAbandonedHold($request, $stale);

    expect($call->refresh()->load('participants')->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD)
        ->and(AssistRequest::query()->sole()->status)->toBe('active');

    // And quiet for real, it is let go.
    $this->travel(10)->seconds();
    app(Assist::class)->letGoOfAbandonedHold(AssistRequest::query()->findOrFail($asked['id']), Call::query()->with('participants')->findOrFail($call->id));

    expect($call->refresh()->status)->toBe('ended')
        ->and(AssistRequest::query()->sole()->status)->toBe('claimed');
});

it('refuses a second assist to an agent who is live on one, and takes it once the first is on hold', function () {
    [, $first, $agent, $call] = $this->liveAssist();

    $other = $this->browser($this->user(['email' => 'second@example.com']));
    $second = $this->asked($other);

    // Holler would take the agent out of the first call for the second: refused instead, the first goes on.
    $this->postJson(route('session-replay.assist.claim', $second['id']))->assertStatus(409)->assertJson(['reason' => 'busy']);

    expect(AssistRequest::query()->findOrFail($second['id'])->status)->toBe('open')
        ->and($call->refresh()->status)->toBe('active');

    // Nor an offer of help to someone else.
    $live = $this->browser($this->user(['email' => 'third@example.com']));
    $this->postJson(route('session-replay.assist.propose', $live['session']))->assertStatus(409)->assertJson(['reason' => 'busy']);

    Holler::hold($call, (string) $agent->id);

    $this->postJson(route('session-replay.assist.claim', $second['id']))->assertOk();
    $this->poll($second['id'], $second['secret'], $other['token'], ['tab' => 'tab-b'])->assertJson(['status' => 'active']);

    // The first waits on hold, untouched.
    expect($call->refresh()->load('participants')->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD)
        ->and(AssistRequest::query()->findOrFail($first['id'])->status)->toBe('active');
});

it('never ends the assist an agent is live on when an older one of theirs comes back for a new call', function () {
    config()->set('holler.reconnect_timeout', 6);
    config()->set('holler.hold_timeout', 6);
    config()->set('session-replay.assist.claim_timeout_seconds', 600);

    [$browserA, $a, $agent, $callA] = $this->liveAssist();

    // A on hold, B claimed and live.
    Holler::hold($callA, (string) $agent->id);

    $browserB = $this->browser($this->user(['email' => 'second@example.com']));
    $b = $this->asked($browserB);
    $this->postJson(route('session-replay.assist.claim', $b['id']))->assertOk();
    $callB = $this->poll($b['id'], $b['secret'], $browserB['token'], ['tab' => 'tab-b'])->assertJson(['status' => 'active'])->json('call.id');
    Holler::poll((string) $agent->id, 0, $callB, 'agent-tab-b', claim: true, kind: 'assist');

    // Stage A's tab is closed: its hold lapses, A is back to claimed. Stage B and both pages poll on.
    for ($i = 0; $i < 5; $i++) {
        $this->travel(2)->seconds();
        $this->stagePolls($b);
        Holler::poll((string) $agent->id, 0, $callB, 'agent-tab-b', claim: true, kind: 'assist');
        $this->poll($b['id'], $b['secret'], $browserB['token'], ['tab' => 'tab-b']);
        $pollA = $this->poll($a['id'], $a['secret'], $browserA['token'], ['tab' => 'tab-1']);
    }

    $pollA->assertJson(['status' => 'claimed', 'reconnecting' => true]);

    // The agent opens A's live view again while still live on B: no new call, B untouched, and the stage is told why.
    $this->travel(2)->seconds();
    expect($this->stagePolls($a))->toMatchArray(['status' => 'claimed', 'blocked' => 'other_assist']);
    Holler::poll((string) $agent->id, 0, $callB, 'agent-tab-b', claim: true, kind: 'assist');
    $this->poll($a['id'], $a['secret'], $browserA['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'claimed', 'call' => null]);

    $live = Call::query()->with('participants')->findOrFail($callB);
    expect($live->status)->toBe('active')
        ->and($live->participant((string) $agent->id)->status)->toBe(CallParticipant::JOINED)
        ->and(AssistRequest::query()->findOrFail($b['id'])->status)->toBe('active')
        ->and(Call::query()->where('kind', 'assist')->where('status', '!=', 'ended')->count())->toBe(1);

    // B on hold: A's next poll makes its call, and B waits.
    Holler::hold($live, (string) $agent->id);
    $this->travel(2)->seconds();
    expect($this->stagePolls($a)['blocked'])->toBeNull();
    $this->stagePolls($b);
    $this->poll($a['id'], $a['secret'], $browserA['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active', 'reconnecting' => false]);

    expect($live->refresh()->load('participants')->participant((string) $agent->id)->status)->toBe(CallParticipant::ON_HOLD)
        ->and(AssistRequest::query()->findOrFail($b['id'])->status)->toBe('active');
});

it('refuses Put on hold while a call in the widget is on hold already: one call on hold per person', function () {
    Holler::routes(middleware: ['web', 'auth']);

    $browser = $this->browser();
    $asked = $this->asked($browser);
    $agent = $this->staff();
    $colleague = $this->staff(['name' => 'Bo Colleague']);

    $widget = Holler::start($agent, $colleague, 'widget-tab');
    Holler::answer($widget, (string) $colleague->id, 'their-tab');
    Holler::hold($widget, (string) $agent->id);

    $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();
    $assist = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->json('call.id');

    // The stage's Put on hold is Holler's own route.
    $this->postJson(route('holler.hold', ['call' => $assist]))
        ->assertStatus(422)
        ->assertJson(['message' => 'You already have a call on hold.']);

    expect(Call::query()->with('participants')->findOrFail($assist)->participant((string) $agent->id)->status)->toBe(CallParticipant::JOINED)
        ->and(AssistRequest::query()->sole()->status)->toBe('active');
});

it('takes a window id of up to 32 characters, as Holler\'s column and routes do', function () {
    [$browser, $asked] = $this->claimed();

    // Too long (or not an id at all): no window, so nothing is claimed and nothing fails.
    $long = str_repeat('a', 33);
    $poll = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => $long])->assertOk()->assertJson(['status' => 'active']);
    $guest = fn () => Call::query()->with('participants')->findOrFail($poll->json('call.id'))->participant('guest:'.$asked['id']);

    expect($guest()->tab)->toBeNull();

    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => str_repeat('b', 64)])->assertOk();
    $this->visitorPost('leave', $asked['id'], $asked['secret'], $browser['token'], ['tab' => $long])->assertNoContent();
    expect($guest()->tab)->toBeNull();

    $exact = str_repeat('c', 32);
    $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => $exact])->assertOk();
    expect($guest()->tab)->toBe($exact);
});
