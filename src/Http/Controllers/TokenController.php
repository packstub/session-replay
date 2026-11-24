<?php

declare(strict_types=1);

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packstub\SessionReplay\SessionReplayManager;
use Packstub\SessionReplay\Support\ContextToken;

/**
 * GET {path}/token (ingest.token_endpoint): what @sessionReplay puts in a page, for a frontend served apart from the
 * app (the npm package @packstub/session-replay). It runs behind ingest.token_middleware, the app's own sign-in, and
 * decides like the directive: the master switch, guests, recordWhen() and the resolvers. The token it hands out is
 * the one the directive would sign, so the upload routes need nothing new.
 *
 * The answer is the signed-in person's: never cached, and it varies with whatever signs them in.
 *
 * @internal Wired by the package; not part of the public API.
 */
final class TokenController
{
    public function __invoke(Request $request, SessionReplayManager $replay): JsonResponse
    {
        $config = $replay->recorderConfig([], $request, page: false);

        $body = $config === null
            ? ['record' => false]
            : ['record' => true, 'config' => $config, 'expires_at' => $this->expiresAt($config)];

        return response()->json($body, 200, [
            'Cache-Control' => 'no-store, private',
            'Vary' => 'Origin, Cookie, Authorization',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * When the token stops being taken (ingest.token_days), as ISO 8601; null for a token that names nobody while
     * ingest.guest_tokens_expire is off (identity null is the directive's word for such a token).
     *
     * @param  array<string, mixed>  $config
     */
    private function expiresAt(array $config): ?string
    {
        if ($config['identity'] === null && ! config('session-replay.ingest.guest_tokens_expire', true)) {
            return null;
        }

        $days = max(1, (int) config('session-replay.ingest.token_days', ContextToken::DEFAULT_DAYS));

        return now()->addDays($days)->toIso8601String();
    }
}
