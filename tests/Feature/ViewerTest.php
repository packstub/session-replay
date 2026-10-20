<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Tests\Fixtures\Policies\ReplaySessionPolicy;

it('lets nobody in outside the local environment until the app defines the gate', function () {
    $session = $this->recording();

    $this->actingAs($this->user());

    $this->get(route('session-replay.index'))->assertForbidden();
    $this->get(route('session-replay.show', $session))->assertForbidden();
    $this->get(route('session-replay.manifest', $session))->assertForbidden();
    $this->get(route('session-replay.chunk', [$session, 0]))->assertForbidden();

    expect(SessionReplay::check())->toBeFalse();
});

it('lets the local environment in while there is no gate', function () {
    $session = $this->recording();

    $this->app->detectEnvironment(fn () => 'local');
    $this->actingAs($this->user());

    $this->get(route('session-replay.index'))->assertOk();
    $this->get(route('session-replay.show', $session))->assertOk();
});

it('sends guests to the login page', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $this->get(route('session-replay.index'))->assertRedirect(route('login'));
});

it('shows the list and the player to whoever the gate allows', function () {
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $user->is_staff);

    $customer = $this->user(['email' => 'customer@example.com']);
    $session = $this->recording($customer, ['error_count' => 2]);

    $this->actingAs($this->user(['is_staff' => true]));

    $this->get(route('session-replay.index'))
        ->assertOk()
        ->assertSee('customer@example.com')
        ->assertSee(route('session-replay.show', $session));

    $this->get(route('session-replay.show', $session))
        ->assertOk()
        ->assertSee('data-session-replay-player', false)
        ->assertSee(route('session-replay.manifest', $session), false)
        ->assertSee('player.js', false);

    $this->actingAs($customer)->get(route('session-replay.index'))->assertForbidden();
});

it('links back to the filtered list, never to a page the Referer names', function () {
    Gate::define('viewSessionReplay', fn () => true);

    $session = $this->recording();
    $filtered = route('session-replay.index').'?errors=1';

    $this->actingAs($this->user());

    $this->withHeader('referer', $filtered)->get(route('session-replay.show', $session))->assertSee('href="'.e($filtered).'"', false);
    $this->withHeader('referer', 'https://evil.test/login')->get(route('session-replay.show', $session))
        ->assertDontSee('evil.test')
        ->assertSee('href="'.route('session-replay.index').'"', false);
});

it('passes the recording to the gate, for the page and for every file behind it', function () {
    $staff = $this->user(['is_staff' => true]);
    $mine = $this->recording($this->user());
    $other = $this->recording($staffMember = $this->user(['is_staff' => true]));

    // Support may watch customers, never other staff.
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $user->is_staff && ! $session?->user?->is_staff);

    $this->actingAs($staff);

    $this->get(route('session-replay.index'))->assertOk();
    $this->get(route('session-replay.show', $mine))->assertOk();
    $this->get(route('session-replay.manifest', $mine))->assertOk();
    $this->get(route('session-replay.chunk', [$mine, 0]))->assertOk();

    $this->get(route('session-replay.show', $other))->assertForbidden();
    $this->get(route('session-replay.manifest', $other))->assertForbidden();
    $this->get(route('session-replay.chunk', [$other, 0]))->assertForbidden();
    $this->get(route('session-replay.asset', [$other, str_repeat('a', 64)]))->assertForbidden();

    expect(SessionReplay::check($mine, $staff))->toBeTrue()
        ->and(SessionReplay::check($other, $staff))->toBeFalse()
        ->and($staffMember->is_staff)->toBeTrue();
});

it('serves the manifest, chunks as stored gzip, and stylesheets', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $user = $this->user();
    $session = $this->recording($user);
    $now = now()->getTimestampMs();
    $css = str_repeat('a{b:c}', 1000);

    $this->ingest(['token' => $this->token($user), 'session' => $session->id, 'seq' => 1, 'meta' => ['markers' => [
        ['type' => 'error', 'label' => 'Boom', 'at' => $now],
    ]]])->assertOk();

    $this->post(route('session-replay.ingest.asset'), ['token' => $this->token($user), 'hash' => hash('sha256', $css), 'content' => $css])->assertCreated();

    $this->actingAs($user);

    $manifest = $this->getJson(route('session-replay.manifest', $session))->assertOk()->json();

    expect($manifest['id'])->toBe($session->id)
        ->and($manifest['chunks'])->toHaveCount(2)
        ->and($manifest['chunks'][1]['url'])->toBe(route('session-replay.chunk', [$session, 1]))
        ->and($manifest['markers'][0]['label'])->toBe('Boom')
        ->and($manifest['markers'][0]['logUrl'])->toBeNull()
        ->and($manifest['assetUrl'])->toContain('__hash__');

    // Where the marker's log lines are read, when the app says: the player's "Open in logs".
    SessionReplay::logUrlUsing(fn (ReplayMarker $marker): ?string => $marker->type === 'error' ? 'https://logs.test/?query='.urlencode($marker->label) : null);

    $markers = $this->getJson(route('session-replay.manifest', $session))->assertOk()->json('markers');

    expect($markers[0]['logUrl'])->toBe('https://logs.test/?query=Boom')
        ->and(array_column($markers, 'logUrl'))->toHaveCount(count($markers));

    $chunk = $this->get(route('session-replay.chunk', [$session, 0]))->assertOk()->assertHeader('Content-Encoding', 'gzip');

    expect(json_decode(gzdecode($chunk->streamedContent()), true))->toHaveCount(3);

    $asset = $this->get(route('session-replay.asset', [$session, hash('sha256', $css)]))->assertOk()->assertHeader('Content-Encoding', 'gzip');

    expect(gzdecode($asset->streamedContent()))->toBe($css);

    $this->get(route('session-replay.chunk', [$session, 7]))->assertNotFound();
    $this->get(route('session-replay.asset', [$session, str_repeat('b', 64)]))->assertNotFound();
});

it('filters the list', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $ada = $this->user(['email' => 'ada@example.com']);
    $grace = $this->user(['email' => 'grace@example.com']);

    $this->recording($ada, ['error_count' => 1]);
    $this->recording($grace);

    $this->actingAs($ada);

    $this->get(route('session-replay.index', ['errors' => 1]))->assertSee('ada@example.com')->assertDontSee('grace@example.com');
    $this->get(route('session-replay.index', ['user' => $grace->id]))->assertSee('grace@example.com')->assertDontSee('ada@example.com');
    $this->get(route('session-replay.index', ['from' => now()->addDay()->toDateString()]))->assertSee('No recordings yet');
    $this->get(route('session-replay.index', ['from' => 'nonsense']))->assertOk();
});

it('serves the built scripts to anyone, with a long cache', function () {
    $this->get(route('session-replay.script', 'recorder.js'))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get(route('session-replay.script', 'player.css'))->assertOk();
    $this->get(route('session-replay.script', 'secrets.env'))->assertNotFound();
});

it('keeps recordings out of the list with visibleUsing, in the query', function () {
    Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => $session === null || $session->user_id !== (string) $user->id);

    $ada = $this->user(['email' => 'ada@example.com']);
    $grace = $this->user(['email' => 'grace@example.com']);

    $this->recording($ada);
    $this->recording($grace);

    SessionReplay::visibleUsing(fn ($query, $viewer) => $query->where('user_id', '!=', (string) $viewer->id));

    $this->actingAs($ada)->get(route('session-replay.index'))->assertOk()->assertSee('grace@example.com')->assertDontSee('ada@example.com');

    expect(SessionReplay::visibleTo(ReplaySession::query(), $grace)->count())->toBe(1);
});

it('speaks the app\'s language, in the pages and in the player', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $session = $this->recording($this->user());

    app()->setLocale('de');

    $this->actingAs($this->user());

    $this->get(route('session-replay.index'))->assertSee('1 Aufzeichnung')->assertSee('Ansehen');
    $this->get(route('session-replay.show', $session))->assertSee('Alle Aufzeichnungen')->assertSee('Aufzeichnung wird geladen', false);
});

it('ships every string in every language', function () {
    foreach (['viewer', 'player', 'recorder'] as $file) {
        $english = array_keys(Arr::dot(require __DIR__."/../../resources/lang/en/{$file}.php"));

        foreach (['de', 'es', 'ro', 'ru'] as $locale) {
            expect(array_keys(Arr::dot(require __DIR__."/../../resources/lang/{$locale}/{$file}.php")))->toBe($english);
        }
    }
});

it('keeps an orWhere in visibleUsing inside the rule, so it never opens what the query narrowed', function () {
    config()->set('session-replay.guests', true);

    $ada = $this->user();
    $acme = $this->team('Acme');
    $globex = $this->team('Globex');

    $this->ingest(['token' => $this->token($ada, $acme)])->assertCreated();
    $this->ingest(['token' => $this->token(null, $acme)])->assertCreated();
    $this->ingest(['token' => $this->token($ada, $globex)])->assertCreated();
    $this->ingest(['token' => $this->token(null, $globex)])->assertCreated();

    // A viewer sees their own recordings or guests'.
    SessionReplay::visibleUsing(fn ($query, $viewer) => $query->where('user_id', (string) $viewer->id)->orWhereNull('user_id'));

    // A panel narrows to its workspace before the rule.
    $visible = SessionReplay::visibleTo(ReplaySession::query()->forTenant($acme), $ada)->get();

    expect($visible)->toHaveCount(2)
        ->and($visible->pluck('tenant_id')->unique()->all())->toBe([(string) $acme->id]);
});

it('ignores a list where the list expects one value', function () {
    Gate::define('viewSessionReplay', fn () => true);

    $this->recording($this->user(['email' => 'ada@example.com']));

    $this->actingAs($this->user());

    $this->get(route('session-replay.index').'?user[]=1&from[]=x&errors[]=1')->assertOk()->assertSee('ada@example.com');
});

it('loads the player script once per tab with wire:navigate', function () {
    $html = (string) SessionReplay::playerAssets();

    expect($html)->toMatch('/<script src="[^"]+player\.js[^"]*" defer data-navigate-once><\/script>/')
        ->and($html)->toContain('player.css');
});

it('offers the link to the moment in the player unless the page turns it off', function () {
    $session = $this->recording($this->user());

    expect(Blade::render('<x-session-replay::player :session="$session" :assets="false" />', ['session' => $session]))->toContain('data-copy-link="true"')
        ->and(Blade::render('<x-session-replay::player :session="$session" :assets="false" :copy-link="false" />', ['session' => $session]))->toContain('data-copy-link="false"');
});

it('asks a ReplaySession policy, for the pages and the data, when the app defines no gate', function () {
    Gate::policy(ReplaySession::class, ReplaySessionPolicy::class);

    $open = $this->recording();
    $pinned = $this->recording(null, ['pinned' => true]);

    $this->actingAs($this->user(['is_staff' => true]));

    $this->get(route('session-replay.index'))->assertOk();
    $this->get(route('session-replay.show', $open))->assertOk();
    $this->get(route('session-replay.manifest', $open))->assertOk();
    $this->get(route('session-replay.chunk', [$open, 0]))->assertOk();
    $this->get(route('session-replay.manifest', $pinned))->assertForbidden();

    $this->actingAs($this->user());

    $this->get(route('session-replay.index'))->assertForbidden();
    $this->get(route('session-replay.manifest', $open))->assertForbidden();
});

it('lets the gate decide when the app defines both the gate and a policy', function () {
    Gate::policy(ReplaySession::class, ReplaySessionPolicy::class);
    Gate::define('viewSessionReplay', fn ($user) => $user->email === 'support@example.com');

    $session = $this->recording();

    $this->actingAs($this->user(['is_staff' => true]))->get(route('session-replay.manifest', $session))->assertForbidden();
    $this->actingAs($this->user(['email' => 'support@example.com']))->get(route('session-replay.manifest', $session))->assertOk();
});

it('lists the recordings the person reported a problem from', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $ada = $this->user(['email' => 'ada@example.com']);
    $grace = $this->user(['email' => 'grace@example.com']);

    $this->ingest(['token' => $this->token($ada), 'meta' => ['markers' => [
        ['type' => 'report', 'label' => 'Wrong total on the invoice', 'at' => now()->getTimestampMs()],
    ]]])->assertCreated();
    $this->ingest(['token' => $this->token($grace), 'meta' => ['markers' => [
        ['type' => 'custom', 'label' => 'Checkout started', 'at' => now()->getTimestampMs()],
    ]]])->assertCreated();

    expect(ReplaySession::query()->reported()->sole()->user->is($ada))->toBeTrue();

    $this->actingAs($ada);

    $this->get(route('session-replay.index'))->assertSeeInOrder(['ada@example.com', 'reported'])->assertSee('grace@example.com');
    $this->get(route('session-replay.index', ['reported' => 1]))->assertSee('ada@example.com')->assertDontSee('grace@example.com');
});

it('names the impersonator in the player page when impersonatorUsing() gave a model', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $admin = $this->user(['email' => 'admin@example.com']);
    $customer = $this->user();
    $byModel = $this->recording(null, []);
    $byModel->forceFill(['impersonator_type' => $admin->getMorphClass(), 'impersonator_id' => (string) $admin->id])->save();
    $byKey = $this->recording($customer, ['impersonator_id' => '42']);

    $this->actingAs($admin);

    $this->get(route('session-replay.show', $byModel))->assertSee('admin@example.com');
    $this->get(route('session-replay.show', $byKey))->assertSee('#42');
});

it('serves a stylesheet only to a recording that points at it, once the recording has references', function () {
    Gate::define('viewSessionReplay', fn ($user) => true);

    $user = $this->user();
    $css = str_repeat('a{b:c}', 1000);
    $other = str_repeat('d{e:f}', 1000);
    $session = $this->recording($user);

    foreach ([$css, $other] as $sheet) {
        $this->post(route('session-replay.ingest.asset'), ['token' => $this->token($user), 'hash' => hash('sha256', $sheet), 'content' => $sheet])->assertCreated();
    }

    $this->actingAs($user);

    // From before the references were stored: by hash, as it was.
    $this->get(route('session-replay.asset', [$session, hash('sha256', $other)]))->assertOk();

    $this->ingest(['token' => $this->token($user), 'session' => $session->id, 'seq' => 1, 'meta' => ['assets' => [hash('sha256', $css)]]])->assertOk();

    $this->get(route('session-replay.asset', [$session, hash('sha256', $css)]))->assertOk();
    $this->get(route('session-replay.asset', [$session, hash('sha256', $other)]))->assertNotFound();
});
