/**
 * wire:navigate replaces the whole <body> instead of morphing it. Left to
 * rrweb, the swap becomes one mutation that adds every node of the new page,
 * which is larger than a full snapshot of it and slower to serialize. So the
 * recorder stops rrweb when Livewire is about to swap (livewire:navigating)
 * and starts it again once the new page is in place (livewire:navigated):
 * the swap is never recorded, and the new page arrives as a full snapshot,
 * the same as a page load.
 *
 * This is the state of that pause, without the browser: pause() and resume()
 * are the recorder's, timers are injected so the tests can run them.
 *
 * - "navigated" without a "navigating" before it does nothing: Livewire also
 *   fires it on the first load and when it restores a fragment.
 * - If "navigated" never comes (a head script that never loads), the
 *   recording resumes after timeoutMs, the new page or not.
 * - A second "navigating" during the pause keeps the one pause and timer.
 * - stop() ends the pause without resuming: the recording was stopped for good.
 * - swapped() tells whether Livewire swapped the page at least once, recorded
 *   or not: a snapshot from then on is not a page load (a recording started
 *   after a wire:navigate, with consent given on a later page, included).
 */
export function navigationPause({ pause, resume, setTimer = setTimeout, clearTimer = clearTimeout, timeoutMs = 5000 }) {
    let paused = false;
    let timer = null;
    let swapped = false;

    function end() {
        if (timer !== null) clearTimer(timer);

        timer = null;
        paused = false;
    }

    function wake() {
        if (!paused) return false;

        end();
        resume();

        return true;
    }

    return {
        /** Livewire is about to swap the page; active says whether anything is being recorded. */
        navigating(active) {
            swapped = true;

            if (paused || !active) return false;

            if (pause() === false) return false;

            paused = true;
            timer = setTimer(wake, timeoutMs);

            return true;
        },
        /** The new page is in place. True when the recording was resumed. */
        navigated: wake,
        stop: end,
        isPaused: () => paused,
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
