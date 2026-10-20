<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\SessionReplayServiceProvider;

/** What the recorder is handed, from the directive's HTML. */
function rendered(string $html): array
{
    preg_match('/window\.__sessionReplay=(\{.*?\});<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

/** Read a published config/session-replay.php the way the package does when the app boots. */
function publishedConfig(array $published): void
{
    config()->set('session-replay', $published);

    (new SessionReplayServiceProvider(app()))->packageRegistered();
}

it('replaces a default list with the one a published config sets, however short or empty', function () {
    publishedConfig([
        'on_error' => ['triggers' => ['custom']],
        'privacy' => ['redact_query' => []],
        'except_routes' => [],
        'except' => ['billing/*'],
        'capture' => ['console' => ['error', 'warn']],
    ]);

    expect(config('session-replay.on_error.triggers'))->toBe(['custom'])
        ->and(config('session-replay.privacy.redact_query'))->toBe([])
        ->and(config('session-replay.except_routes'))->toBe([])
        ->and(config('session-replay.except'))->toBe(['billing/*'])
        ->and(config('session-replay.capture.console'))->toBe(['error', 'warn']);
});

it('merges a published group key by key, keeping the defaults it leaves out', function () {
    publishedConfig([
        'size' => ['sampling' => ['mousemove' => 100]],
        'privacy' => ['mask_all_text' => true],
        'snapshots' => ['share_routes' => ['pricing']],
    ]);

    expect(config('session-replay.size.sampling'))->toBe(['mousemove' => 100, 'scroll' => 150, 'media' => 800, 'input' => 'last'])
        ->and(config('session-replay.privacy.mask_all_text'))->toBeTrue()
        ->and(config('session-replay.privacy.mask_all_inputs'))->toBeTrue()
        ->and(config('session-replay.privacy.redact_query'))->toContain('token')
        ->and(config('session-replay.snapshots.share_routes'))->toBe(['pricing'])
        ->and(config('session-replay.snapshots.volatile_ids'))->toBe(['fi-dropdown-panel-*']);
});

it('reads a published config with the old key names under the new ones', function () {
    publishedConfig([
        'idle_timeout' => 45,
        'flush_interval' => 2500,
        'on_error' => ['ask_timeout' => 90],
        'ingest' => ['throttle' => 120],
    ]);

    expect(config('session-replay.idle_minutes'))->toBe(45)
        ->and(config('session-replay.flush_seconds'))->toBe(2.5)
        ->and(config('session-replay.on_error.ask_timeout_seconds'))->toBe(90)
        ->and(config('session-replay.ingest.requests_per_minute'))->toBe(120)
        ->and(config()->has('session-replay.idle_timeout'))->toBeFalse()
        ->and(config()->has('session-replay.ingest.throttle'))->toBeFalse();

    $this->actingAs($this->user());
    $config = rendered((string) SessionReplay::recorder());

    expect($config['idleTimeout'])->toBe(45 * 60 * 1000)
        ->and($config['flushInterval'])->toBe(2500);
});

it('prefers the new key name when a published config has both', function () {
    publishedConfig(['idle_timeout' => 45, 'idle_minutes' => 10, 'flush_interval' => 2500, 'flush_seconds' => 8]);

    expect(config('session-replay.idle_minutes'))->toBe(10)
        ->and(config('session-replay.flush_seconds'))->toBe(8);
});

it('reads a misspelt consent as opt-in and a misspelt mode as on_error, and says so in the log once', function () {
    Log::spy();

    $this->actingAs($this->user());

    config()->set('session-replay.consent', 'optin-please');
    config()->set('session-replay.mode', 'sessions');

    $config = rendered((string) SessionReplay::recorder());
    rendered((string) SessionReplay::recorder());

    expect($config['consent'])->toBe('opt-in')
        ->and($config['mode'])->toBe('on_error')
        ->and($config['onError'])->toBeArray();

    Log::shouldHaveReceived('warning')->with('Session Replay: consent "optin-please" is none of always, opt-in; it is read as "opt-in".')->once();
    Log::shouldHaveReceived('warning')->with('Session Replay: mode "sessions" is none of session, on_error; it is read as "on_error".')->once();
});

it('reads the usual spellings of consent and mode as what they mean', function (string $key, mixed $value, string $expected) {
    Log::spy();

    config()->set('session-replay.'.$key, $value);

    expect(SessionReplay::{$key}())->toBe($expected);

    Log::shouldNotHaveReceived('warning');
})->with([
    ['consent', 'always', 'always'],
    ['consent', 'Always', 'always'],
    ['consent', 'opt-in', 'opt-in'],
    ['consent', 'opt_in', 'opt-in'],
    ['consent', 'OptIn', 'opt-in'],
    ['consent', null, 'always'],
    ['mode', 'session', 'session'],
    ['mode', 'on_error', 'on_error'],
    ['mode', 'on-error', 'on_error'],
    ['mode', ' ON_ERROR ', 'on_error'],
    ['mode', null, 'session'],
]);

it('leaves out properties too large to travel in every page, and says so in the log', function () {
    Log::spy();

    $this->actingAs($this->user());

    SessionReplay::propertiesUsing(fn () => ['notes' => str_repeat('x', 5000)]);
    $large = rendered((string) SessionReplay::recorder());

    SessionReplay::propertiesUsing(fn () => ['plan' => 'pro']);
    $small = rendered((string) SessionReplay::recorder());

    $properties = fn (array $config): array => json_decode(base64_decode(strtr(explode('.', $config['token'])[0], '-_', '+/')), true)['p'];

    expect($properties($large))->toBe([])
        ->and($properties($small))->toBe(['plan' => 'pro']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'properties take 5012 bytes, more than the 4096'))->once();
});

it('asks viewer.guard in the viewer\'s login when one is set', function () {
    $middleware = function (): array {
        (fn () => $this->registerRoutes())->call(new SessionReplayServiceProvider(app()));
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName('session-replay.manifest')->gatherMiddleware();
    };

    expect($middleware())->toContain('auth')->not->toContain('auth:admin');

    config()->set('session-replay.viewer.guard', 'admin');

    expect($middleware())->toContain('auth:admin')->not->toContain('auth');

    // A middleware list that names its own guard is left alone.
    config()->set('session-replay.viewer.middleware', ['web', 'auth:staff']);

    expect($middleware())->toContain('auth:staff')->not->toContain('auth:admin');
});
