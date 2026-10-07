<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Support\ContextToken;

function recorderConfig(string $html): array
{
    preg_match('/window\.__sessionReplay=(\{.*?\});<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

it('tells the browser when the person changed, so a tab that signs in starts a new recording', function () {
    config(['session-replay.guests' => true]);

    $guest = recorderConfig($this->get('page')->getContent());
    $first = recorderConfig($this->actingAs($this->user())->get('page')->getContent());
    $again = recorderConfig($this->get('page')->getContent());
    $second = recorderConfig($this->actingAs($this->user())->get('page')->getContent());

    expect($guest['identity'])->toBeNull()
        ->and($first['identity'])->toBeString()->toBe($again['identity'])
        ->and($second['identity'])->toBeString()->not->toBe($first['identity']);
});

it('tells the browser when the workspace or the impersonator changed, too', function () {
    $this->actingAs($this->user());

    $identity = fn (array $options): ?string => recorderConfig((string) SessionReplay::recorder($options))['identity'];
    $acme = $this->team('Acme');

    expect($identity(['tenant' => $acme]))->toBe($identity(['tenant' => $acme]))
        ->not->toBe($identity([]))
        ->not->toBe($identity(['tenant' => $this->team('Globex')]))
        ->not->toBe($identity(['tenant' => $acme, 'impersonator' => 7]));
});

it('renders the recorder for a signed-in person, with a token that names them', function () {
    $user = $this->user();

    $html = $this->actingAs($user)->get('page')->assertOk()->getContent();
    $config = recorderConfig($html);
    $token = ContextToken::decode($config['token']);

    expect($html)->toContain('scripts/recorder.js?v=')
        ->and($config['ingestUrl'])->toBe(route('session-replay.ingest'))
        ->and($config['privacy']['maskAllInputs'])->toBeTrue()
        ->and($config['size']['stripAttributes'])->toContain('wire:*')
        ->and($config['cookie'])->toBe('session_replay_id')
        ->and($token->userId)->toBe((string) $user->id)
        ->and($token->userType)->toBe($user->getMorphClass())
        ->and($token->tenantId)->toBeNull();
});

it('runs the config on every wire:navigate page and the bundle once per document', function () {
    $html = $this->actingAs($this->user())->get('page')->assertOk()->getContent();

    expect($html)->toMatch('/<script src="[^"]*recorder\.js[^"]*" defer data-navigate-once/')
        ->and($html)->toMatch('/<script>window\.__sessionReplay=/');
});

it('renders nothing for guests, excluded paths, a false recordWhen or the master switch', function () {
    $this->get('page')->assertOk()->assertDontSee('__sessionReplay', false);

    config()->set('session-replay.guests', true);
    $this->get('page')->assertSee('__sessionReplay', false);

    config()->set('session-replay.except', ['admin/secrets*']);
    $this->actingAs($staff = $this->user(['is_staff' => true]))->get('admin/secrets/keys')->assertDontSee('__sessionReplay', false);

    SessionReplay::recordWhen(fn ($user, $request) => ! $user?->is_staff);
    $this->actingAs($staff)->get('page')->assertDontSee('__sessionReplay', false);
    $this->actingAs($this->user())->get('page')->assertSee('__sessionReplay', false);

    config()->set('session-replay.enabled', false);
    $this->actingAs($this->user())->get('page')->assertDontSee('__sessionReplay', false);
});

it('never records its own pages', function () {
    config()->set('session-replay.guests', true);

    $request = Request::create('/session-replay/'.Str::uuid());

    expect(SessionReplay::shouldRecord($request))->toBeFalse()
        ->and(SessionReplay::shouldRecord(Request::create('/orders')))->toBeTrue();
});

it('signs the workspace, the impersonator and the app\'s properties into the token', function () {
    $user = $this->user();
    $team = $this->team();

    SessionReplay::tenantUsing(fn () => $team)
        ->impersonatorUsing(fn () => 42)
        ->propertiesUsing(fn () => ['plan' => 'pro']);

    $this->actingAs($user);

    $token = ContextToken::decode(recorderConfig((string) SessionReplay::recorder(['properties' => ['panel' => 'admin']]))['token']);

    expect($token->tenantType)->toBe($team->getMorphClass())
        ->and($token->tenantId)->toBe((string) $team->id)
        ->and($token->impersonatorId)->toBe('42')
        ->and($token->properties)->toBe(['plan' => 'pro', 'panel' => 'admin']);
});

it('takes the person and the workspace from the caller when a panel knows better', function () {
    $other = $this->user();
    $team = $this->team('Globex');

    // Nobody on the default guard; the panel's guard has someone.
    $token = ContextToken::decode(recorderConfig((string) SessionReplay::recorder(['user' => $other, 'tenant' => $team]))['token']);

    expect($token->userId)->toBe((string) $other->id)->and($token->tenantId)->toBe((string) $team->id);
});

it('passes the app\'s privacy, consent and size settings to the browser', function () {
    config()->set('session-replay.consent', 'opt-in');
    config()->set('session-replay.privacy.mask_all_text', true);
    config()->set('session-replay.capture.console', []);
    config()->set('session-replay.context.enabled', false);
    config()->set('session-replay.sample_rate', 0.25);

    $this->actingAs($this->user());

    $config = recorderConfig((string) SessionReplay::recorder());

    expect($config['consent'])->toBe('opt-in')
        ->and($config['privacy']['maskAllText'])->toBeTrue()
        ->and($config['capture']['console'])->toBe([])
        ->and($config['cookie'])->toBeNull()
        ->and($config['sampleRate'])->toBe(0.25);
});

it('leaves pages with a password-reset or verification link in their URL out by default', function () {
    $this->actingAs($this->user());

    $this->get('admin/password-reset/reset?email=ada@example.com&token=abc&signature=123')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('reset-password/abc123')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('page')->assertSee('__sessionReplay', false);
});

it('leaves pages out by route name, whatever their URL', function () {
    $this->actingAs($this->user());

    // password.reset is in the default list; the path matches none of the default path patterns.
    $this->get('account/new-password')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('admin/support/secrets')->assertOk()->assertSee('__sessionReplay', false);

    config()->set('session-replay.except_routes', ['support.*']);

    $this->get('admin/support/secrets')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('account/new-password')->assertOk()->assertSee('__sessionReplay', false);

    config()->set('session-replay.except_routes', []);

    $this->get('account/new-password')->assertOk()->assertSee('__sessionReplay', false);
});

it('reads keyed entries in the except lists like plain ones', function () {
    $this->actingAs($this->user());

    config()->set('session-replay.except', ['secrets' => 'admin/secrets*', 'reset-password/*']);
    config()->set('session-replay.except_routes', ['support' => 'support.*', 'password.*']);

    $this->get('admin/secrets/keys')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('admin/support/secrets')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('account/new-password')->assertOk()->assertDontSee('__sessionReplay', false);
    $this->get('page')->assertSee('__sessionReplay', false);
});

it('masks rich editors and redacts secret query parameters by default', function () {
    $this->actingAs($this->user());

    $privacy = recorderConfig((string) SessionReplay::recorder())['privacy'];

    expect($privacy['maskTextSelector'])->toContain('[contenteditable]')
        ->and($privacy['redactQuery'])->toContain('token', 'signature', 'code', 'state', 'email');
});

it('records signed-in people without naming them when privacy.anonymous is on', function () {
    config()->set('session-replay.privacy.anonymous', true);

    $team = $this->team();
    SessionReplay::tenantUsing(fn () => $team)->impersonatorUsing(fn () => 42);

    $ada = $this->user();
    $grace = $this->user();

    $config = recorderConfig((string) $this->actingAs($ada)->get('page')->getContent());
    $token = ContextToken::decode($config['token']);

    // Recorded although guests are off, with the workspace but without the person or the impersonator.
    expect($token->userId)->toBeNull()
        ->and($token->userType)->toBeNull()
        ->and($token->impersonatorId)->toBeNull()
        ->and($token->tenantId)->toBe((string) $team->id)
        ->and($token->pseudonym)->toHaveLength(32);

    // Another person is another recording, though neither is named.
    $other = recorderConfig((string) $this->actingAs($grace)->get('page')->getContent());

    expect($config['identity'])->not->toBeNull()
        ->and($other['identity'])->not->toBe($config['identity'])
        ->and(ContextToken::decode($other['token'])->pseudonym)->not->toBe($token->pseudonym);

    // Guests are still left out unless the app turns them on.
    auth()->logout();
    $this->get('page')->assertDontSee('__sessionReplay', false);
});

it('escapes what goes into the script tag', function () {
    SessionReplay::propertiesUsing(fn () => ['note' => '</script><script>alert(1)</script>']);

    $this->actingAs($this->user());

    expect((string) SessionReplay::recorder())->not->toContain('</script><script>alert');
});

it('compiles the Blade directive with and without options', function () {
    expect(Blade::compileString('@sessionReplay'))->toContain('->recorder([])')
        ->and(Blade::compileString("@sessionReplay(['nonce' => \$nonce])"))->toContain("->recorder(['nonce' => \$nonce])");
});

it('keeps mode "session" by default, with nothing about errors for the browser', function () {
    $config = recorderConfig($this->actingAs($this->user())->get('page')->getContent());

    expect($config['mode'])->toBe('session')
        ->and($config['onError'])->toBeNull()
        ->and((require __DIR__.'/../../config/session-replay.php')['mode'])->toBe('session');
});

it('tells the browser the window and the triggers in mode "on_error"', function () {
    config()->set('session-replay.mode', 'on_error');
    config()->set('session-replay.on_error.triggers', ['error', 'console', 'navigation', 'custom']);

    $config = recorderConfig($this->actingAs($this->user())->get('page')->getContent());

    expect($config['mode'])->toBe('on_error')
        ->and($config['onError']['bufferMs'])->toBe(60_000)
        ->and($config['onError']['triggers'])->toBe(['error', 'console', 'custom'])
        ->and($config['onError']['ask'])->toBeFalse()
        ->and($config['onError']['keepPending'])->toBeTrue()
        ->and($config['onError']['labels'])->toBeNull();

    config()->set('session-replay.on_error.keep_pending', false);
    expect(recorderConfig((string) SessionReplay::recorder())['onError']['keepPending'])->toBeFalse();

    config()->set('session-replay.on_error.buffer_seconds', 5000);
    expect(recorderConfig((string) SessionReplay::recorder())['onError']['bufferMs'])->toBe(600_000);

    config()->set('session-replay.on_error.buffer_seconds', 1);
    expect(recorderConfig((string) SessionReplay::recorder())['onError']['bufferMs'])->toBe(5_000);

    config()->set('session-replay.mode', 'something else');
    expect(recorderConfig((string) SessionReplay::recorder())['mode'])->toBe('session');
});

it('hands the browser the question in the app\'s language, with the anonymous choice only for a named person', function () {
    config()->set('session-replay.mode', 'on_error');
    config()->set('session-replay.on_error.ask', true);
    config()->set('session-replay.guests', true);

    app()->setLocale('de');

    $signedIn = recorderConfig($this->actingAs($this->user())->get('page')->getContent())['onError'];

    expect($signedIn['ask'])->toBeTrue()
        ->and($signedIn['offerAnonymous'])->toBeTrue()
        ->and($signedIn['labels']['share'])->toBe('Aufzeichnung senden')
        ->and(array_keys($signedIn['labels']))->toBe(['title', 'body', 'anonymous', 'share', 'decline', 'failed', 'sent']);

    config()->set('session-replay.privacy.anonymous', true);
    expect(recorderConfig((string) SessionReplay::recorder())['onError']['offerAnonymous'])->toBeFalse();

    config()->set('session-replay.privacy.anonymous', false);
    expect(recorderConfig((string) SessionReplay::recorder(['user' => null]))['onError']['offerAnonymous'])->toBeFalse();
});

it('tells the browser whether the question replaces Livewire\'s error modal', function () {
    config()->set('session-replay.mode', 'on_error');
    config()->set('session-replay.on_error.ask', true);
    $this->actingAs($this->user());

    $replaces = fn (): bool => recorderConfig((string) SessionReplay::recorder())['onError']['replaceLivewireModal'];

    expect(recorderConfig((string) SessionReplay::recorder())['appName'])->toBe(config('app.name'));

    // The default replaces it in production only.
    config()->set('app.debug', false);
    expect((require __DIR__.'/../../config/session-replay.php')['on_error']['livewire_error_modal'])->toBe('production')
        ->and($replaces())->toBeTrue();

    config()->set('app.debug', true);
    expect($replaces())->toBeFalse();

    config()->set('session-replay.on_error.livewire_error_modal', 'replace');
    expect($replaces())->toBeTrue();

    config()->set('session-replay.on_error.livewire_error_modal', 'keep');
    config()->set('app.debug', false);
    expect($replaces())->toBeFalse();

    // Without the question there is nothing to put in the modal's place.
    config()->set('session-replay.on_error.livewire_error_modal', 'replace');
    config()->set('session-replay.on_error.ask', false);
    expect($replaces())->toBeFalse();
});
