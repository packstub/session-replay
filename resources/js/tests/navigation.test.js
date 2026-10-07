import assert from 'node:assert/strict';
import { test } from 'node:test';
import { navigationPause } from '../lib/navigation.js';

function setup(options = {}) {
    const calls = [];
    const timers = new Map();
    let next = 1;

    const pause = navigationPause({
        pause: () => {
            calls.push('pause');

            return options.pauseResult;
        },
        resume: () => calls.push('resume'),
        setTimer: (callback, ms) => {
            const id = next++;

            timers.set(id, { callback, ms });

            return id;
        },
        clearTimer: (id) => timers.delete(id),
        timeoutMs: 5000,
    });

    const fire = () => {
        for (const [id, timer] of [...timers]) {
            timers.delete(id);
            timer.callback();
        }
    };

    return { pause, calls, timers, fire };
}

test('navigating then navigated pauses once and resumes once', () => {
    const { pause, calls, timers } = setup();

    assert.equal(pause.navigating(true), true);
    assert.equal(pause.isPaused(), true);
    assert.equal(timers.size, 1);
    assert.equal([...timers.values()][0].ms, 5000);

    assert.equal(pause.navigated(), true);
    assert.equal(pause.isPaused(), false);
    assert.equal(timers.size, 0);

    // A late second navigated, or the one Livewire fires on a fragment restore, does nothing.
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['pause', 'resume']);
});

test('navigated without navigating does nothing (first load, fragment restore)', () => {
    const { pause, calls } = setup();

    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, []);
});

test('nothing pauses when nothing is recorded', () => {
    const { pause, calls, timers } = setup();

    assert.equal(pause.navigating(false), false);
    assert.equal(pause.isPaused(), false);
    assert.equal(timers.size, 0);
    assert.deepEqual(calls, []);
});

test('a pause the recorder refuses leaves no timer behind', () => {
    const { pause, timers } = setup({ pauseResult: false });

    assert.equal(pause.navigating(true), false);
    assert.equal(pause.isPaused(), false);
    assert.equal(timers.size, 0);
});

test('the recording resumes after the timeout when navigated never comes', () => {
    const { pause, calls, fire } = setup();

    pause.navigating(true);
    fire();

    assert.equal(pause.isPaused(), false);
    assert.deepEqual(calls, ['pause', 'resume']);

    // Livewire's navigated, arriving after the timeout, does not restart a second time.
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['pause', 'resume']);
});

test('a second navigating during the pause keeps one pause and one timer', () => {
    const { pause, calls, timers } = setup();

    pause.navigating(true);
    assert.equal(pause.navigating(true), false);

    assert.equal(timers.size, 1);
    assert.deepEqual(calls, ['pause']);
});

test('stop during the pause ends it without resuming', () => {
    const { pause, calls, timers, fire } = setup();

    pause.navigating(true);
    pause.stop();
    fire();

    assert.equal(pause.isPaused(), false);
    assert.equal(timers.size, 0);
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['pause']);
});

test('the next navigation pauses again', () => {
    const { pause, calls } = setup();

    pause.navigating(true);
    pause.navigated();
    pause.navigating(true);
    pause.navigated();

    assert.deepEqual(calls, ['pause', 'resume', 'pause', 'resume']);
});
