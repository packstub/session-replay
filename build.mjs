import { build, context } from 'esbuild';

/**
 * Builds resources/js into resources/dist: recorder.js (loaded on recorded
 * pages, so it stays small and has no CSS), assist.js (an ES module the
 * recorder imports only when Assist is needed: the calls engine and the
 * visitor's UI), player.js + player.css (loaded only where a replay is
 * watched) and stage.js + stage.css (the agent's live stage: rrweb's Replayer
 * in live mode and the calls engine). The dist files are committed; the
 * package serves them through a route, so an app never runs this.
 */
const shared = {
    bundle: true,
    minify: true,
    format: 'iife',
    target: ['es2020'],
    legalComments: 'none',
    logLevel: 'info',
};

const targets = [
    { ...shared, entryPoints: { recorder: 'resources/js/recorder.js' }, outdir: 'resources/dist' },
    { ...shared, entryPoints: { player: 'resources/js/player.js' }, outdir: 'resources/dist' },
    { ...shared, entryPoints: { stage: 'resources/js/stage.js' }, outdir: 'resources/dist' },
    { ...shared, format: 'esm', entryPoints: { assist: 'resources/js/assist.js' }, outdir: 'resources/dist' },
];

if (process.argv.includes('--watch')) {
    for (const target of targets) await (await context(target)).watch();
} else {
    for (const target of targets) await build(target);
}
