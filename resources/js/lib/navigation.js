/**
 * wire:navigate replaces the whole <body> instead of morphing it. Left to
 * rrweb, the swap becomes one mutation that adds every node of the new page,
 * which is larger than a full snapshot of it and slower to serialize. So the
 * recorder stops rrweb when Livewire is about to swap (livewire:navigating)
 * and starts it again once the new page is in place (livewire:navigated):
 * the swap is never recorded, and the new page arrives as a full snapshot,
 * the same as a page load.
 *
 * The new page also says whether it is recorded at all. The server renders
 * the recorder's config only on pages it records (except, except_routes,
 * recordWhen()); Livewire runs the new page's inline scripts during the swap,
 * so current() returns that page's config, or nothing for a page the app
 * leaves out. On such a page the recording stays paused, and what would be
 * recorded on it (markers, console messages) is dropped, until a later
 * navigation lands on a page with a config.
 *
 * This is the state of that pause, without the browser: pause() and
 * resume(config, paused) are the recorder's, timers are injected so the tests
 * can run them.
 *
 * - "navigated" without a "navigating" before it does nothing: Livewire also
 *   fires it on the first load and when it restores a fragment.
 * - resume(config, paused) is called for every new page with a config,
 *   paused telling whether rrweb was stopped for the swap and must start again.
 * - Between "navigating" and the new page, defer(fn) holds what would be
 *   recorded: run after resume() when the page has a config, dropped when not.
 * - If "navigated" never comes (a head script that never loads), the page is
 *   looked at after timeoutMs: a config resumes, none drops what was held and
 *   leaves the decision to a late "navigated".
 * - A second "navigating" during the pause keeps the one pause and timer.
 * - stop() ends the pause without resuming: the recording was stopped for good.
 * - swapped() tells whether Livewire swapped the page at least once, recorded
 *   or not: a snapshot from then on is not a page load (a recording started
 *   after a wire:navigate, with consent given on a later page, included).
 */
export function navigationPause({ pause, resume, current, setTimer = setTimeout, clearTimer = clearTimeout, timeoutMs = 5000 }) {
    let paused = false;
    let timer = null;
    let swapped = false;
    let awaiting = false;
    let excluded = false;
    let held = [];

    function clear() {
        if (timer !== null) clearTimer(timer);

        timer = null;
    }

    /** The new page is in place (navigated), or should be by now (the timeout). True when the recording was resumed. */
    function settle(final) {
        if (!awaiting) return false;

        const config = current();

        if (!config) {
            excluded = true;
            held = [];

            // Only Livewire's own "navigated" says the page is complete; after the timeout a late one may still bring a config.
            if (final) {
                awaiting = false;
                clear();
            } else {
                timer = null;
            }

            return false;
        }

        const wasPaused = paused;
        const run = held;

        awaiting = false;
        excluded = false;
        paused = false;
        held = [];
        clear();
        resume(config, wasPaused);

        for (const fn of run) fn();

        return wasPaused;
    }

    return {
        /** Livewire is about to swap the page; active says whether anything is being recorded. True when rrweb was paused. */
        navigating(active) {
            swapped = true;
            awaiting = true;

            let pausedNow = false;

            if (!paused && active && pause() !== false) {
                paused = true;
                pausedNow = true;
            }

            if (timer === null) timer = setTimer(() => settle(false), timeoutMs);

            return pausedNow;
        },
        navigated: () => settle(true),
        /** Hold fn until the new page is known, or drop it on a page the app leaves out. False: run it now. */
        defer(fn) {
            if (excluded) return true;
            if (!awaiting) return false;

            held.push(fn);

            return true;
        },
        stop() {
            clear();
            paused = false;
            held = [];
        },
        isPaused: () => paused,
        /** The page shown came with wire:navigate and the app does not record it. */
        isExcluded: () => excluded,
        /** Livewire swapped the page and the new one is not known yet. */
        isAwaiting: () => awaiting,
        swapped: () => swapped,
    };
}

/**
 * An rrweb record plugin (the console's) that outlives the pause. rrweb runs a
 * plugin's observer on every record() and undoes it on stop, so the console
 * would go unwatched while Livewire swaps the page (a console.error from the
 * new page's scripts lost, with its marker and trigger), and putting the
 * original console method back would also drop whatever another script
 * wrapped around it after the recorder started. Here the observer is
 * installed once, in the top window, and undone only by stop(); while rrweb
 * is stopped, what it reports goes to idle(payload) instead. Other windows
 * (same-origin iframes) get the plugin as rrweb would run it.
 */
export function lastingPlugin(plugin, idle, top) {
    let forward = null;
    let undo = null;

    return {
        plugin: {
            ...plugin,
            observer(callback, win, options) {
                if (win !== top) return plugin.observer(callback, win, options);

                forward = callback;

                if (!undo) undo = plugin.observer((payload) => (forward ? forward(payload) : idle(payload)), win, options);

                // rrweb stopping only unhooks it from that run.
                return () => {
                    if (forward === callback) forward = null;
                };
            },
        },
        stop() {
            const undoNow = undo;

            forward = null;
            undo = null;
            undoNow?.();
        },
    };
}
