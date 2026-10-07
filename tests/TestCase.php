<?php

namespace Packstub\SessionReplay\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\SessionReplayServiceProvider;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Tests\Fixtures\Models\Team;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/** The package in a plain Laravel app: no Filament, no Livewire. */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('replays');
    }

    protected function getPackageProviders($app): array
    {
        return [SessionReplayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('session-replay.storage.disk', 'replays');
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function defineRoutes($router): void
    {
        $router->get('login', fn () => 'login')->name('login');
        $router->middleware('web')->get('page', fn () => view('page'));
        $router->middleware('web')->get('admin/secrets/keys', fn () => view('page'));
        $router->middleware('web')->get('admin/password-reset/reset', fn () => view('page'));
        $router->middleware('web')->get('reset-password/{token}', fn () => view('page'));
        $router->middleware('web')->get('account/new-password', fn () => view('page'))->name('password.reset');
        $router->middleware('web')->get('admin/support/secrets', fn () => view('page'))->name('support.secrets');
    }

    protected function defineWebRoutes($router): void {}

    protected function user(array $attributes = []): User
    {
        return User::query()->create($attributes + ['name' => 'Ada Lovelace', 'email' => uniqid().'@example.com', 'password' => 'secret']);
    }

    protected function team(string $name = 'Acme'): Team
    {
        return Team::query()->create(['name' => $name]);
    }

    protected function token(?User $user = null, ?Team $team = null, ?string $impersonator = null, array $properties = []): string
    {
        return ContextToken::for($user, $team, $impersonator, $properties)->encode();
    }

    /** @return array<int, array<string, mixed>> */
    protected function events(int $count = 3, ?int $from = null): array
    {
        $from ??= now()->getTimestampMs();

        return array_map(fn (int $i): array => ['type' => 3, 'timestamp' => $from + $i * 100, 'data' => ['source' => 1]], range(0, $count - 1));
    }

    /**
     * Upload a batch the way the recorder does.
     *
     * @param  array<string, mixed>  $overrides  token, session, seq, meta, events (array|string), gzip (bool)
     */
    protected function ingest(array $overrides = []): TestResponse
    {
        $events = $overrides['events'] ?? $this->events();
        $body = is_string($events) ? $events : json_encode($events);

        if ($overrides['gzip'] ?? true) {
            $body = gzencode($body);
        }

        $now = now()->getTimestampMs();

        return $this->post(route('session-replay.ingest'), [
            'token' => $overrides['token'] ?? $this->token($this->user()),
            'session' => $overrides['session'] ?? (string) Str::uuid(),
            'seq' => $overrides['seq'] ?? 0,
            'meta' => json_encode(($overrides['meta'] ?? []) + [
                'url' => 'https://app.test/orders',
                'viewport' => ['width' => 1440, 'height' => 900],
                'from' => $now - 1000,
                'to' => $now,
                'events' => is_array($events) ? count($events) : 0,
                'activeMs' => 800,
                'markers' => [],
                'assets' => [],
            ]),
            'events' => UploadedFile::fake()->createWithContent('events', $body),
        ] + array_intersect_key($overrides, ['anonymous' => true]), ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36']);
    }

    protected function recording(?User $user = null, array $attributes = []): ReplaySession
    {
        $id = (string) Str::uuid();

        $this->ingest(['token' => $this->token($user ?? $this->user()), 'session' => $id])->assertCreated();

        $session = ReplaySession::query()->findOrFail($id);

        if ($attributes !== []) {
            $session->forceFill($attributes)->save();
        }

        return $session;
    }
}
