<?php

declare(strict_types=1);

namespace Packstub\SessionReplay\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/**
 * A frontend that signs in with a bearer token instead of the session cookie (ingest.token_middleware with a
 * stateless guard, as auth:sanctum is): a request guard that takes "Bearer user-<id>" stands in for Sanctum. The
 * routes register at boot, so this happens before it.
 */
abstract class StandaloneBearerTestCase extends StandaloneTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.guards.bearer', ['driver' => 'bearer']);
        $app['config']->set('session-replay.ingest.token_middleware', ['auth:bearer']);

        Auth::viaRequest('bearer', function (Request $request): ?User {
            $token = (string) $request->bearerToken();

            return str_starts_with($token, 'user-') ? User::query()->find(substr($token, 5)) : null;
        });
    }
}
