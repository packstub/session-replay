<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplayChunk;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ReplayStorage;

function storedAsset(string $css, $lastSeen): ReplayAsset
{
    $storage = app(ReplayStorage::class);
    $hash = hash('sha256', $css);

    $storage->putAsset($hash, gzencode($css));

    return ReplayAsset::query()->create(['hash' => $hash, 'path' => $storage->assetPath($hash), 'bytes' => 10, 'raw_bytes' => strlen($css), 'last_seen_at' => $lastSeen]);
}

it('deletes old recordings with their files, chunks and markers, and keeps pinned and recent ones', function () {
    $old = $this->recording(attributes: ['started_at' => now()->subDays(40), 'last_activity_at' => now()->subDays(40)]);
    $pinned = $this->recording(attributes: ['started_at' => now()->subDays(40), 'last_activity_at' => now()->subDays(40), 'pinned' => true]);
    $recent = $this->recording();

    ReplayMarker::query()->create(['replay_session_id' => $old->id, 'type' => 'error', 'label' => 'Boom', 'at_ms' => 1]);

    $oldPath = $old->chunks()->sole()->path;

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(ReplaySession::query()->pluck('id')->all())->toEqualCanonicalizing([$pinned->id, $recent->id])
        ->and(ReplayChunk::query()->where('replay_session_id', $old->id)->exists())->toBeFalse()
        ->and(ReplayMarker::query()->count())->toBe(0);

    Storage::disk('replays')->assertMissing($oldPath);
    Storage::disk('replays')->assertExists($recent->chunks()->sole()->path);
});

it('honours --days and refuses a window under a day', function () {
    $this->recording(attributes: ['last_activity_at' => now()->subDays(3)]);

    $this->artisan('session-replay:prune', ['--days' => 7])->assertSuccessful();
    expect(ReplaySession::query()->count())->toBe(1);

    $this->artisan('session-replay:prune', ['--days' => 2])->assertSuccessful();
    expect(ReplaySession::query()->count())->toBe(0);

    $this->artisan('session-replay:prune', ['--days' => 0])->assertFailed();
});

it('drops stylesheets nothing can reference any more and keeps the ones a remaining recording may need', function () {
    $this->recording(attributes: ['started_at' => now()->subDays(10), 'last_activity_at' => now()->subDays(10)]);

    $orphan = storedAsset('a{}', now()->subDays(60));
    $needed = storedAsset('b{}', now()->subDays(9));

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(ReplayAsset::query()->pluck('hash')->all())->toBe([$needed->hash]);

    Storage::disk('replays')->assertMissing($orphan->path);
    Storage::disk('replays')->assertExists($needed->path);
});

it('keeps a stylesheet an old pinned recording still points at', function () {
    $this->recording(attributes: ['started_at' => now()->subDays(90), 'last_activity_at' => now()->subDays(90), 'pinned' => true]);

    $asset = storedAsset('c{}', now()->subDays(89));

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(ReplayAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
});

it('removes the files when a recording is deleted by hand', function () {
    $session = $this->recording();
    $path = $session->chunks()->sole()->path;

    $session->delete();

    Storage::disk('replays')->assertMissing($path);
});

it('indexes a workspace\'s recordings by start, and adds the index to an install from before it only once', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_10_07_000001_add_tenant_started_at_index_to_replay_sessions_table.php';
    $columns = ['tenant_type', 'tenant_id', 'started_at'];

    expect(Schema::hasIndex('replay_sessions', $columns))->toBeTrue();

    // Already there on a fresh install: nothing happens.
    $migration->up();

    Schema::table('replay_sessions', fn ($table) => $table->dropIndex($columns));

    expect(Schema::hasIndex('replay_sessions', $columns))->toBeFalse();

    $migration->up();

    expect(Schema::hasIndex('replay_sessions', $columns))->toBeTrue();
});
