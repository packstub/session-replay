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

        $lifetime = $config === null ? null : $this->lifetime($config);

        // expires_in is what the package schedules its renewal by: seconds need no clock in common with the browser's.
        $body = $config === null
            ? ['record' => false]
            : ['record' => true, 'config' => $config, 'expires_at' => $lifetime === null ? null : now()->addSeconds($lifetime)->toIso8601String(), 'expires_in' => $lifetime];

        return response()->json($body, 200, [
            'Cache-Control' => 'no-store, private',
            'Vary' => 'Origin, Cookie, Authorization',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * For how many seconds the token is taken (ingest.token_days, as ContextToken::isExpired() counts them); null
     * for a token that names nobody while ingest.guest_tokens_expire is off (identity null is the directive's word
     * for such a token).
     *
     * @param  array<string, mixed>  $config
     */
    private function lifetime(array $config): ?int
    {
        if ($config['identity'] === null && ! config('session-replay.ingest.guest_tokens_expire', true)) {
            return null;
        }

        return max(1, (int) config('session-replay.ingest.token_days', ContextToken::DEFAULT_DAYS)) * 24 * 60 * 60;
    }
}
