<?php

namespace Packstub\SessionReplay\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Support\ReplayStorage;

/**
 * @property string $id
 * @property string|null $user_type
 * @property string|null $user_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $impersonator_id
 * @property array<string, mixed>|null $properties
 * @property string|null $entry_url
 * @property int $page_count
 * @property int $event_count
 * @property int $chunk_count
 * @property int $bytes
 * @property int $error_count
 * @property int $rage_click_count
 * @property int|null $lcp_ms
 * @property int|null $inp_ms
 * @property float|null $cls
 * @property int $active_ms
 * @property bool $pinned
 * @property bool $truncated
 * @property Carbon|null $started_at
 * @property Carbon|null $last_activity_at
 */
class ReplaySession extends ReplayModel
{
    use HasUuids;

    protected $table = 'replay_sessions';

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'pinned' => 'boolean',
            'truncated' => 'boolean',
            'cls' => 'float',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The rows go with the foreign keys; the files have to be removed by hand.
        static::deleting(function (self $session): void {
            app(ReplayStorage::class)->deleteSession($session->id);
        });
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ReplayChunk::class)->orderBy('seq');
    }

    public function markers(): HasMany
    {
        return $this->hasMany(ReplayMarker::class)->orderBy('at_ms');
    }

    /** The shared snapshots this recording points at (by hash). */
    public function snapshotReferences(): HasMany
    {
        return $this->hasMany(ReplaySessionAsset::class);
    }

    /** Wall-clock length of the recording in milliseconds. */
    public function durationMs(): int
    {
        if (! $this->started_at || ! $this->last_activity_at) {
            return 0;
        }

        return (int) max(0, $this->started_at->diffInMilliseconds($this->last_activity_at));
    }

    /** "4m 12s" — for a list. */
    public function durationForHumans(): string
    {
        $seconds = intdiv($this->durationMs(), 1000);

        return match (true) {
            $seconds >= 3600 => sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
            $seconds >= 60 => sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60),
            default => $seconds.'s',
        };
    }

    /** Still receiving events (activity inside the last two minutes). */
    public function isLive(): bool
    {
        return $this->last_activity_at?->gt(now()->subMinutes(2)) ?? false;
    }

    /** good | needs-improvement | poor | null, the worst of the vitals that were measured (web.dev thresholds). */
    public function vitalsRating(): ?string
    {
        $ratings = array_filter([
            $this->lcp_ms === null ? null : ($this->lcp_ms > 4000 ? 2 : ($this->lcp_ms > 2500 ? 1 : 0)),
            $this->inp_ms === null ? null : ($this->inp_ms > 500 ? 2 : ($this->inp_ms > 200 ? 1 : 0)),
            $this->cls === null ? null : ($this->cls > 0.25 ? 2 : ($this->cls > 0.1 ? 1 : 0)),
        ], fn (?int $rating): bool => $rating !== null);

        if ($ratings === []) {
            return null;
        }

        return ['good', 'needs-improvement', 'poor'][max($ratings)];
    }

    /** The page in the built-in viewer, or wherever the app said replays are watched. */
    public function url(): ?string
    {
        return SessionReplay::urlFor($this);
    }

    public function scopeForUser(Builder $query, Model $user): void
    {
        $query->where('user_type', $user->getMorphClass())->where('user_id', (string) $user->getKey());
    }

    public function scopeForTenant(Builder $query, Model $tenant): void
    {
        $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', (string) $tenant->getKey());
    }

    public function scopeWithErrors(Builder $query): void
    {
        $query->where('error_count', '>', 0);
    }

    public function scopePoorVitals(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->where('lcp_ms', '>', 4000)->orWhere('inp_ms', '>', 500)->orWhere('cls', '>', 0.25));
    }

    /** Older than the retention window and not pinned. */
    public function scopePrunable(Builder $query, int $days): void
    {
        $query->where('pinned', false)->where('last_activity_at', '<', now()->subDays($days));
    }
}
