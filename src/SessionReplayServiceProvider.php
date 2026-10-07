<?php

namespace Packstub\SessionReplay;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Packstub\SessionReplay\Commands\PruneCommand;
use Packstub\SessionReplay\Http\Controllers\IngestController;
use Packstub\SessionReplay\Http\Controllers\ReplayDataController;
use Packstub\SessionReplay\Http\Controllers\ScriptController;
use Packstub\SessionReplay\Http\Controllers\ViewerController;
use Packstub\SessionReplay\Http\Middleware\AddReplayContext;
use Packstub\SessionReplay\Http\Middleware\AuthorizeViewer;
use Packstub\SessionReplay\Http\Middleware\ThrottleIngest;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Support\ReplayStorage;
use Packstub\SessionReplay\Support\ServerErrors;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class SessionReplayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('session-replay')
            ->hasConfigFile()
            ->hasViews('session-replay')
            ->hasTranslations()
            ->discoversMigrations()
            // Auto-run by default; database-per-tenant apps set run_migrations=false, publish and run them centrally.
            ->runsMigrations((bool) config('session-replay.run_migrations', true))
            ->hasCommand(PruneCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $command->info('Installing Session Replay…'))
                    ->publishConfigFile()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $this->publishProviderStub($command);

                        $command->info('Next: put @sessionReplay before </body> in the layouts you want recorded,');
                        $command->line('decide who may watch in app/Providers/SessionReplayServiceProvider.php (until then only the local environment can),');
                        $command->line('and schedule the clean-up: Schedule::command(\'session-replay:prune\')->daily();');
                    });
            });
    }

    /** Deep-merge the package's config defaults under any user-published values (mergeConfigFrom is top-level only). */
    public function packageRegistered(): void
    {
        $defaults = require __DIR__.'/../config/session-replay.php';
        $published = (array) config('session-replay', []);

        config()->set('session-replay', array_replace_recursive($defaults, $published));

        // A list the app set replaces the default list; merged index by index, [] could never turn anything off.
        foreach (['except', 'capture.console', 'size.strip_attributes', 'size.keep_attributes', 'snapshots.share_routes', 'snapshots.share_paths', 'snapshots.volatile_ids', 'ingest.middleware', 'viewer.middleware'] as $list) {
            if (Arr::has($published, $list)) {
                config()->set('session-replay.'.$list, Arr::get($published, $list));
            }
        }

        $this->app->singleton(SessionReplayManager::class);
        $this->app->singleton(ReplayStorage::class);
        // Per request: which recording a request belongs to must not leak into the next one (Octane, queue workers).
        $this->app->scoped(ServerErrors::class);
    }

    public function packageBooted(): void
    {
        Blade::directive('sessionReplay', fn (string $expression): string => '<?php echo app(\\'.SessionReplayManager::class.'::class)->recorder('.($expression === '' ? '[]' : $expression).'); ?>');
        Blade::directive('sessionReplayPlayerAssets', fn (): string => '<?php echo app(\\'.SessionReplayManager::class.'::class)->playerAssets(); ?>');

        if (config('session-replay.context.enabled', true)) {
            // Global, ahead of cookie decryption: the recorder writes its cookie in the clear.
            $this->app->make(Kernel::class)->prependMiddleware(AddReplayContext::class);
        }

        RateLimiter::for('session-replay', function (Request $request) {
            $perMinute = config('session-replay.ingest.throttle');

            if (! $perMinute) {
                return Limit::none();
            }

            // Per person when the token names one, per rendered page for guests (a value the client cannot choose);
            // never per IP (an edge proxy hides it). A request without a valid token is refused right after.
            $key = ContextToken::decode($request->input('token'))?->throttleKey() ?? 'invalid';

            return Limit::perMinute((int) $perMinute)->by('session-replay|'.$key);
        });

        // Exceptions Laravel reports during a recorded request go on its timeline. Returning nothing keeps the app's
        // own reporting going.
        $this->callAfterResolving(ExceptionHandler::class, function (mixed $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(ServerErrors::class)->report($exception);
                });
            }
        });

        $this->app->booted(fn () => $this->registerRoutes());
    }

    protected function registerRoutes(): void
    {
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        Route::group(array_filter([
            'prefix' => trim((string) config('session-replay.path', 'session-replay'), '/'),
            'domain' => config('session-replay.domain'),
            'as' => 'session-replay.',
        ]), function () use ($uuid): void {
            Route::get('scripts/{file}', ScriptController::class)->where('file', '[a-z.]+')->name('script');

            Route::middleware([...(array) config('session-replay.ingest.middleware', []), ThrottleIngest::class.':session-replay'])->group(function (): void {
                Route::post('ingest', [IngestController::class, 'store'])->name('ingest');
                Route::post('ingest/asset', [IngestController::class, 'asset'])->name('ingest.asset');
                Route::post('ingest/snapshot', [IngestController::class, 'snapshot'])->name('ingest.snapshot');
            });

            Route::middleware([...(array) config('session-replay.viewer.middleware', ['web', 'auth']), AuthorizeViewer::class])->group(function () use ($uuid): void {
                // The data routes stay when the pages are turned off: the player component needs them.
                Route::get('{session}/manifest', [ReplayDataController::class, 'manifest'])->where('session', $uuid)->name('manifest');
                Route::get('{session}/chunks/{seq}', [ReplayDataController::class, 'chunk'])->where('session', $uuid)->whereNumber('seq')->name('chunk');
                Route::get('{session}/assets/{hash}', [ReplayDataController::class, 'asset'])->where('session', $uuid)->where('hash', '[a-f0-9]{64}|__hash__')->name('asset');
                Route::get('{session}/snapshots/{hash}', [ReplayDataController::class, 'snapshot'])->where('session', $uuid)->where('hash', '[a-f0-9]{64}|__hash__')->name('snapshot');

                if (config('session-replay.viewer.enabled', true)) {
                    Route::get('/', [ViewerController::class, 'index'])->name('index');
                    Route::get('{session}', [ViewerController::class, 'show'])->where('session', $uuid)->name('show');
                }
            });
        });
    }

    /** app/Providers/SessionReplayServiceProvider.php with the gate in it, the way Telescope and Horizon do. */
    protected function publishProviderStub(InstallCommand $command): void
    {
        $target = app_path('Providers/SessionReplayServiceProvider.php');

        if (file_exists($target)) {
            return;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $namespace = rtrim($this->app->getNamespace(), '\\');

        file_put_contents($target, str_replace('{{ namespace }}', $namespace, (string) file_get_contents(__DIR__.'/../stubs/SessionReplayServiceProvider.stub')));

        $command->info('Published app/Providers/SessionReplayServiceProvider.php');

        if (file_exists($providers = $this->app->bootstrapPath('providers.php'))) {
            ServiceProvider::addProviderToBootstrapFile($namespace.'\\Providers\\SessionReplayServiceProvider', $providers);
        }
    }
}
