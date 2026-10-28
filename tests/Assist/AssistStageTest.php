<?php

use Illuminate\Testing\TestResponse;
use Packstub\Calls\Facades\Calls;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;

/** The stage's config, as the Blade view writes it for stage.js. */
function stageConfig(TestResponse $response): array
{
    preg_match('/data-config="([^"]*)"/', $response->getContent(), $match);

    return json_decode(html_entity_decode($match[1] ?? '{}', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->staffGate();
});

it('shows a Live tab with the recordings that uploaded in the last two minutes, and when they were last seen', function () {
    $live = $this->browser($this->user(['email' => 'live@example.com']));
    $this->browser($this->user(['email' => 'quiet@example.com']), ['last_activity_at' => now()->subMinutes(5)]);
    $live['session']->forceFill(['last_activity_at' => now()->subSeconds(40)])->save();

    $this->actingAs($this->staff());

    $this->get(route('session-replay.index'))
        ->assertOk()
        ->assertSee('live@example.com')
        ->assertSee('quiet@example.com')
        ->assertSee(route('session-replay.index', ['live' => 1]), false)
        ->assertSee('last seen 40 s ago');

    $this->get(route('session-replay.index', ['live' => 1]))
        ->assertOk()
        ->assertSee('live@example.com')
        ->assertDontSee('quiet@example.com')
        ->assertSee('aria-current="page"', false);

    $live['session']->forceFill(['last_activity_at' => now()->subSeconds(100)])->save();

    $this->get(route('session-replay.index', ['live' => 1]))->assertSee('last seen 1 min ago');

    ReplaySession::query()->update(['last_activity_at' => now()->subMinutes(3)]);

    $this->get(route('session-replay.index', ['live' => 1]))->assertSee('Nobody is on a recorded page right now.');
});

it('offers help on live recordings only, never on the agent\'s own, and only with Calls', function () {
    $this->needsCalls();

    $live = $this->browser();
    $quiet = $this->browser(null, ['last_activity_at' => now()->subMinutes(5)]);
    $agent = $this->staff();
    $own = $this->browser($agent);

    $this->actingAs($agent);

    $page = $this->get(route('session-replay.index'))->assertOk();

    $page->assertSee(route('session-replay.assist.propose', $live['session']), false)
        ->assertDontSee(route('session-replay.assist.propose', $quiet['session']), false)
        ->assertDontSee(route('session-replay.assist.propose', $own['session']), false);

    $this->get(route('session-replay.show', $live['session']))->assertSee(route('session-replay.assist.propose', $live['session']), false)->assertSee('Offer help');
    $this->get(route('session-replay.show', $quiet['session']))->assertDontSee('Offer help');

    $this->withoutCalls();

    $this->get(route('session-replay.index'))->assertDontSee(route('session-replay.assist.propose', $live['session']), false);
});

it('sends an offer made from the list on to the stage, and shows it there instead of the button', function () {
    $this->needsCalls();

    $browser = $this->browser();
    $agent = $this->staff();

    $response = $this->actingAs($agent)->post(route('session-replay.assist.propose', $browser['session']));
    $request = AssistRequest::query()->sole();

    $response->assertRedirect(route('session-replay.assist.stage', $request->id));

    $this->get(route('session-replay.index'))
        ->assertSee(route('session-replay.assist.stage', $request->id), false)
        ->assertDontSee(route('session-replay.assist.propose', $browser['session']), false);

    // Someone else sees that an offer is going, not the stage.
    $this->actingAs($this->staff(['name' => 'Bob']))->get(route('session-replay.index'))
        ->assertDontSee(route('session-replay.assist.stage', $request->id), false)
        ->assertSee('offered by Ana Support');
});

it('goes on to the stage after a claim from the Requests page, which links to it', function () {
    $this->needsCalls();

    $asked = $this->asked($this->browser());
    $agent = $this->staff();

    $this->actingAs($agent)->post(route('session-replay.assist.claim', $asked['id']))
        ->assertRedirect(route('session-replay.assist.stage', $asked['id']))
        ->assertSessionHas('session-replay.assist');

    $this->get(route('session-replay.assist.index'))->assertSee(route('session-replay.assist.stage', $asked['id']), false)->assertSee('Open live view');

    // A claim that fails still goes back to the list with the reason.
    $this->actingAs($this->staff(['name' => 'Bob']))->post(route('session-replay.assist.claim', $asked['id']))
        ->assertRedirect(route('session-replay.assist.index'))
        ->assertSessionHas('session-replay.assist-error');
});

it('renders the stage with what its script needs: the request, the agent routes and Calls\' own routes', function () {
    $this->needsCalls();

    Calls::routes(middleware: ['web', 'auth']);

    [, $asked, $agent] = $this->claimed();

    $page = $this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))
        ->assertOk()
        ->assertSee('data-session-replay-stage', false)
        ->assertSee(SessionReplay::scriptUrl('stage.js'), false)
        ->assertSee(SessionReplay::scriptUrl('stage.css'), false)
        ->assertSee('The checkout button does nothing')
        ->assertSee('Request control');

    $config = stageConfig($page);

    expect($config['request'])->toMatchArray(['id' => $asked['id'], 'status' => 'claimed', 'mine' => true])
        ->and($config['request'])->not->toHaveKeys(['secret_hash', 'secret', 'lock_key'])
        ->and($config['routes'])->toBe([
            'status' => route('session-replay.assist.status', $asked['id']),
            'asks' => route('session-replay.assist.asks', $asked['id']),
            'end' => route('session-replay.assist.end', $asked['id']),
            'withdraw' => route('session-replay.assist.withdraw', $asked['id']),
            'list' => route('session-replay.assist.index'),
        ])
        ->and($config['calls']['available'])->toBeTrue()
        ->and($config['calls']['routes']['poll'])->toBe(route('calls.poll'))
        ->and($config['calls']['routes']['signal'])->toBe(route('calls.signal', ['call' => '__ID__']))
        ->and($config['calls']['routes']['ice'])->toBe(route('calls.ice'))
        ->and($config['calls']['iceTransportPolicy'])->toBe('all')
        ->and($config['video'])->toBeTrue()
        ->and($config['labels']['buttons']['control'])->toBe('Request control')
        ->and($config['csrf'])->toBeString();

    config()->set('packstub-calls.video', false);

    expect(stageConfig($this->get(route('session-replay.assist.stage', $asked['id'])))['video'])->toBeFalse();
});

it('tells the agent when Calls\' routes are not registered, instead of a stage that never connects', function () {
    $this->needsCalls();

    [, $asked, $agent] = $this->claimed();

    $config = stageConfig($this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))->assertOk());

    expect($config['calls']['available'])->toBeTrue()
        ->and($config['calls']['routes'])->toBeNull()
        ->and($config['labels']['status']['no_routes'])->toContain('Calls::routes()');
});

it('opens the stage to the agent who holds the request only, asking the gate and canAssist again', function () {
    $this->needsCalls();

    [, $asked, $agent] = $this->claimed();
    $stage = route('session-replay.assist.stage', $asked['id']);

    $this->actingAs($agent)->get($stage)->assertOk();

    // Another agent, a customer the gate turns away, an id that is no request.
    $this->actingAs($this->staff(['name' => 'Bob']))->get($stage)->assertForbidden();
    $this->actingAs($this->user())->get($stage)->assertForbidden();
    $this->actingAs($agent)->get(route('session-replay.assist.stage', '00000000-0000-4000-8000-000000000000'))->assertForbidden();

    // The app's rule narrows who may help, on the stage too.
    SessionReplay::canAssistUsing(fn ($who) => $who->name === 'Senior');

    $this->actingAs($agent)->get($stage)->assertForbidden();
});

it('sends the agent back to the list from the stage of a request that is over', function () {
    $this->needsCalls();

    [, $asked, $agent] = $this->claimed();

    $this->actingAs($agent)->postJson(route('session-replay.assist.end', $asked['id']))->assertOk();

    $this->get(route('session-replay.assist.stage', $asked['id']))
        ->assertRedirect(route('session-replay.assist.index'))
        ->assertSessionHas('session-replay.assist-error');
});

it('asks a guest to sign in before the stage', function () {
    $this->needsCalls();

    [, $asked] = $this->claimed();

    auth()->logout();

    $this->get(route('session-replay.assist.stage', $asked['id']))->assertRedirect(route('login'));
});

it('keeps the queue without Calls: the claim stays on the list and the stage says there is no live view', function () {
    $this->withoutCalls();

    $asked = $this->asked($this->browser());
    $agent = $this->staff();

    $this->actingAs($agent)->post(route('session-replay.assist.claim', $asked['id']))->assertRedirect(route('session-replay.assist.index'));
    $this->get(route('session-replay.assist.index'))->assertDontSee(route('session-replay.assist.stage', $asked['id']), false);

    $config = stageConfig($this->get(route('session-replay.assist.stage', $asked['id']))->assertOk());

    expect($config['calls'])->toMatchArray(['available' => false, 'routes' => null])
        ->and($config['video'])->toBeFalse();
});

it('serves the stage script and stylesheet', function () {
    $this->get(SessionReplay::scriptUrl('stage.js'))->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    $this->get(SessionReplay::scriptUrl('stage.css'))->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
});
