<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Events\ReplaySessionStarted;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplayChunk;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ContextToken;

it('stores the first batch as a recording with who, where and the file on disk', function () {
    Event::fake([ReplaySessionStarted::class]);

    $user = $this->user();
    $team = $this->team();
    $id = (string) Str::uuid();

    $this->ingest([
        'token' => $this->token($user, $team, '99', ['panel' => 'admin']),
        'session' => $id,
    ])->assertCreated()->assertJson(['ok' => true, 'duplicate' => false, 'missing_assets' => []]);

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->user_type)->toBe($user->getMorphClass())
        ->and($session->user_id)->toBe((string) $user->id)
        ->and($session->user->is($user))->toBeTrue()
        ->and($session->tenant->is($team))->toBeTrue()
        ->and($session->impersonator_id)->toBe('99')
        ->and($session->properties)->toBe(['panel' => 'admin'])
        ->and($session->entry_url)->toBe('https://app.test/orders')
        ->and($session->device)->toBe('desktop')
        ->and($session->viewport_width)->toBe(1440)
        ->and($session->chunk_count)->toBe(1)
        ->and($session->event_count)->toBe(3)
        ->and($session->active_ms)->toBe(800)
        ->and($session->bytes)->toBeGreaterThan(0);

    $chunk = ReplayChunk::query()->sole();

    Storage::disk('replays')->assertExists($chunk->path);
    expect(json_decode(gzdecode(Storage::disk('replays')->get($chunk->path)), true))->toHaveCount(3);

    Event::assertDispatched(ReplaySessionStarted::class, fn ($event) => $event->session->is($session));
});

it('compresses a batch that arrives as plain JSON, so the disk has one shape', function () {
    $this->ingest(['gzip' => false])->assertCreated();

    $stored = Storage::disk('replays')->get(ReplayChunk::query()->sole()->path);

    expect(str_starts_with($stored, "\x1f\x8b"))->toBeTrue()
        ->and(json_decode(gzdecode($stored), true))->toHaveCount(3);
});

it('appends batches, indexes markers and keeps the worst vitals', function () {
    $user = $this->user();
    $id = (string) Str::uuid();
    $now = now()->getTimestampMs();

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'meta' => ['markers' => [
        ['type' => 'navigation', 'label' => '/orders', 'at' => $now - 900],
        ['type' => 'vital', 'label' => 'LCP 1800 ms', 'payload' => ['name' => 'LCP', 'value' => 1800.4, 'rating' => 'good'], 'at' => $now - 500],
    ]]])->assertCreated();

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 1, 'meta' => ['markers' => [
        ['type' => 'error', 'label' => 'TypeError: x is undefined', 'payload' => ['line' => 12], 'at' => $now],
        ['type' => 'request', 'label' => 'Livewire request failed (419)', 'payload' => ['status' => 419], 'at' => $now],
        ['type' => 'rage-click', 'label' => 'button.fi-btn', 'at' => $now],
        ['type' => 'vital', 'label' => 'LCP 5200 ms', 'payload' => ['name' => 'LCP', 'value' => 5200, 'rating' => 'poor'], 'at' => $now],
        ['type' => 'vital', 'label' => 'CLS 0.310', 'payload' => ['name' => 'CLS', 'value' => 0.31], 'at' => $now],
        ['type' => 'made-up', 'label' => 'ignored', 'at' => $now],
        'not even an array',
    ]]])->assertOk();

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->chunk_count)->toBe(2)
        ->and($session->event_count)->toBe(6)
        ->and($session->page_count)->toBe(1)
        ->and($session->error_count)->toBe(2)
        ->and($session->rage_click_count)->toBe(1)
        ->and($session->lcp_ms)->toBe(5200)
        ->and($session->cls)->toBe(0.31)
        ->and($session->vitalsRating())->toBe('poor')
        ->and(ReplayMarker::query()->count())->toBe(7)
        ->and(ReplayMarker::query()->where('type', 'error')->sole()->payload)->toBe(['line' => 12]);

    expect(ReplaySession::query()->withErrors()->count())->toBe(1)
        ->and(ReplaySession::query()->poorVitals()->count())->toBe(1)
        ->and($user->sessionReplays()->count())->toBe(1);
});

it('keeps the first copy when an upload is retried', function () {
    $user = $this->user();
    $id = (string) Str::uuid();

    $this->ingest(['token' => $this->token($user), 'session' => $id])->assertCreated();
    $this->ingest(['token' => $this->token($user), 'session' => $id, 'events' => $this->events(9)])->assertOk()->assertJson(['duplicate' => true]);

    expect(ReplayChunk::query()->count())->toBe(1)
        ->and(ReplaySession::query()->findOrFail($id)->event_count)->toBe(3);
});

it('refuses a token that is missing, forged, tampered with or too old', function () {
    $valid = $this->token($this->user());
    [$payload, $signature] = explode('.', $valid);

    $this->ingest(['token' => ''])->assertUnauthorized()->assertJson(['stop' => true]);
    $this->ingest(['token' => 'abc.def'])->assertUnauthorized();
    $this->ingest(['token' => rtrim(strtr(base64_encode('{"u":["x","1"],"iat":'.time().'}'), '+/', '-_'), '=').'.'.$signature])->assertUnauthorized();

    $old = new ContextToken('user', '1', issuedAt: time() - 7 * 86400 - 60);
    $this->ingest(['token' => $old->encode()])->assertUnauthorized();

    expect(ReplaySession::query()->count())->toBe(0);
});

it('keeps a token valid for ingest.token_days, for pages that come out of a long-lived cache', function () {
    $user = $this->user();
    $nineDays = new ContextToken($user->getMorphClass(), (string) $user->id, issuedAt: time() - 9 * 86400);

    $this->ingest(['token' => $nineDays->encode()])->assertUnauthorized();

    config()->set('session-replay.ingest.token_days', 10);

    $this->ingest(['token' => $nineDays->encode()])->assertCreated();
});

it('lets a token that names nobody outlive the limit only when the app says so', function () {
    config()->set('session-replay.guests', true);

    $old = time() - 400 * 86400;

    $this->ingest(['token' => (new ContextToken(issuedAt: $old))->encode()])->assertUnauthorized();

    config()->set('session-replay.ingest.guest_tokens_expire', false);

    $this->ingest(['token' => (new ContextToken(issuedAt: $old))->encode()])->assertCreated();

    // A workspace or an impersonator is identity too, and a person always is.
    $this->ingest(['token' => (new ContextToken(tenantType: 'team', tenantId: '1', issuedAt: $old))->encode()])->assertUnauthorized();
    $this->ingest(['token' => (new ContextToken(impersonatorId: '9', issuedAt: $old))->encode()])->assertUnauthorized();
    $this->ingest(['token' => (new ContextToken('user', '1', issuedAt: $old))->encode()])->assertUnauthorized();
});

it('does not record guests unless the app says so', function () {
    $this->ingest(['token' => $this->token(null)])->assertForbidden()->assertJson(['stop' => true]);

    config()->set('session-replay.guests', true);

    $this->ingest(['token' => $this->token(null)])->assertCreated();

    expect(ReplaySession::query()->sole()->user_id)->toBeNull();
});

it('never lets one person write into another person\'s recording', function () {
    $session = $this->recording($ada = $this->user());

    $this->ingest(['token' => $this->token($this->user()), 'session' => $session->id, 'seq' => 1])->assertForbidden();
    $this->ingest(['token' => $this->token($ada), 'session' => $session->id, 'seq' => 1])->assertOk();

    expect($session->refresh()->chunk_count)->toBe(2);
});

it('never lets a recording cross into another workspace', function () {
    $ada = $this->user();
    $acme = $this->team('Acme');
    $id = (string) Str::uuid();

    $this->ingest(['token' => $this->token($ada, $acme), 'session' => $id])->assertCreated();
    $this->ingest(['token' => $this->token($ada, $this->team('Globex')), 'session' => $id, 'seq' => 1])->assertForbidden()->assertJson(['stop' => true]);
    $this->ingest(['token' => $this->token($ada), 'session' => $id, 'seq' => 1])->assertForbidden();
    $this->ingest(['token' => $this->token($ada, $acme), 'session' => $id, 'seq' => 1])->assertOk();
});

it('validates the session id, the sequence and the upload', function () {
    $this->ingest(['session' => 'not-a-uuid'])->assertUnprocessable();
    $this->ingest(['seq' => -1])->assertUnprocessable();
    $this->ingest(['events' => '', 'gzip' => false])->assertUnprocessable();
    // Claims to be gzip, is not.
    $this->ingest(['events' => "\x1f\x8bgarbage", 'gzip' => false])->assertUnprocessable();
});

it('refuses uploads over the batch limit and gzip that inflates past its ceiling', function () {
    config()->set('session-replay.ingest.max_batch_kb', 1);

    $this->ingest(['events' => random_bytes(2048), 'gzip' => false])->assertStatus(413);
    // 1 KB limit × 40 = 40 KB ceiling; 200 KB of zeros compresses to a few hundred bytes.
    $this->ingest(['events' => str_repeat('0', 200_000)])->assertUnprocessable();

    expect(ReplaySession::query()->count())->toBe(0);
});

it('refuses gzip with a second member or trailing bytes, which a browser could decode past the checks', function () {
    $events = json_encode($this->events());

    $this->ingest(['events' => gzencode($events).gzencode(str_repeat('A', 100_000)), 'gzip' => false])->assertUnprocessable();
    $this->ingest(['events' => gzencode($events).'trailing', 'gzip' => false])->assertUnprocessable();
    $this->ingest(['events' => substr(gzencode($events), 0, -4), 'gzip' => false])->assertUnprocessable();

    // A stylesheet whose first member matches the hash, with something else behind it.
    $css = 'body{color:red}';

    $this->post(route('session-replay.ingest.asset'), [
        'token' => $this->token($this->user()),
        'hash' => hash('sha256', $css),
        'content' => UploadedFile::fake()->createWithContent('content', gzencode($css).gzencode('body{background:url(https://evil.test/x)}')),
    ])->assertUnprocessable();

    expect(ReplaySession::query()->count())->toBe(0)->and(ReplayAsset::query()->count())->toBe(0);
});

it('stops a recording at its size limit and marks it truncated', function () {
    $session = $this->recording($user = $this->user());

    config()->set('session-replay.ingest.max_session_mb', 0);

    $this->ingest(['token' => $this->token($user), 'session' => $session->id, 'seq' => 1])->assertStatus(413)->assertJson(['stop' => true]);

    expect($session->refresh()->truncated)->toBeTrue()->and($session->chunk_count)->toBe(1);
});

it('refuses everything while recording is turned off', function () {
    config()->set('session-replay.enabled', false);

    $this->ingest()->assertForbidden()->assertJson(['stop' => true]);
});

it('asks for stylesheets it does not have and stores them under the hash of their content', function () {
    $css = str_repeat('.fi-btn{color:red}', 500);
    $hash = hash('sha256', $css);
    $token = $this->token($this->user());

    $this->ingest(['token' => $token, 'meta' => ['assets' => [$hash, 'not-a-hash']]])->assertCreated()->assertJson(['missing_assets' => [$hash]]);

    $this->post(route('session-replay.ingest.asset'), [
        'token' => $token,
        'hash' => $hash,
        'content' => UploadedFile::fake()->createWithContent('content', gzencode($css)),
    ])->assertCreated();

    $asset = ReplayAsset::query()->sole();

    expect($asset->hash)->toBe($hash)->and($asset->raw_bytes)->toBe(strlen($css));
    expect(gzdecode(Storage::disk('replays')->get($asset->path)))->toBe($css);

    // The next recording that references it has nothing to upload.
    $this->ingest(['token' => $token, 'meta' => ['assets' => [$hash]]])->assertCreated()->assertJson(['missing_assets' => []]);
    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => $hash, 'content' => 'x'])->assertOk()->assertJson(['duplicate' => true]);
});

it('refuses a stylesheet whose content does not match its hash', function () {
    $this->post(route('session-replay.ingest.asset'), [
        'token' => $this->token($this->user()),
        'hash' => hash('sha256', 'body{}'),
        'content' => UploadedFile::fake()->createWithContent('content', 'body{display:none}'),
    ])->assertUnprocessable();

    $this->post(route('session-replay.ingest.asset'), ['token' => 'nope', 'hash' => hash('sha256', 'a'), 'content' => 'a'])->assertUnauthorized();

    expect(ReplayAsset::query()->count())->toBe(0);
});

it('throttles per person, not per address', function () {
    config()->set('session-replay.ingest.throttle', 2);

    $ada = $this->token($this->user());

    $this->ingest(['token' => $ada])->assertCreated();
    $this->ingest(['token' => $ada])->assertCreated();
    $this->ingest(['token' => $ada])->assertStatus(429);
    $this->ingest(['token' => $this->token($this->user())])->assertCreated();
});

it('throttles guests by the page their token came from, never by a session id they make up', function () {
    config()->set('session-replay.guests', true);
    config()->set('session-replay.ingest.throttle', 2);

    $page = $this->token(null);

    // A fresh session id per request does not reset the limit.
    $this->ingest(['token' => $page])->assertCreated();
    $this->ingest(['token' => $page])->assertCreated();
    $this->ingest(['token' => $page])->assertStatus(429);

    // Another rendered page is another budget.
    $this->ingest(['token' => $this->token(null)])->assertCreated();
});

it('stops a person, and all guests together, at the daily upload limit', function () {
    config()->set('session-replay.guests', true);
    config()->set('session-replay.ingest.daily_mb', 1);
    config()->set('session-replay.ingest.guest_daily_mb', 1);

    $ada = $this->token($this->user());
    $noise = json_encode([str_repeat('x', 600 * 1024)]);

    $this->ingest(['token' => $ada, 'events' => $noise, 'gzip' => false])->assertCreated();
    $this->ingest(['token' => $ada, 'events' => $noise, 'gzip' => false])->assertStatus(429)->assertJson(['stop' => true]);
    $this->ingest(['token' => $this->token($this->user())])->assertCreated();

    $this->ingest(['token' => $this->token(null), 'events' => $noise, 'gzip' => false])->assertCreated();
    $this->ingest(['token' => $this->token(null), 'events' => $noise, 'gzip' => false])->assertStatus(429);
});

it('never lets an impersonated recording and the person\'s own write into each other', function () {
    $ada = $this->user();
    $session = $this->recording($ada);

    $this->ingest(['token' => $this->token($ada, null, '7'), 'session' => $session->id, 'seq' => 1])->assertForbidden();
    $this->ingest(['token' => $this->token($ada), 'session' => $session->id, 'seq' => 1])->assertOk();
});

it('stores an anonymous recording without a person, with guests off, and limits it per person', function () {
    config()->set('session-replay.ingest.daily_mb', 1);

    $ada = ContextToken::for($this->user(), null, '7')->withoutPerson()->encode();
    $grace = ContextToken::for($this->user())->withoutPerson()->encode();
    $id = (string) Str::uuid();

    $this->ingest(['token' => $ada, 'session' => $id])->assertCreated();

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->user_id)->toBeNull()->and($session->user_type)->toBeNull()->and($session->impersonator_id)->toBeNull();

    // Each person has their own daily allowance, not the guests' shared one.
    $noise = json_encode([str_repeat('x', 1100 * 1024)]);

    $this->ingest(['token' => $ada, 'events' => $noise, 'gzip' => false])->assertStatus(429);
    $this->ingest(['token' => $grace])->assertCreated();
});

it('stores a recording the person chose to send anonymously without them, and keeps it that way', function () {
    config()->set('session-replay.ingest.daily_mb', 1);

    $ada = $this->user();
    $team = $this->team();
    $token = $this->token($ada, $team, '7', ['plan' => 'pro']);
    $id = (string) Str::uuid();

    $this->ingest(['token' => $token, 'session' => $id, 'anonymous' => '1'])->assertCreated();

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->user_id)->toBeNull()
        ->and($session->user_type)->toBeNull()
        ->and($session->impersonator_id)->toBeNull()
        ->and($session->tenant->is($team))->toBeTrue()
        ->and($session->properties)->toBe(['plan' => 'pro']);

    // The rest of the recording comes anonymous too; an upload with the person in it is refused.
    $this->ingest(['token' => $token, 'session' => $id, 'seq' => 1, 'anonymous' => '1'])->assertOk();
    $this->ingest(['token' => $token, 'session' => $id, 'seq' => 2])->assertForbidden()->assertJson(['stop' => true]);

    // A recording that names the person cannot be continued anonymously either.
    $named = $this->recording($ada);

    $this->ingest(['token' => $this->token($ada), 'session' => $named->id, 'seq' => 1, 'anonymous' => '1'])->assertForbidden();

    // The daily allowance stays the person's own: sending anonymously does not open a second one.
    $noise = json_encode([str_repeat('x', 1100 * 1024)]);

    $this->ingest(['token' => $token, 'events' => $noise, 'gzip' => false, 'anonymous' => '1'])->assertStatus(429);
    $this->ingest(['token' => $token])->assertStatus(429);
});

it('drops the impersonator from an anonymous token even when nobody is signed in', function () {
    $token = (new ContextToken(impersonatorId: '7', issuedAt: time()))->withoutPerson();

    expect($token->impersonatorId)->toBeNull()->and($token->pseudonym)->toBeNull()->and($token->isVisitor())->toBeTrue();
});

it('keeps the device class but not the user agent when privacy.store_user_agent is off', function () {
    $this->ingest()->assertCreated();

    expect(ReplaySession::query()->sole()->user_agent)->toContain('Macintosh');

    config()->set('session-replay.privacy.store_user_agent', false);
    ReplaySession::query()->delete();

    $this->ingest()->assertCreated();

    $session = ReplaySession::query()->sole();

    expect($session->user_agent)->toBeNull()->and($session->device)->toBe('desktop');
});

it('puts no web middleware on the upload routes, which carry no CSRF token', function () {
    $middleware = app('router')->getRoutes()->getByName('session-replay.ingest')->gatherMiddleware();

    expect($middleware)->not->toContain('web')
        ->and((require __DIR__.'/../../config/session-replay.php')['ingest']['middleware'])->toBe([]);
});

/**
 * Another first batch of the recording made its row while this upload was on its way: every lookup misses the row
 * until this upload tries to insert its own.
 */
function recordingMadeDuringTheRace(string $id, $user): void
{
    ReplaySession::query()->insert(['id' => $id, 'user_type' => $user->getMorphClass(), 'user_id' => (string) $user->id, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $hidden = true;

    ReplaySession::addGlobalScope('race', function ($query) use (&$hidden): void {
        if ($hidden) {
            $query->whereRaw('1 = 0');
        }
    });

    ReplaySession::creating(function () use (&$hidden): void {
        $hidden = false;
    });

    // Global scopes outlive the test; the next one boots the model afresh.
    test()->beforeApplicationDestroyed(fn () => ReplaySession::clearBootedModels());
}

it('stores the first batch of a recording whose row another upload made a moment ago, and starts it once', function () {
    Event::fake([ReplaySessionStarted::class]);

    $user = $this->user();
    $id = (string) Str::uuid();

    recordingMadeDuringTheRace($id, $user);

    $this->ingest(['token' => $this->token($user), 'session' => $id])->assertOk()->assertJson(['duplicate' => false]);

    expect(ReplaySession::query()->findOrFail($id)->chunk_count)->toBe(1);

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 1])->assertOk();

    Event::assertDispatchedTimes(ReplaySessionStarted::class, 1);
});

it('refuses a first batch whose recording someone else made a moment ago', function () {
    $ada = $this->user();
    $id = (string) Str::uuid();

    recordingMadeDuringTheRace($id, $ada);

    $this->ingest(['token' => $this->token($this->user()), 'session' => $id])->assertForbidden()->assertJson(['stop' => true]);

    expect(ReplayChunk::query()->count())->toBe(0);
});

it('stores a batch again after it failed half way, instead of calling the retry a duplicate', function () {
    Event::fake([ReplaySessionStarted::class]);

    $user = $this->user();
    $id = (string) Str::uuid();
    $now = now()->getTimestampMs();
    $fail = true;

    // Something breaks right after the chunk row is written.
    ReplayChunk::created(function () use (&$fail): void {
        if ($fail) {
            $fail = false;

            throw new RuntimeException('The database went away.');
        }
    });

    $upload = fn () => $this->ingest(['token' => $this->token($user), 'session' => $id, 'meta' => ['markers' => [
        ['type' => 'error', 'label' => 'Boom', 'at' => $now],
    ]]]);

    $this->withoutExceptionHandling();

    expect(fn () => $upload())->toThrow(RuntimeException::class);
    expect(ReplayChunk::query()->count())->toBe(0)->and(ReplayMarker::query()->count())->toBe(0);

    $upload()->assertOk()->assertJson(['duplicate' => false]);

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->chunk_count)->toBe(1)
        ->and($session->error_count)->toBe(1)
        ->and(ReplayMarker::query()->count())->toBe(1);

    // The row was made by the first try; the recording starts with the batch that was stored.
    Event::assertDispatchedTimes(ReplaySessionStarted::class, 1);
});

it('answers duplicate when the same batch is stored by another request at the same moment', function () {
    $session = $this->recording($user = $this->user());

    // The other copy inserts its chunk row between this upload's check and its insert.
    ReplayChunk::creating(function (ReplayChunk $chunk): void {
        if (! ReplayChunk::query()->where('replay_session_id', $chunk->replay_session_id)->where('seq', $chunk->seq)->exists()) {
            ReplayChunk::query()->insert(['replay_session_id' => $chunk->replay_session_id, 'seq' => $chunk->seq, 'path' => $chunk->path, 'bytes' => 1, 'from_ms' => 1, 'to_ms' => 1]);
        }
    });

    $this->ingest(['token' => $this->token($user), 'session' => $session->id, 'seq' => 1])->assertOk()->assertJson(['duplicate' => true]);

    expect($session->refresh()->chunk_count)->toBe(1);
});

it('never moves the last activity or a worst vital back when an older batch arrives late', function () {
    $user = $this->user();
    $id = (string) Str::uuid();
    $now = now()->getTimestampMs();

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'meta' => ['from' => $now - 2000, 'to' => $now, 'markers' => [
        ['type' => 'vital', 'label' => 'LCP', 'payload' => ['name' => 'LCP', 'value' => 5200], 'at' => $now],
        ['type' => 'vital', 'label' => 'CLS', 'payload' => ['name' => 'CLS', 'value' => 0.31], 'at' => $now],
    ]]])->assertCreated();

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 1, 'meta' => ['from' => $now - 300_000, 'to' => $now - 240_000, 'markers' => [
        ['type' => 'vital', 'label' => 'LCP', 'payload' => ['name' => 'LCP', 'value' => 900], 'at' => $now - 250_000],
        ['type' => 'vital', 'label' => 'INP', 'payload' => ['name' => 'INP', 'value' => 120], 'at' => $now - 250_000],
        ['type' => 'vital', 'label' => 'CLS', 'payload' => ['name' => 'CLS', 'value' => 0.02], 'at' => $now - 250_000],
    ]]])->assertOk();

    $session = ReplaySession::query()->findOrFail($id);

    expect($session->last_activity_at->getTimestamp())->toBe(intdiv($now, 1000))
        ->and($session->lcp_ms)->toBe(5200)
        ->and($session->inp_ms)->toBe(120)
        ->and($session->cls)->toBe(0.31);
});

it('keeps numbers from the browser inside what the columns hold on every database', function () {
    $user = $this->user();
    $id = (string) Str::uuid();

    $this->ingest(['token' => $this->token($user), 'session' => $id, 'meta' => [
        'viewport' => ['width' => 100_000, 'height' => -5],
        'from' => -1000,
        'to' => 1e30,
        'events' => 1e15,
        'activeMs' => -50,
        'markers' => [
            ['type' => 'vital', 'label' => 'LCP', 'payload' => ['name' => 'LCP', 'value' => 9e12], 'at' => -7],
            ['type' => 'error', 'label' => 'Boom', 'at' => 'yesterday'],
        ],
    ]])->assertCreated();

    $session = ReplaySession::query()->findOrFail($id);
    $chunk = ReplayChunk::query()->sole();

    expect($session->viewport_width)->toBe(32767)
        ->and($session->viewport_height)->toBeNull()
        ->and($session->event_count)->toBe(2_147_483_647)
        ->and($session->active_ms)->toBe(0)
        ->and($session->lcp_ms)->toBe(2_147_483_647)
        ->and($session->started_at->isToday())->toBeTrue()
        ->and($chunk->event_count)->toBe(2_147_483_647)
        ->and($chunk->from_ms)->toBeGreaterThan(0)
        ->and($chunk->to_ms)->toBeGreaterThanOrEqual($chunk->from_ms)
        ->and(ReplayMarker::query()->pluck('at_ms')->every(fn ($at) => $at > 0))->toBeTrue();

    // The counters stop at the column's limit instead of overflowing it.
    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 1, 'meta' => ['events' => 5]])->assertOk();

    expect($session->refresh()->event_count)->toBe(2_147_483_647)->and($session->chunk_count)->toBe(2);
});

it('files a session id sent in upper case under its lower-case spelling', function () {
    Gate::define('viewSessionReplay', fn () => true);

    $user = $this->user();
    $id = (string) Str::uuid();

    $this->ingest(['token' => $this->token($user), 'session' => strtoupper($id)])->assertCreated();
    $this->ingest(['token' => $this->token($user), 'session' => strtoupper($id), 'seq' => 1])->assertOk();
    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 2])->assertOk();

    $session = ReplaySession::query()->sole();

    expect($session->id)->toBe($id)
        ->and($session->chunk_count)->toBe(3)
        ->and(ReplayChunk::query()->pluck('path')->every(fn ($path) => str_contains($path, "/sessions/{$id}/")))->toBeTrue();

    $this->actingAs($user);

    $this->get(SessionReplay::urlFor($session))->assertOk();
    $this->getJson(route('session-replay.manifest', $session))->assertOk()->assertJsonCount(3, 'chunks');
    $this->get(route('session-replay.chunk', [$session, 2]))->assertOk();
});

it('answers a field sent as a list with 422 or 401, never a server error', function () {
    $token = $this->token($this->user());
    $hash = hash('sha256', 'a{}');

    $this->post(route('session-replay.ingest'), ['token' => $token, 'session' => [(string) Str::uuid()], 'seq' => 0, 'events' => 'x'])->assertUnprocessable();
    $this->post(route('session-replay.ingest'), ['token' => [$token], 'session' => (string) Str::uuid(), 'seq' => 0, 'events' => 'x'])->assertUnauthorized();
    $this->post(route('session-replay.ingest'), ['token' => $token, 'session' => (string) Str::uuid(), 'seq' => 0, 'events' => ['x']])->assertUnprocessable();
    $this->post(route('session-replay.ingest'), ['token' => $token, 'session' => (string) Str::uuid(), 'seq' => 0, 'meta' => ['url' => 'x'], 'events' => json_encode($this->events())])->assertCreated();

    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => [$hash], 'content' => 'a{}'])->assertUnprocessable();
    $this->post(route('session-replay.ingest.asset'), ['token' => $token, 'hash' => $hash, 'content' => ['a{}']])->assertUnprocessable();
    $this->post(route('session-replay.ingest.snapshot'), ['token' => $token, 'hash' => [$hash], 'content' => 'a{}'])->assertUnprocessable();
});

it('accepts a token signed with a previous app key, so rotating the key keeps open tabs recording', function () {
    $user = $this->user();
    $old = 'base64:'.base64_encode(random_bytes(32));

    config()->set('app.key', $old);
    $token = $this->token($user);

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $this->ingest(['token' => $token])->assertUnauthorized();

    config()->set('app.previous_keys', [$old]);

    $this->ingest(['token' => $token])->assertCreated();

    // New tokens are signed with the current key only.
    $fresh = $this->token($user);

    config()->set('app.previous_keys', []);

    $this->ingest(['token' => $fresh])->assertCreated();
    $this->ingest(['token' => $token])->assertUnauthorized();
});
