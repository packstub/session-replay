import { drain } from '@packstub/calls';
import { AssistClient } from './lib/assist-client.js';
import { installAssist as install } from './lib/assist-visitor.js';

/**
 * The Assist module the recorder loads with import() (built to
 * resources/dist/assist.js): the visitor's code (lib/assist-visitor.js) with
 * the calls engine handed in. The visitor's code takes the engine by
 * injection, so its node tests run on a fake and never need the
 * @packstub/calls package.
 */
export function installAssist(recorder, env = {}) {
    return install(recorder, { Client: AssistClient, drain, ...env });
}
