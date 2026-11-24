<?php

declare(strict_types=1);

namespace Packstub\SessionReplay\Tests;

/**
 * A frontend on another origin: the token route on and two origins listed, next to entries that are no origin. The
 * routes register at boot, so this happens before it.
 */
abstract class StandaloneTestCase extends TestCase
{
    public const ORIGIN = 'https://app.example.com';

    public const DEV_ORIGIN = 'http://localhost:5173';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session-replay.ingest.token_endpoint', true);
        $app['config']->set('session-replay.ingest.origins', [self::ORIGIN, 'HTTP://LocalHost:5173/', '*', 'null', 'https://*.example.com', 'https://app.example.com/path', 42]);
    }
}
