import { boot } from './core.js';

/**
 * The bundle @sessionReplay loads: reads window.__sessionReplay (the config
 * the directive wrote into the page) and starts the recorder once per
 * document, as window.SessionReplay. With wire:navigate every page Livewire
 * swaps in writes its own config, which the recorder reads (pageConfig).
 * The recorder itself is core.js; standalone.js starts the same one for a
 * frontend on another origin.
 */
if (window.__sessionReplay && !window.SessionReplay) boot(window.__sessionReplay, { global: true, pageConfig: true });
