<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\BatchIngester;
use Packstub\SessionReplay\Support\ContextToken;
use Packstub\SessionReplay\Support\ReplayStorage;

/**
 * Where the recorder uploads. Identity comes from the signed token the page
 * was rendered with (see ContextToken), never from the request's session.
 */
class IngestController
{
    /** A batch may inflate to at most this many times ingest.max_batch_kb. */
    protected const INFLATE_FACTOR = 40;

    /** A stylesheet may inflate to at most this many times ingest.max_asset_kb. */
    protected const ASSET_INFLATE_FACTOR = 8;

    public function __construct(protected ReplayStorage $storage, protected BatchIngester $ingester) {}

    /** POST {path}/ingest — multipart: token, session, seq, meta (JSON), events (file; gzip or JSON). */
    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $sessionId = (string) $request->input('session');
        $seq = $request->input('seq');

        if (! Str::isUuid($sessionId) || ! is_numeric($seq) || (int) $seq < 0 || (int) $seq > 100000) {
            return $this->refuse(422, 'A session id and a sequence number are required.');
        }

        $body = $this->upload($request, 'events', (int) config('session-replay.ingest.max_batch_kb', 1536));

        if ($body instanceof JsonResponse) {
            return $body;
        }

        /** @var ReplaySession|null $session */
        $session = ReplaySession::query()->find($sessionId);

        if ($this->overDailyLimit($context, strlen($body))) {
            return $this->refuse(429, 'The daily upload limit was reached.', stop: true);
        }

        if ($session !== null && (! $context->sameUserAs($session->user_type, $session->user_id) || ! $context->sameImpersonatorAs($session->impersonator_id))) {
            return $this->refuse(403, 'This recording belongs to someone else.', stop: true);
        }

        // The recorder starts a new recording when the workspace changes; one that did not must not leak pages across.
        if ($session !== null && ! $context->sameTenantAs($session->tenant_type, $session->tenant_id)) {
            return $this->refuse(403, 'This recording belongs to another workspace.', stop: true);
        }

        $limit = (int) config('session-replay.ingest.max_session_mb', 50) * 1024 * 1024;

        if ($session !== null && $session->bytes + strlen($body) > $limit) {
            $session->forceFill(['truncated' => true])->save();

            return $this->refuse(413, 'The recording reached its size limit.', stop: true);
        }

        $gzip = $this->storage->toGzip($body, (int) config('session-replay.ingest.max_batch_kb', 1536) * 1024 * self::INFLATE_FACTOR);

        if ($gzip === null) {
            return $this->refuse(422, 'The events could not be read.');
        }

        $meta = json_decode((string) $request->input('meta', '{}'), true);

        $result = $this->ingester->ingest($sessionId, (int) $seq, $gzip, is_array($meta) ? $meta : [], $context, $request->userAgent());

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'],
            'missing_assets' => $result['missing_assets'],
        ], $result['created'] ? 201 : 200);
    }

    /** POST {path}/ingest/asset — multipart: token, hash (SHA-256 of the stylesheet), content (file; gzip or text). */
    public function asset(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $hash = (string) $request->input('hash');

        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return $this->refuse(422, 'A SHA-256 hash is required.');
        }

        // Only stylesheets answer "duplicate"; a shared snapshot's hash is checked like an unknown one.
        if (ReplayAsset::query()->where('hash', $hash)->where('kind', ReplayAsset::STYLESHEET)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $maxKb = (int) config('session-replay.ingest.max_asset_kb', 1536);
        $body = $this->upload($request, 'content', $maxKb);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->overDailyLimit($context, strlen($body))) {
            return $this->refuse(429, 'The daily upload limit was reached.', stop: true);
        }

        $raw = str_starts_with($body, "\x1f\x8b") ? $this->storage->inflate($body, $maxKb * 1024 * self::ASSET_INFLATE_FACTOR) : $body;

        // The name is the content: nobody can store a stylesheet under a hash another recording points at.
        if ($raw === null || ! hash_equals($hash, hash('sha256', $raw))) {
            return $this->refuse(422, 'The content does not match the hash.');
        }

        // Stored already as a shared snapshot: same bytes, and the answer must not tell (see snapshot()).
        if (ReplayAsset::query()->where('hash', $hash)->exists()) {
            return response()->json(['ok' => true], 201);
        }

        $gzip = str_starts_with($body, "\x1f\x8b") ? $body : (string) gzencode($raw, 6);
        $bytes = $this->storage->putAsset($hash, $gzip);

        ReplayAsset::query()->firstOrCreate(['hash' => $hash], [
            'path' => $this->storage->assetPath($hash),
            'bytes' => $bytes,
            'raw_bytes' => strlen($raw),
            'last_seen_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    /**
     * POST {path}/ingest/snapshot — multipart: token, hash (SHA-256 of the page's node tree as JSON), content (file; gzip or JSON).
     *
     * The answer never says whether the hash was stored before: the content is
     * checked against the hash and counted against the daily limit either way,
     * so nobody can ask the server whether someone saw a page they can guess.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $hash = (string) $request->input('hash');

        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return $this->refuse(422, 'A SHA-256 hash is required.');
        }

        // A snapshot used to travel inside a batch, so it gets a batch's limits.
        $maxKb = (int) config('session-replay.ingest.max_batch_kb', 1536);
        $body = $this->upload($request, 'content', $maxKb);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->overDailyLimit($context, strlen($body))) {
            return $this->refuse(429, 'The daily upload limit was reached.', stop: true);
        }

        $gzipped = str_starts_with($body, "\x1f\x8b");
        $ceiling = $maxKb * 1024 * self::INFLATE_FACTOR;
        $raw = $gzipped ? $this->storage->inflate($body, $ceiling) : (strlen($body) > $ceiling ? null : $body);

        if ($raw === null || ! hash_equals($hash, hash('sha256', $raw))) {
            return $this->refuse(422, 'The content does not match the hash.');
        }

        /** @var ReplayAsset|null $asset */
        $asset = ReplayAsset::query()->where('hash', $hash)->first();

        if ($asset !== null) {
            // Sent again: a recording is about to point at it, so the next prune must leave it. Moved at most once a
            // day, like a stylesheet's, so a page everyone opens does not write a row on every view.
            if ($asset->last_seen_at === null || $asset->last_seen_at->lt(now()->subDay())) {
                $asset->forceFill(['last_seen_at' => now()])->save();
            }
        } else {
            $bytes = $this->storage->putSnapshot($hash, $gzipped ? $body : (string) gzencode($raw, 6));

            ReplayAsset::query()->firstOrCreate(['hash' => $hash], [
                'kind' => ReplayAsset::SNAPSHOT,
                'path' => $this->storage->snapshotPath($hash),
                'bytes' => $bytes,
                'raw_bytes' => strlen($raw),
                'last_seen_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    protected function context(Request $request): ContextToken|JsonResponse
    {
        if (! SessionReplay::enabled()) {
            return $this->refuse(403, 'Recording is turned off.', stop: true);
        }

        $context = ContextToken::decode($request->input('token'));

        if ($context === null) {
            return $this->refuse(401, 'The recording token is missing, invalid or expired.', stop: true);
        }

        if ($context->isVisitor() && ! config('session-replay.guests', false)) {
            return $this->refuse(403, 'Guests are not recorded.', stop: true);
        }

        return $context;
    }

    /** Counts the upload against the person's daily allowance (all guests share one) and says whether it is spent. */
    protected function overDailyLimit(ContextToken $context, int $bytes): bool
    {
        $megabytes = $context->isVisitor() ? config('session-replay.ingest.guest_daily_mb') : config('session-replay.ingest.daily_mb');

        if (! $megabytes) {
            return false;
        }

        $key = 'session-replay|bytes|'.now()->format('Y-m-d').'|'.($context->isVisitor() ? 'guests' : $context->throttleKey());

        Cache::add($key, 0, now()->addDay());

        return (int) Cache::increment($key, $bytes) > (int) $megabytes * 1024 * 1024;
    }

    protected function upload(Request $request, string $field, int $maxKb): string|JsonResponse
    {
        $file = $request->file($field);
        $body = $file !== null && $file->isValid() ? (string) $file->getContent() : (string) $request->input($field, '');

        if ($body === '') {
            return $this->refuse(422, "Nothing was uploaded as \"{$field}\".");
        }

        if (strlen($body) > $maxKb * 1024) {
            return $this->refuse(413, 'The upload is too large.');
        }

        return $body;
    }

    /** "stop" tells the recorder to give up for this page instead of retrying. */
    protected function refuse(int $status, string $message, bool $stop = false): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message, 'stop' => $stop], $status);
    }
}
