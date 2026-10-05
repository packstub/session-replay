<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Models\ReplaySessionAsset;
use Packstub\SessionReplay\Support\ReplayStorage;

function snapshotTree(string $title = 'Pricing'): string
{
    return json_encode(['type' => 0, 'id' => 1, 'childNodes' => [['type' => 2, 'tagName' => 'html', 'id' => 2, 'attributes' => [], 'childNodes' => [['type' => 3, 'id' => 3, 'textContent' => $title]]]]]);
}

function sharedRecorderConfig(string $html): array
{
    preg_match('/window\.__sessionReplay=(\{.*?\});<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

/** Upload a snapshot the way the recorder does (gzip by default). */
function uploadSnapshot($test, string $token, string $tree, ?string $hash = null, bool $gzip = true)
{
    return $test->post(route('session-replay.ingest.snapshot'), [
        'token' => $token,
        'hash' => $hash ?? hash('sha256', $tree),
        'content' => UploadedFile::fake()->createWithContent('content', $gzip ? gzencode($tree) : $tree),
    ]);
}

it('shares no page by default and tells the recorder which pages do', function () {
    $this->actingAs($this->user());

    $config = sharedRecorderConfig($this->get('page')->getContent());

    expect($config['snapshots']['share'])->toBeFalse()
        ->and($config['snapshots']['volatileIds'])->toBe(['fi-dropdown-panel-*'])
        ->and($config['snapshots']['url'])->toBe(route('session-replay.ingest.snapshot'));

    config()->set('session-replay.snapshots.share_paths', ['pag*']);

    expect(sharedRecorderConfig($this->get('page')->getContent())['snapshots']['share'])->toBeTrue()
        ->and(sharedRecorderConfig($this->get('admin/support/secrets')->getContent())['snapshots']['share'])->toBeFalse();

    config()->set('session-replay.snapshots.share_paths', []);
    config()->set('session-replay.snapshots.share_routes', ['support.*']);

    expect(sharedRecorderConfig($this->get('admin/support/secrets')->getContent())['snapshots']['share'])->toBeTrue()
        ->and(sharedRecorderConfig($this->get('page')->getContent())['snapshots']['share'])->toBeFalse();
});

it('reads keyed entries in the share lists like plain ones', function () {
    $this->actingAs($this->user());

    config()->set('session-replay.snapshots.share_paths', ['docs' => 'docs/*', 'pag*']);
    config()->set('session-replay.snapshots.share_routes', ['support' => 'support.*', 'nothing.*']);

    expect(sharedRecorderConfig($this->get('page')->getContent())['snapshots']['share'])->toBeTrue()
        ->and(sharedRecorderConfig($this->get('admin/support/secrets')->getContent())['snapshots']['share'])->toBeTrue();
});

it('stores a snapshot once under the hash of its content, next to the stylesheets', function () {
    $token = $this->token($this->user());
    $tree = snapshotTree();
    $hash = hash('sha256', $tree);

    uploadSnapshot($this, $token, $tree)->assertOk()->assertExactJson(['ok' => true]);

    $asset = ReplayAsset::query()->sole();

    expect($asset->hash)->toBe($hash)
        ->and($asset->kind)->toBe(ReplayAsset::SNAPSHOT)
        ->and($asset->path)->toBe('session-replay/assets/'.substr($hash, 0, 2)."/{$hash}.json.gz")
        ->and($asset->raw_bytes)->toBe(strlen($tree));

    expect(gzdecode(Storage::disk('replays')->get($asset->path)))->toBe($tree);

    // Plain JSON is compressed here, like a batch.
    $other = snapshotTree('Docs');

    uploadSnapshot($this, $token, $other, gzip: false)->assertOk();

    $stored = Storage::disk('replays')->get(ReplayAsset::query()->where('hash', hash('sha256', $other))->sole()->path);

    expect(str_starts_with($stored, "\x1f\x8b"))->toBeTrue()->and(gzdecode($stored))->toBe($other);
});

it('answers the same whether the snapshot was stored before or not', function () {
    $token = $this->token($this->user());
    $tree = snapshotTree();
    $hash = hash('sha256', $tree);

    $first = uploadSnapshot($this, $token, $tree);
    $again = uploadSnapshot($this, $this->token($this->user()), $tree);

    expect($again->status())->toBe($first->status())
        ->and($again->getContent())->toBe($first->getContent())
        ->and(ReplayAsset::query()->count())->toBe(1);

    // Content that does not match is refused the same way for a stored hash and an unknown one.
    $stored = uploadSnapshot($this, $token, snapshotTree('Something else'), $hash);
    $unknown = uploadSnapshot($this, $token, snapshotTree('Something else'), str_repeat('d', 64));

    $stored->assertUnprocessable();
    expect($unknown->status())->toBe($stored->status())->and($unknown->getContent())->toBe($stored->getContent());

    // The stylesheet routes do not tell either: a snapshot hash is "missing" and checked like an unknown stylesheet.
    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => $hash, 'content' => 'x'])->assertUnprocessable();
    $this->ingest(['token' => $token, 'meta' => ['assets' => [$hash]]])->assertCreated()->assertJson(['missing_assets' => [$hash]]);

    // The right content through the stylesheet route changes nothing and says "created", as for a new stylesheet.
    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => $hash, 'content' => $tree])->assertCreated();

    expect(ReplayAsset::query()->sole()->kind)->toBe(ReplayAsset::SNAPSHOT);
});

it('moves the last-seen date when a stored snapshot is sent again', function () {
    $token = $this->token($this->user());
    $tree = snapshotTree();

    uploadSnapshot($this, $token, $tree);
    ReplayAsset::query()->update(['last_seen_at' => now()->subDays(5)]);

    uploadSnapshot($this, $token, $tree)->assertOk();

    expect(ReplayAsset::query()->sole()->last_seen_at->isToday())->toBeTrue();
});

it('refuses a snapshot that is not what it claims, too large, or inflates past the ceiling', function () {
    $token = $this->token($this->user());

    uploadSnapshot($this, $token, snapshotTree(), 'not-a-hash')->assertUnprocessable();
    uploadSnapshot($this, $token, snapshotTree(), hash('sha256', 'other'))->assertUnprocessable();

    config()->set('session-replay.ingest.max_batch_kb', 1);

    uploadSnapshot($this, $token, str_repeat('x', 2048), gzip: false)->assertStatus(413);

    // 1 KB as sent may inflate to 40 KB, like a batch.
    $bomb = str_repeat('a', 50 * 1024);

    uploadSnapshot($this, $token, $bomb)->assertUnprocessable();

    $this->post(route('session-replay.ingest.snapshot'), ['token' => 'nope', 'hash' => hash('sha256', 'a'), 'content' => 'a'])->assertUnauthorized();

    expect(ReplayAsset::query()->count())->toBe(0);
});

it('refuses guests while guests are not recorded, and counts against the daily limit', function () {
    uploadSnapshot($this, $this->token(null), snapshotTree())->assertForbidden();

    config()->set('session-replay.ingest.daily_mb', 1);

    $token = $this->token($this->user());
    $large = json_encode([Str::random(600 * 1024)]);

    // The second copy is stored already and still counts: what it costs never depends on what is there.
    uploadSnapshot($this, $token, $large, gzip: false)->assertOk();
    uploadSnapshot($this, $token, $large, gzip: false)->assertStatus(429)->assertJson(['stop' => true]);
});

it('remembers which stored snapshots a recording points at, and tells the browser nothing about them', function () {
    $token = $this->token($user = $this->user());
    $tree = snapshotTree();
    $hash = hash('sha256', $tree);
    $id = (string) Str::uuid();

    uploadSnapshot($this, $token, $tree)->assertOk();

    $response = $this->ingest(['token' => $token, 'session' => $id, 'meta' => ['snapshots' => [$hash, $hash, str_repeat('e', 64), 'nope', ['x']]]])
        ->assertCreated();

    expect($response->json())->toBe(['ok' => true, 'duplicate' => false, 'missing_assets' => []]);

    // Only the stored one; an unknown hash and garbage leave no row.
    expect(ReplaySessionAsset::query()->pluck('hash')->all())->toBe([$hash]);

    // A second batch that points at it again adds nothing.
    $this->ingest(['token' => $token, 'session' => $id, 'seq' => 1, 'meta' => ['snapshots' => [$hash]]])->assertOk();

    expect(ReplaySession::query()->findOrFail($id)->snapshotReferences()->count())->toBe(1);
});

it('serves a shared snapshot only behind the gate, and only for a recording that points at it', function () {
    $token = $this->token($user = $this->user());
    $tree = snapshotTree();
    $hash = hash('sha256', $tree);
    $otherTree = snapshotTree('Somebody else');
    $otherHash = hash('sha256', $otherTree);

    uploadSnapshot($this, $token, $tree);
    uploadSnapshot($this, $token, $otherTree);

    $mine = (string) Str::uuid();
    $theirs = (string) Str::uuid();

    $this->ingest(['token' => $token, 'session' => $mine, 'meta' => ['snapshots' => [$hash]]])->assertCreated();
    $this->ingest(['token' => $this->token($this->user()), 'session' => $theirs, 'meta' => ['snapshots' => [$otherHash]]])->assertCreated();

    Gate::define('viewSessionReplay', fn ($viewer, ?ReplaySession $session = null) => $session === null || $session->id === $mine);

    $this->actingAs($user);

    $manifest = $this->getJson(route('session-replay.manifest', $mine))->assertOk()->json();

    expect($manifest['snapshotUrl'])->toBe(route('session-replay.snapshot', [$mine, '__hash__']));

    $response = $this->get(route('session-replay.snapshot', [$mine, $hash]))
        ->assertOk()
        ->assertHeader('Content-Encoding', 'gzip')
        ->assertHeader('Content-Type', 'application/json');

    expect(gzdecode($response->streamedContent()))->toBe($tree);

    // Stored, but not referenced by this recording: the hash alone opens nothing.
    $this->get(route('session-replay.snapshot', [$mine, $otherHash]))->assertNotFound();

    // Nor does the stylesheet route, which serves any stylesheet to a viewer of any recording.
    $this->get(route('session-replay.asset', [$mine, $otherHash]))->assertNotFound();
    $this->get(route('session-replay.asset', [$mine, $hash]))->assertNotFound();

    // The recording that does reference it is one the gate refuses.
    $this->get(route('session-replay.snapshot', [$theirs, $otherHash]))->assertForbidden();

    auth()->logout();

    $this->get(route('session-replay.snapshot', [$mine, $hash]))->assertRedirect();
});

it('prunes a shared snapshot once no recording that is left points at it', function () {
    $token = $this->token($this->user());
    $storage = app(ReplayStorage::class);

    $kept = snapshotTree('Kept by a pinned recording');
    $gone = snapshotTree('Only an old recording');
    $fresh = snapshotTree('Uploaded, the batch not there yet');
    $orphan = snapshotTree('Nobody ever pointed at it');

    foreach ([$kept, $gone, $fresh, $orphan] as $tree) {
        uploadSnapshot($this, $token, $tree)->assertOk();
    }

    $pinned = (string) Str::uuid();
    $old = (string) Str::uuid();

    $this->ingest(['token' => $token, 'session' => $pinned, 'meta' => ['snapshots' => [hash('sha256', $kept)]]])->assertCreated();
    $this->ingest(['token' => $token, 'session' => $old, 'meta' => ['snapshots' => [hash('sha256', $gone), hash('sha256', $kept)]]])->assertCreated();

    ReplaySession::query()->whereKey($pinned)->update(['started_at' => now()->subDays(90), 'last_activity_at' => now()->subDays(90), 'pinned' => true]);
    ReplaySession::query()->whereKey($old)->update(['started_at' => now()->subDays(40), 'last_activity_at' => now()->subDays(40)]);

    // Everything but the fresh upload was last sent long ago.
    ReplayAsset::query()->where('hash', '!=', hash('sha256', $fresh))->update(['last_seen_at' => now()->subDays(60)]);

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(ReplayAsset::query()->pluck('hash')->sort()->values()->all())
        ->toBe(collect([hash('sha256', $kept), hash('sha256', $fresh)])->sort()->values()->all());

    Storage::disk('replays')->assertExists($storage->snapshotPath(hash('sha256', $kept)));
    Storage::disk('replays')->assertMissing($storage->snapshotPath(hash('sha256', $gone)));
    Storage::disk('replays')->assertMissing($storage->snapshotPath(hash('sha256', $orphan)));

    expect(ReplaySessionAsset::query()->pluck('replay_session_id')->all())->toBe([$pinned]);
});

it('keeps an old stylesheet that a recording points at as a snapshot', function () {
    $token = $this->token($this->user());
    $tree = snapshotTree();
    $hash = hash('sha256', $tree);

    // Stored through the stylesheet route first; a recording then points at it as a snapshot.
    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => $hash, 'content' => $tree])->assertCreated();
    $this->ingest(['token' => $token, 'meta' => ['snapshots' => [$hash]]])->assertCreated();

    ReplayAsset::query()->update(['last_seen_at' => now()->subDays(60)]);
    ReplaySession::query()->update(['started_at' => now()->subDays(1)]);

    $this->artisan('session-replay:prune')->assertSuccessful();

    expect(ReplayAsset::query()->where('hash', $hash)->exists())->toBeTrue();
});

it('adds the kind column to an install from before shared snapshots, and only once', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_10_05_000001_add_kind_to_replay_assets_table.php';

    // Already there on a fresh install: nothing happens.
    $migration->up();

    Schema::table('replay_assets', fn ($table) => $table->dropColumn('kind'));

    expect(Schema::hasColumn('replay_assets', 'kind'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('replay_assets', 'kind'))->toBeTrue();
});
