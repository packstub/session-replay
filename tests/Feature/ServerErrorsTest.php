<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ServerErrors;

beforeEach(function () {
    Route::middleware('web')->post('orders', function () {
        throw new RuntimeException('Could not save https://app.test/orders?token=abc for ada@example.com');
    });
    Route::middleware('web')->get('missing', fn () => abort(404));
    Route::middleware('web')->get('session-replay/lab/boom', function () {
        throw new RuntimeException('The package\'s own route failed.');
    });
    Route::get('context', fn () => response()->json(Context::all()));
});

/** @return array<string, mixed> */
function serverMarker(ReplaySession $session): ?array
{
    $marker = ReplayMarker::query()->where('replay_session_id', $session->id)->where('label', 'like', 'Server error%')->first();

    return $marker === null ? null : ['label' => $marker->label, 'payload' => json_decode((string) $marker->getRawOriginal('payload'), true), 'at' => $marker->at_ms];
}

it('puts an exception of a recorded request on the replay: class, status, method and path, no message', function () {
    $user = $this->user();
    $session = $this->recording($user);

    $this->actingAs($user)->withUnencryptedCookie('session_replay_id', $session->id)->post('orders?token=abc')->assertServerError();

    $marker = serverMarker($session);

    expect($marker['label'])->toBe('Server error: RuntimeException')
        ->and($marker['payload'])->toBe(['source' => 'server', 'exception' => RuntimeException::class, 'status' => 500, 'method' => 'POST', 'path' => '/orders'])
        ->and($marker['at'])->toBeGreaterThan(now()->subMinute()->getTimestampMs())
        ->and($session->fresh()->error_count)->toBe(1);
});

it('adds the message, its URLs redacted, only when asked to', function () {
    config()->set('session-replay.capture.server_errors', 'message');

    $user = $this->user();
    $session = $this->recording($user);

    $this->actingAs($user)->withUnencryptedCookie('session_replay_id', $session->id)->post('orders')->assertServerError();

    expect(serverMarker($session)['label'])->toBe('Server error: RuntimeException: Could not save https://app.test/orders?token=redacted for ada@example.com');
});

it('leaves out someone else\'s recording, exceptions Laravel does not report, the package\'s own routes, and when turned off', function () {
    $owner = $this->user();
    $session = $this->recording($owner);

    $this->actingAs($this->user())->withUnencryptedCookie('session_replay_id', $session->id)->post('orders')->assertServerError();
    $this->actingAs($owner)->get('missing')->assertNotFound();
    $this->get('session-replay/lab/boom')->assertServerError();

    config()->set('session-replay.capture.server_errors', false);
    $this->post('orders')->assertServerError();

    expect(serverMarker($session))->toBeNull()
        ->and($session->fresh()->error_count)->toBe(0);
});

it('keeps the error of a recording not stored yet for its first batch (mode on_error)', function () {
    $user = $this->user();
    $id = (string) Str::uuid();

    $this->actingAs($user)->withUnencryptedCookie('session_replay_id', $id)->post('orders')->assertServerError();

    expect(ReplaySession::query()->find($id))->toBeNull();

    $this->ingest(['token' => $this->token($user), 'session' => $id])->assertCreated();

    $session = ReplaySession::query()->findOrFail($id);

    expect(serverMarker($session)['payload']['path'])->toBe('/orders')
        ->and($session->error_count)->toBe(1);

    // Taken once.
    $this->ingest(['token' => $this->token($user), 'session' => $id, 'seq' => 1])->assertOk();

    expect(ReplayMarker::query()->where('replay_session_id', $id)->where('label', 'like', 'Server error%')->count())->toBe(1);
});

it('does not hand a kept error to a recording of someone else', function () {
    $id = (string) Str::uuid();

    $this->actingAs($this->user())->withUnencryptedCookie('session_replay_id', $id)->post('orders')->assertServerError();

    $this->ingest(['token' => $this->token($this->user()), 'session' => $id])->assertCreated();

    expect(serverMarker(ReplaySession::query()->findOrFail($id)))->toBeNull();
});

it('adds a link that opens the replay at the request\'s moment to the log context', function () {
    $id = (string) Str::uuid();
    $before = now()->getTimestampMs();

    $moment = $this->withUnencryptedCookie('session_replay_id', $id)->get('context')->json('session_replay_moment');

    expect($moment)->toStartWith(route('session-replay.show', $id).'?at=')
        ->and((int) Str::after($moment, '?at='))->toBeGreaterThanOrEqual($before);
});

it('redacts the listed query parameters of every URL in a text', function () {
    expect(ServerErrors::redactUrls('GET https://app.test/a?Token=1&page=2 and http://x.test/b?code=3', ['token', 'code']))
        ->toBe('GET https://app.test/a?Token=redacted&page=2 and http://x.test/b?code=redacted')
        ->and(ServerErrors::redactUrls('no url here', ['token']))->toBe('no url here');
});
