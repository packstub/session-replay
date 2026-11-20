<?php

use Illuminate\Testing\TestResponse;
use Packstub\Holler\Facades\Holler;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;

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

it('offers help on live recordings only, never on the agent\'s own, and only with Holler', function () {
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

it('renders the stage with what its script needs: the request, the agent routes and Holler\'s own routes', function () {
    $this->needsCalls();

    Holler::routes(middleware: ['web', 'auth']);

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
        ->and($config['calls']['routes']['poll'])->toBe(route('holler.poll'))
        ->and($config['calls']['routes']['signal'])->toBe(route('holler.signal', ['call' => '__ID__']))
        ->and($config['calls']['routes']['ice'])->toBe(route('holler.ice'))
        ->and($config['calls']['iceTransportPolicy'])->toBe('all')
        ->and($config['video'])->toBeTrue()
        ->and($config['labels']['buttons']['control'])->toBe('Request control')
        ->and($config['csrf'])->toBeString();

    config()->set('holler.video', false);

    expect(stageConfig($this->get(route('session-replay.assist.stage', $asked['id'])))['video'])->toBeFalse();
});

it('tells the agent when Holler\'s routes are not registered, instead of a stage that never connects', function () {
    $this->needsCalls();

    [, $asked, $agent] = $this->claimed();

    $config = stageConfig($this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))->assertOk());

    expect($config['calls']['available'])->toBeTrue()
        ->and($config['calls']['routes'])->toBeNull()
        ->and($config['labels']['status']['no_routes'])->toContain('Holler::routes()');
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

it('keeps the queue without Holler: the claim stays on the list and the stage says there is no live view', function () {
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

it('polls Holler from the stage as often as Holler says', function () {
    $this->needsCalls();

    [, $asked, $agent] = $this->claimed();
    $stage = route('session-replay.assist.stage', $asked['id']);

    config()->set('holler.poll.active', 1500);
    expect(stageConfig($this->actingAs($agent)->get($stage))['calls']['pollMs'])->toBe(1500);

    // Kept in a sane range.
    config()->set('holler.poll.active', 5);
    expect(stageConfig($this->get($stage))['calls']['pollMs'])->toBe(250);
});

it('keeps the mirror from fetching what the visitor\'s page points it at: a CSP on the stage for images, styles, fonts and media', function () {
    config()->set('app.url', 'https://shop.test');
    config()->set('app.asset_url', 'https://cdn.shop.test/build');

    [, $asked, $agent] = $this->claimed();

    $policy = $this->actingAs($agent)->get(route('session-replay.assist.stage', $asked['id']))->assertOk()->headers->get('Content-Security-Policy');

    expect($policy)->toContain("img-src 'self' data: blob: https://shop.test https://cdn.shop.test")
        ->toContain("style-src 'self' 'unsafe-inline' https://shop.test https://cdn.shop.test")
        ->toContain("font-src 'self' data: https://shop.test https://cdn.shop.test")
        ->toContain("media-src 'self' data: blob: mediastream: https://shop.test https://cdn.shop.test")
        ->toContain("frame-src 'self' https://shop.test https://cdn.shop.test")
        ->toContain("object-src 'none'")
        // Nothing about scripts or connections: the app's own policy, if any, decides those.
        ->not->toContain('script-src')->not->toContain('connect-src')->not->toContain('default-src');

    // More origins for the app's assets (a font service, a CDN), or "*" for none of it.
    config()->set('session-replay.assist.mirror_sources', ['https://fonts.example.com']);

    expect($this->get(route('session-replay.assist.stage', $asked['id']))->headers->get('Content-Security-Policy'))->toContain("font-src 'self' data: https://shop.test https://cdn.shop.test https://fonts.example.com");

    config()->set('session-replay.assist.mirror_sources', ['*']);

    $this->get(route('session-replay.assist.stage', $asked['id']))->assertHeaderMissing('Content-Security-Policy');
});

it('keeps a mirror source from adding a directive of its own to the stage\'s CSP', function () {
    config()->set('app.url', 'https://shop.test');
    config()->set('app.asset_url', null);
    config()->set('session-replay.assist.mirror_sources', [
        "cdn.example.com; script-src 'unsafe-inline'",
        "https://ok.example.com\r\nX-Injected: 1",
        "fonts.example.com\x00evil.test",
        "fonts\x0b.example.com",
        "'unsafe-eval'",
        'a.example.com,b.example.com',
        ' https://spaced.example.com ',
        'https://path.example.com/x;y',
    ]);

    $policy = app(Assist::class)->mirrorPolicy();

    expect($policy)->toContain("img-src 'self' data: blob: https://shop.test https://spaced.example.com https://path.example.com;")
        ->not->toContain('unsafe-eval')->not->toContain('script-src')->not->toContain('X-Injected')
        ->not->toContain('cdn.example.com')->not->toContain('fonts.example.com')->not->toContain('a.example.com')
        ->and(substr_count($policy, ';'))->toBe(5);

    // "*" with spaces around it, as an env list gives it, still sends no policy.
    config()->set('session-replay.assist.mirror_sources', ['https://cdn.example.com', ' * ']);

    expect(app(Assist::class)->mirrorPolicy())->toBeNull();
});
