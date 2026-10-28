<?php

namespace Packstub\SessionReplay\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;

/**
 * What the recorder's requests (ingest, assist) authenticate with: the signed token the page was rendered with, and
 * for a recording its write key. No session, cookie or CSRF token.
 *
 * @internal
 */
trait ReadsRecorderToken
{
    protected function context(Request $request): ContextToken|JsonResponse
    {
        if (! SessionReplay::enabled()) {
            return $this->refuse(403, 'Recording is turned off.', stop: true);
        }

        $context = ContextToken::decode($this->fromBody($request, 'token'));

        if ($context === null) {
            return $this->refuse(401, 'The recording token is missing, invalid or expired.', stop: true);
        }

        if ($context->isVisitor() && ! config('session-replay.guests', false)) {
            return $this->refuse(403, 'Guests are not recorded.', stop: true);
        }

        return $context;
    }

    /** The recording's write key as the browser sends it (32 hex characters; anything up to 128 is taken), or none. */
    protected function writeKey(Request $request): ?string
    {
        $key = $this->fromBody($request, 'key');

        return is_string($key) && $key !== '' && strlen($key) <= 128 ? $key : null;
    }

    /**
     * Whether the browser presenting this token and key makes this recording: the checks a batch gets (the write
     * key, the person, the impersonator, the workspace).
     */
    protected function makesRecording(ReplaySession $session, ContextToken $context, ?string $key): bool
    {
        return $session->acceptsWriteKey($key)
            && $context->sameUserAs($session->user_type, $session->user_id)
            && $context->sameImpersonatorAs($session->impersonator_id, $session->impersonator_type)
            && $context->sameTenantAs($session->tenant_type, $session->tenant_id);
    }

    /**
     * A credential from the request body only (JSON or a form), never the query string: a URL ends up in access logs,
     * proxies and error trackers, and with it the token, the write key or a request's secret.
     */
    protected function fromBody(Request $request, string $key): mixed
    {
        if ($request->isMethodSafe()) {
            return null;
        }

        return $request->isJson() ? $request->json($key) : ($request->request->all()[$key] ?? null);
    }

    /** "stop" tells the recorder to give up for this page instead of retrying. */
    protected function refuse(int $status, string $message, bool $stop = false): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message, 'stop' => $stop], $status);
    }
}
