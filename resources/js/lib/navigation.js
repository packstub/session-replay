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
 */
export function navigationPause({ pause, resume, setTimer = setTimeout, clearTimer = clearTimeout, timeoutMs = 5000 }) {
    let paused = false;
    let timer = null;

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
    };
}
