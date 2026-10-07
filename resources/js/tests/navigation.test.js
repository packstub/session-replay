import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lastingPlugin, navigationPause } from '../lib/navigation.js';

function setup(options = {}) {
    const calls = [];
    const timers = new Map();
    // What the page Livewire swapped in rendered: a config, or null for a page the app does not record.
    const page = { config: 'config' in options ? options.config : { token: 'next' } };
    let next = 1;

    const pause = navigationPause({
        current: () => page.config,
        pause: () => {
            calls.push('pause');

            return options.pauseResult;
        },
        resume: (config, paused) => calls.push(paused ? 'resume' : 'config'),
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

    return { pause, calls, timers, fire, page };
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

test('resume gets the config the new page rendered', () => {
    const seen = [];
    const pause = navigationPause({ current: () => ({ token: 'second page' }), pause: () => {}, resume: (config, paused) => seen.push([config.token, paused]), setTimer: () => 1, clearTimer: () => {} });

    pause.navigating(true);
    pause.navigated();

    assert.deepEqual(seen, [['second page', true]]);
});

test('navigated without navigating does nothing (first load, fragment restore)', () => {
    const { pause, calls } = setup();

    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, []);
});

test('nothing pauses when nothing is recorded, but the new page config is still taken', () => {
    const { pause, calls } = setup();

    assert.equal(pause.navigating(false), false);
    assert.equal(pause.isPaused(), false);
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['config']);
});

test('a pause the recorder refuses does not resume', () => {
    const { pause, calls } = setup({ pauseResult: false });

    assert.equal(pause.navigating(true), false);
    assert.equal(pause.isPaused(), false);
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['pause', 'config']);
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
    const { pause, calls, timers } = setup();

    pause.navigating(true);
    pause.stop();

    assert.equal(pause.isPaused(), false);
    assert.equal(timers.size, 0);

    // The new page still hands over its config, for a later start; rrweb is not started again.
    assert.equal(pause.navigated(), false);
    assert.deepEqual(calls, ['pause', 'config']);
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

test('a page without a config stays paused: the app does not record it', () => {
    const { pause, calls, timers, page } = setup({ config: null });

    pause.navigating(true);
    assert.equal(pause.navigated(), false);

    assert.equal(pause.isPaused(), true);
    assert.equal(pause.isExcluded(), true);
    assert.equal(pause.isAwaiting(), false);
    assert.equal(timers.size, 0);
    assert.deepEqual(calls, ['pause']);

    // The next page is recorded again: rrweb was already stopped, so no second pause, and it starts on that page.
    page.config = { token: 'next' };
    assert.equal(pause.navigating(true), false);
    assert.equal(pause.navigated(), true);

    assert.equal(pause.isExcluded(), false);
    assert.equal(pause.isPaused(), false);
    assert.deepEqual(calls, ['pause', 'resume']);
});

test('a page without a config is not resumed by the timeout either', () => {
    const { pause, calls, fire, page } = setup({ config: null });

    pause.navigating(true);
    fire();

    assert.equal(pause.isPaused(), true);
    assert.equal(pause.isExcluded(), true);
    assert.deepEqual(calls, ['pause']);

    // A late navigated looks again: a head script that took longer than the timeout wrote the config after all.
    page.config = { token: 'late' };
    assert.equal(pause.navigated(), true);
    assert.deepEqual(calls, ['pause', 'resume']);
});

test('what happens during the swap is held, then kept on a recorded page', () => {
    const { pause, calls } = setup();
    const ran = [];

    assert.equal(pause.defer(() => ran.push('before')), false);

    pause.navigating(true);
    assert.equal(pause.defer(() => ran.push('during')), true);
    assert.deepEqual(ran, []);

    pause.navigated();

    // After resume: the held work lands in the restarted recording.
    assert.deepEqual(calls, ['pause', 'resume']);
    assert.deepEqual(ran, ['during']);
    assert.equal(pause.defer(() => ran.push('after')), false);
});

test('what happens during the swap or on a page left out is dropped', () => {
    const { pause, page } = setup({ config: null });
    const ran = [];

    pause.navigating(true);
    pause.defer(() => ran.push('during'));
    pause.navigated();

    // On the page itself too: an error, a console message or a marker of a page that is not recorded.
    assert.equal(pause.defer(() => ran.push('on the page')), true);

    // Leaving it: until the next page is in place, what happens may still be the page left out (its teardown).
    page.config = { token: 'next' };
    pause.navigating(true);
    assert.equal(pause.defer(() => ran.push('swap away')), true);
    pause.navigated();

    assert.deepEqual(ran, []);
    assert.equal(pause.defer(() => ran.push('next page')), false);
});

test('stop drops what was held', () => {
    const { pause } = setup();
    const ran = [];

    pause.navigating(true);
    pause.defer(() => ran.push('during'));
    pause.stop();
    pause.navigated();

    assert.deepEqual(ran, []);
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
