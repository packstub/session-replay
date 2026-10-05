<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Database\ConnectionInterface;
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

    public function __construct(protected ReplayStorage $storage) {}

    /**
     * @param  array<string, mixed>  $meta
     * @return array{session: ReplaySession, created: bool, duplicate: bool, missing_assets: array<int, string>}
     */
    public function ingest(string $sessionId, int $seq, string $gzip, array $meta, ContextToken $context, ?string $userAgent): array
    {
        $session = $this->connection()->transaction(function () use ($sessionId, $meta, $context, $userAgent) {
            /** @var ReplaySession|null $session */
            $session = ReplaySession::query()->lockForUpdate()->find($sessionId);

            if ($session !== null) {
                return $session;
            }

            $session = new ReplaySession([
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
            $session->id = $sessionId;

            try {
                $session->save();
            } catch (UniqueConstraintViolationException) {
                // Two first uploads raced; the other one made the row.
                return ReplaySession::query()->findOrFail($sessionId);
            }

            return $session;
        });

        $created = $session->wasRecentlyCreated;

        if (ReplayChunk::query()->where('replay_session_id', $sessionId)->where('seq', $seq)->exists()) {
            // A retry of an upload that did arrive: keep the first copy and say so.
            return ['session' => $session, 'created' => false, 'duplicate' => true, 'missing_assets' => []];
        }

        $bytes = $this->storage->putChunk($sessionId, $seq, $gzip);
        $from = (int) (Arr::get($meta, 'from') ?: now()->getTimestampMs());
        $to = (int) (Arr::get($meta, 'to') ?: $from);
        $events = max(0, (int) Arr::get($meta, 'events', 0));

        ReplayChunk::query()->create([
            'replay_session_id' => $sessionId,
            'seq' => $seq,
            'path' => $this->storage->chunkPath($sessionId, $seq),
            'bytes' => $bytes,
            'event_count' => $events,
            'from_ms' => $from,
            'to_ms' => max($from, $to),
        ]);

        $markers = $this->markers($sessionId, (array) Arr::get($meta, 'markers', []));

        if ($markers !== []) {
            ReplayMarker::query()->insert($markers);
        }

        $this->updateCounters($session, $meta, $bytes, $events, $markers);
        $this->referenceSnapshots($sessionId, (array) Arr::get($meta, 'snapshots', []));

        if ($created) {
            ReplaySessionStarted::dispatch($session);
        }

        return [
            'session' => $session,
            'created' => $created,
            'duplicate' => false,
            'missing_assets' => $this->missingAssets((array) Arr::get($meta, 'assets', [])),
        ];
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
                'at_ms' => (int) ($marker['at'] ?? 0) ?: now()->getTimestampMs(),
                'created_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, array<string, mixed>>  $markers
     */
    protected function updateCounters(ReplaySession $session, array $meta, int $bytes, int $events, array $markers): void
    {
        $types = array_count_values(array_column($markers, 'type'));
        $vitals = $this->worstVitals($markers);
        $lastActivity = $this->time(Arr::get($meta, 'to'));

        $updates = [
            'chunk_count' => DB::raw('chunk_count + 1'),
            'event_count' => DB::raw('event_count + '.$events),
            'bytes' => DB::raw('bytes + '.$bytes),
            'page_count' => DB::raw('page_count + '.($types['navigation'] ?? 0)),
            'error_count' => DB::raw('error_count + '.(($types['error'] ?? 0) + ($types['request'] ?? 0))),
            'rage_click_count' => DB::raw('rage_click_count + '.($types['rage-click'] ?? 0)),
            'active_ms' => DB::raw('active_ms + '.max(0, min((int) Arr::get($meta, 'activeMs', 0), 3_600_000))),
        ];

        if ($lastActivity !== null && ($session->last_activity_at === null || $lastActivity->gt($session->last_activity_at))) {
            $updates['last_activity_at'] = $lastActivity;
        }

        foreach (['lcp_ms' => 'LCP', 'inp_ms' => 'INP', 'cls' => 'CLS'] as $column => $name) {
            if (isset($vitals[$name]) && ($session->{$column} === null || $vitals[$name] > $session->{$column})) {
                $updates[$column] = $vitals[$name];
            }
        }

        ReplaySession::query()->whereKey($session->getKey())->update($updates);
        $session->refresh();
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
                $value = $name === 'CLS' ? round(min((float) $value, 9999), 4) : (float) min((int) round((float) $value), 4_000_000_000);
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

    protected function connection(): ConnectionInterface
    {
        return DB::connection(config('session-replay.storage.connection'));
    }

    protected function time(mixed $milliseconds): ?Carbon
    {
        if (! is_numeric($milliseconds) || $milliseconds <= 0) {
            return null;
        }

        $time = Carbon::createFromTimestampMs((int) $milliseconds);

        // A browser clock that is far off would sort the recording into the wrong decade.
        return $time->between(now()->subDays(8), now()->addMinutes(10)) ? $time : now();
    }

    protected function smallInt(mixed $value): ?int
    {
        return is_numeric($value) && $value > 0 ? (int) min((int) $value, 65535) : null;
    }
}
