import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lastingPlugin, navigationPause } from '../lib/navigation.js';

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

test('swapped is set by any navigating, recorded or not', () => {
    const { pause } = setup();

    assert.equal(pause.swapped(), false);

    // Nothing recorded yet (consent given on a later page): the page is still not a page load.
    pause.navigating(false);
    assert.equal(pause.swapped(), true);

    // A navigated alone (first load, fragment restore) is not a swap.
    const other = setup().pause;

    other.navigated();
    assert.equal(other.swapped(), false);
});

/** A console plugin as rrweb's: the observer patches, its return value restores. */
function fakePlugin() {
    const state = { installs: 0, restores: 0, report: null };
    const plugin = {
        name: 'rrweb/console@1',
        options: { level: ['error'] },
        observer(callback, win, options) {
            state.installs++;
            state.report = callback;
            state.win = win;
            state.options = options;

            return () => {
                state.restores++;
                state.report = null;
            };
        },
    };

    return { plugin, state };
}

test('the console plugin is installed once and keeps reporting through the pause', () => {
    const top = {};
    const { plugin, state } = fakePlugin();
    const idle = [];
    const runs = [[], []];
    const lasting = lastingPlugin(plugin, (payload) => idle.push(payload), top);

    assert.equal(lasting.plugin.name, 'rrweb/console@1');
    assert.deepEqual(lasting.plugin.options, { level: ['error'] });

    // record(): rrweb runs the observer.
    const stopFirst = lasting.plugin.observer((payload) => runs[0].push(payload), top, plugin.options);

    state.report('a');

    // The pause: rrweb stops, the console stays patched and reports to idle.
    stopFirst();
    state.report('b');

    // record() again: the same patch, the new run.
    const stopSecond = lasting.plugin.observer((payload) => runs[1].push(payload), top, plugin.options);

    state.report('c');

    assert.equal(state.installs, 1);
    assert.equal(state.restores, 0);
    assert.deepEqual(runs, [['a'], ['c']]);
    assert.deepEqual(idle, ['b']);

    // The recording stops for good: the console is put back once.
    stopSecond();
    lasting.stop();
    lasting.stop();

    assert.equal(state.restores, 1);
});

test('the console plugin of another window (a same-origin iframe) runs as rrweb runs it', () => {
    const top = {};
    const frame = {};
    const { plugin, state } = fakePlugin();
    const lasting = lastingPlugin(plugin, () => {}, top);

    lasting.plugin.observer(() => {}, top, plugin.options);
    const stopFrame = lasting.plugin.observer(() => {}, frame, plugin.options);

    assert.equal(state.installs, 2);
    assert.equal(state.win, frame);

    stopFrame();
    assert.equal(state.restores, 1);
});
