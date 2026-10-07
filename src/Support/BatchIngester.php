<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Events\ReplaySessionStarted;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplayChunk;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Models\ReplaySessionAsset;

/**
 * Stores one upload: the gzip file, its chunk row, the markers the browser
 * listed next to it and the recording's counters. The events are never
 * parsed here; everything the index needs arrives in the sidecar ("meta").
 */
class BatchIngester
{
    public const MAX_MARKERS_PER_BATCH = 200;

    /** The largest value of an integer column on every supported database (Postgres has no unsigned ones). */
    protected const MAX_INTEGER = 2_147_483_647;

    /** 9999-12-31 in epoch milliseconds. */
    protected const MAX_MILLISECONDS = 253_402_300_799_999;

    public function __construct(protected ReplayStorage $storage) {}

    /**
     * @param  array<string, mixed>  $meta
     * @return array{session: ReplaySession, created: bool, duplicate: bool, foreign: bool, missing_assets: array<int, string>}
     */
    public function ingest(string $sessionId, int $seq, string $gzip, array $meta, ContextToken $context, ?string $userAgent): array
    {
        [$session, $created] = $this->session($sessionId, $meta, $context, $userAgent);

        // Another first batch of this id made the row a moment ago, after the controller looked: check it the same way.
        if (! $created && ! ($context->sameUserAs($session->user_type, $session->user_id) && $context->sameImpersonatorAs($session->impersonator_id) && $context->sameTenantAs($session->tenant_type, $session->tenant_id))) {
            return ['session' => $session, 'created' => false, 'duplicate' => false, 'foreign' => true, 'missing_assets' => []];
        }

        if (ReplayChunk::query()->where('replay_session_id', $sessionId)->where('seq', $seq)->exists()) {
            // A retry of an upload that did arrive: keep the first copy and say so.
            return ['session' => $session, 'created' => false, 'duplicate' => true, 'foreign' => false, 'missing_assets' => []];
        }

        $bytes = $this->storage->putChunk($sessionId, $seq, $gzip);
        $from = $this->milliseconds(Arr::get($meta, 'from')) ?? now()->getTimestampMs();
        $to = max($from, $this->milliseconds(Arr::get($meta, 'to')) ?? $from);
        $events = $this->clamp(Arr::get($meta, 'events'), self::MAX_INTEGER);
        $markers = $this->markers($sessionId, (array) Arr::get($meta, 'markers', []));

        // The chunk row, its markers, the counters and the snapshot references land together or not at all, so a
        // retry after a failure stores the batch again instead of finding a chunk row and answering "duplicate".
        try {
            $this->connection()->transaction(function () use ($session, $sessionId, $seq, $meta, $bytes, $from, $to, $events, $markers): void {
                ReplayChunk::query()->create([
                    'replay_session_id' => $sessionId,
                    'seq' => $seq,
                    'path' => $this->storage->chunkPath($sessionId, $seq),
                    'bytes' => $bytes,
                    'event_count' => $events,
                    'from_ms' => $from,
                    'to_ms' => $to,
                ]);

                if ($markers !== []) {
                    ReplayMarker::query()->insert($markers);
                }

                $this->updateCounters($session, $meta, $bytes, $events, $markers);
                $this->referenceSnapshots($sessionId, (array) Arr::get($meta, 'snapshots', []));
            });
        } catch (UniqueConstraintViolationException) {
            // The same batch twice at once (a retry that overtook the first try): the other copy is stored.
            return ['session' => $session, 'created' => false, 'duplicate' => true, 'foreign' => false, 'missing_assets' => []];
        }

        // The first batch that is stored, whichever request made the row: dispatched once per recording.
        if ((int) $session->chunk_count === 1) {
            ReplaySessionStarted::dispatch($session);
        }

        return [
            'session' => $session,
            'created' => $created,
            'duplicate' => false,
            'foreign' => false,
            'missing_assets' => $this->missingAssets((array) Arr::get($meta, 'assets', [])),
        ];
    }

    /**
     * The recording's row, made by this batch when it is the first. No transaction and no lock around it: two
     * first batches of a recording may arrive at once, and a lock on a row that is not there yet deadlocks MySQL,
     * while a failed insert inside a transaction leaves Postgres refusing everything after it. createOrFirst()
     * inserts, and on the unique violation (behind a savepoint when a transaction is open) loads the other's row.
     *
     * @param  array<string, mixed>  $meta
     * @return array{0: ReplaySession, 1: bool}
     */
    protected function session(string $sessionId, array $meta, ContextToken $context, ?string $userAgent): array
    {
        /** @var ReplaySession|null $session */
        $session = ReplaySession::query()->find($sessionId);

        if ($session !== null) {
            return [$session, false];
        }

        $session = ReplaySession::query()->createOrFirst(['id' => $sessionId], [
            'user_type' => $context->userType,
            'user_id' => $context->userId,
            'tenant_type' => $context->tenantType,
            'tenant_id' => $context->tenantId,
            'impersonator_id' => $context->impersonatorId,
            'properties' => $context->properties ?: null,
            'entry_url' => Str::limit((string) Arr::get($meta, 'url', ''), 2000, '') ?: null,
            'user_agent' => $userAgent === null || ! config('session-replay.privacy.store_user_agent', true) ? null : Str::limit($userAgent, 500, ''),
            'device' => SessionReplay::device($userAgent),
            'viewport_width' => $this->smallInt(Arr::get($meta, 'viewport.width')),
            'viewport_height' => $this->smallInt(Arr::get($meta, 'viewport.height')),
            'started_at' => $this->time(Arr::get($meta, 'from')) ?? now(),
            'last_activity_at' => $this->time(Arr::get($meta, 'to')) ?? now(),
        ]);

        return [$session, $session->wasRecentlyCreated];
    }

    /**
     * @param  array<int, mixed>  $markers
     * @return array<int, array<string, mixed>>
     */
    protected function markers(string $sessionId, array $markers): array
    {
        $rows = [];

        foreach (array_slice($markers, 0, self::MAX_MARKERS_PER_BATCH) as $marker) {
            if (! is_array($marker) || ! in_array($marker['type'] ?? null, ReplayMarker::TYPES, true)) {
                continue;
            }

            $payload = is_array($marker['payload'] ?? null) ? json_encode($marker['payload']) : null;

            $rows[] = [
                'replay_session_id' => $sessionId,
                'type' => $marker['type'],
                'label' => Str::limit(trim((string) ($marker['label'] ?? '')), 480) ?: $marker['type'],
                'payload' => $payload !== false && $payload !== null && strlen($payload) <= 8192 ? $payload : null,
                'at_ms' => $this->milliseconds($marker['at'] ?? null) ?? now()->getTimestampMs(),
                'created_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * In one statement, each value worked out by the database from the row as it is: two batches of a recording
     * stored at once both count, and neither moves the last activity or a worst vital back.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<int, array<string, mixed>>  $markers
     */
    protected function updateCounters(ReplaySession $session, array $meta, int $bytes, int $events, array $markers): void
    {
        $types = array_count_values(array_column($markers, 'type'));
        $vitals = $this->worstVitals($markers);
        $lastActivity = $this->time(Arr::get($meta, 'to'));

        $updates = [
            'chunk_count' => $this->add('chunk_count', 1),
            'event_count' => $this->add('event_count', $events),
            'bytes' => DB::raw('bytes + '.$bytes),
            'page_count' => $this->add('page_count', $types['navigation'] ?? 0),
            'error_count' => $this->add('error_count', ($types['error'] ?? 0) + ($types['request'] ?? 0)),
            'rage_click_count' => $this->add('rage_click_count', $types['rage-click'] ?? 0),
            'active_ms' => DB::raw('active_ms + '.$this->clamp(Arr::get($meta, 'activeMs'), 3_600_000)),
        ];

        if ($lastActivity !== null) {
            $updates['last_activity_at'] = $this->later('last_activity_at', $this->connection()->escape($session->fromDateTime($lastActivity)));
        }

        foreach (['lcp_ms' => 'LCP', 'inp_ms' => 'INP', 'cls' => 'CLS'] as $column => $name) {
            if (isset($vitals[$name])) {
                $updates[$column] = $this->later($column, $name === 'CLS' ? number_format($vitals[$name], 4, '.', '') : (string) (int) $vitals[$name]);
            }
        }

        ReplaySession::query()->whereKey($session->getKey())->update($updates);
        $session->refresh();
    }

    /** column + n, stopping at the largest value an integer column holds on every database (Postgres' is signed). */
    protected function add(string $column, int $amount): Expression
    {
        $amount = max(0, min($amount, self::MAX_INTEGER));

        return DB::raw(sprintf('CASE WHEN %1$s > %2$d THEN %3$d ELSE %1$s + %4$d END', $column, self::MAX_INTEGER - $amount, self::MAX_INTEGER, $amount));
    }

    /** The column, or the given SQL literal when the column is empty or below it. */
    protected function later(string $column, string $literal): Expression
    {
        return DB::raw(sprintf('CASE WHEN %1$s IS NULL OR %1$s < %2$s THEN %2$s ELSE %1$s END', $column, $literal));
    }

    /**
     * @param  array<int, array<string, mixed>>  $markers
     * @return array<string, float>
     */
    protected function worstVitals(array $markers): array
    {
        $worst = [];

        foreach ($markers as $marker) {
            if ($marker['type'] !== 'vital' || $marker['payload'] === null) {
                continue;
            }

            $payload = json_decode((string) $marker['payload'], true);
            $name = $payload['name'] ?? null;
            $value = $payload['value'] ?? null;

            if (in_array($name, ['LCP', 'INP', 'CLS'], true) && is_numeric($value) && $value >= 0) {
                // Within the columns: decimal(8, 4), and an integer that is signed on Postgres.
                $value = $name === 'CLS' ? round(min((float) $value, 9999), 4) : (float) min(round((float) $value), self::MAX_INTEGER);
                $worst[$name] = max($worst[$name] ?? 0, $value);
            }
        }

        return $worst;
    }

    /**
     * Of the stylesheet hashes this batch references, the ones not stored yet;
     * the ones that are get their last-seen date moved, which is what keeps
     * them from being pruned.
     *
     * @param  array<int, mixed>  $hashes
     * @return array<int, string>
     */
    protected function missingAssets(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter(
            array_slice($hashes, 0, 100),
            fn ($hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        )));

        if ($hashes === []) {
            return [];
        }

        // Only stylesheets count as known: whether a shared snapshot exists is never told to a browser.
        $known = ReplayAsset::query()->whereIn('hash', $hashes)->where('kind', ReplayAsset::STYLESHEET)->pluck('hash')->all();

        if ($known !== []) {
            ReplayAsset::query()->whereIn('hash', $known)->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDay()))->update(['last_seen_at' => now()]);
        }

        return array_values(array_diff($hashes, $known));
    }

    /**
     * Remember which shared snapshots the recording points at, the ones that
     * are stored; the viewer serves only those, and prune keeps them. Nothing
     * of this goes back to the browser.
     *
     * @param  array<int, mixed>  $hashes
     */
    protected function referenceSnapshots(string $sessionId, array $hashes): void
    {
        $hashes = array_values(array_unique(array_filter(
            array_slice($hashes, 0, 20),
            fn ($hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        )));

        if ($hashes === []) {
            return;
        }

        $stored = ReplayAsset::query()->whereIn('hash', $hashes)->pluck('hash')->all();

        ReplaySessionAsset::query()->insertOrIgnore(array_map(fn (string $hash): array => [
            'replay_session_id' => $sessionId,
            'hash' => $hash,
            'created_at' => now(),
        ], $stored));
    }

    protected function connection(): Connection
    {
        return DB::connection(config('session-replay.storage.connection'));
    }

    protected function time(mixed $milliseconds): ?Carbon
    {
        $milliseconds = $this->milliseconds($milliseconds);

        if ($milliseconds === null) {
            return null;
        }

        $time = Carbon::createFromTimestampMs($milliseconds);

        // A browser clock that is far off would sort the recording into the wrong decade.
        return $time->between(now()->subDays(8), now()->addMinutes(10)) ? $time : now();
    }

    /** A smallint column, signed on Postgres. */
    protected function smallInt(mixed $value): ?int
    {
        return is_numeric($value) && $value > 0 ? $this->clamp($value, 32767) : null;
    }

    /** Epoch milliseconds from the browser, or null when it sent none, nothing positive or something past the year 9999. */
    protected function milliseconds(mixed $value): ?int
    {
        return is_numeric($value) && $value >= 1 && $value <= self::MAX_MILLISECONDS ? (int) $value : null;
    }

    /** A number from the browser between 0 and $max; anything else is 0. */
    protected function clamp(mixed $value, int $max): int
    {
        return is_numeric($value) ? (int) max(0, min((float) $value, $max)) : 0;
    }
}
