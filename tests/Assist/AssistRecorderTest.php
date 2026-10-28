<?php

use Packstub\SessionReplay\Facades\SessionReplay;

function assistConfig(string $html): ?array
{
    preg_match('/window\.__sessionReplay=(\{.*?\});<\/script>/s', $html, $match);

    return (json_decode($match[1] ?? 'null', true) ?? [])['assist'] ?? null;
}

it('hands the recorder the visitor routes, the timings and the strings while Assist is on', function () {
    $assist = assistConfig($this->actingAs($this->user())->get('page')->assertOk()->getContent());

    expect($assist['routes']['store'])->toBe(route('session-replay.assist.store'))
        ->and($assist['routes']['heartbeat'])->toBe(route('session-replay.assist.heartbeat'))
        // The browser puts the request id where __ID__ is.
        ->and($assist['routes']['poll'])->toBe(str_replace('00000000-0000-0000-0000-000000000000', '__ID__', route('session-replay.assist.poll', ['id' => '00000000-0000-0000-0000-000000000000'])))
        ->and(array_keys($assist['routes']))->toEqualCanonicalizing(['store', 'heartbeat', 'poll', 'accept', 'decline', 'cancel', 'hangup', 'leave', 'signal', 'grants'])
        ->and($assist['routes']['signal'])->toEndWith('/assist/requests/__ID__/signals')
        // The rest of Assist loads from the package's own script route, versioned like the recorder.
        ->and($assist['module'])->toBe(SessionReplay::scriptUrl('assist.js'))
        ->and($assist['button'])->toBeFalse()
        ->and($assist['controlMasked'])->toBeFalse()
        ->and($assist['heartbeatMs'])->toBe(15000)
        ->and($assist['poll'])->toBe(['waiting' => 3000, 'active' => 2000])
        ->and($assist['labels']['offer'])->toBe(':name would like to help you with this page.')
        ->and($assist['labels'])->toHaveKeys(['getHelp', 'askControl', 'askMasked', 'askAudio', 'askScreen', 'endControl']);
});

it('keeps the active poll under Calls\' hold and turns on the button and masked typing from the config', function () {
    config()->set('session-replay.assist.button', true);
    config()->set('session-replay.assist.control_masked', true);
    config()->set('session-replay.assist.poll.active_ms', 9000);

    $assist = assistConfig((string) SessionReplay::recorder(['user' => $this->user()]));

    expect($assist['button'])->toBeTrue()
        ->and($assist['controlMasked'])->toBeTrue()
        ->and($assist['poll']['active'])->toBe(4000);
});

it('puts nothing of Assist on the page while it is off', function () {
    config()->set('session-replay.assist.enabled', false);

    expect(assistConfig((string) SessionReplay::recorder(['user' => $this->user()])))->toBeNull();
});

it('serves the Assist module next to the recorder, as JavaScript the browser may import', function () {
    $response = $this->get(SessionReplay::scriptUrl('assist.js'))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/javascript')
        ->and(file_get_contents(SessionReplay::scriptPath('assist.js')))->toContain('export')->toContain('createDataChannel')
        // The recorder itself carries only the hook: no calls engine, no visitor UI.
        ->and(file_get_contents(SessionReplay::scriptPath('recorder.js')))->not->toContain('createDataChannel')->not->toContain('border: 3px dashed');
});
