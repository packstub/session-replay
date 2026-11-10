import { CallClient } from '@packstub/calls';
import { workerTimers } from './worker-timers.js';

export { workerTimers };

/**
 * What both sides of Assist run the engine with: a CallClient whose poll loop
 * sits on a Web Worker timer. A hidden tab's own timers slow to once a minute,
 * which would lose the side of the call Calls holds for a window (5 s) and
 * look like a drop to the other side. The visitor's page (assist.js) and the
 * agent's stage (stage.js) both use it.
 */

/** The engine's client, its poll loop on the worker timer. `schedule` and `stop` are internal to the engine; this copy is bundled. */
export class AssistClient extends CallClient {
    constructor(options, timers) {
        super(options);
        this.timers = timers;
    }

    schedule(delay) {
        this.timers?.clear(this.timer);
        this.timer = null;

        if (!this.polling) return;

        this.timer = this.timers.set(() => this.tick(), delay ?? this.pollIntervals.active);
    }

    stop() {
        this.timers?.clear(this.timer);
        this.timer = null;
        super.stop();
    }
}
