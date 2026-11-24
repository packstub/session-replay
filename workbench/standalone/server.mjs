import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * The standalone lab: a page on another origin than the workbench that records with the npm build
 * (npm/dist/index.js, `npm run build`), the way a Vue or React app would.
 *
 *   composer serve                              # the workbench on http://127.0.0.1:8000
 *   npm run build && node workbench/standalone/server.mjs
 *
 * Open http://127.0.0.1:8000 once (it signs Ada in), then http://127.0.0.1:8001 (127.0.0.1, not localhost: that is
 * the origin the workbench lists). The recording shows up on http://127.0.0.1:8000/session-replay.
 */
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const port = Number(process.env.PORT || 8001);

const files = {
    '/': [join(root, 'workbench/standalone/index.html'), 'text/html; charset=utf-8'],
    '/dist/index.js': [join(root, 'npm/dist/index.js'), 'text/javascript; charset=utf-8'],
};

createServer(async (request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname;
    // Any other path is a route of the single-page app: the page itself, as an SPA's server answers it.
    const file = files[path] ?? (path.startsWith('/dist/') ? null : files['/']);

    if (!file) {
        response.writeHead(404).end('Not found');

        return;
    }

    try {
        response.writeHead(200, { 'Content-Type': file[1], 'Cache-Control': 'no-store' }).end(await readFile(file[0]));
    } catch {
        response.writeHead(500).end(`${file[0]} is missing: run npm run build first.`);
    }
}).listen(port, '127.0.0.1', () => console.log(`Standalone lab on http://127.0.0.1:${port}`));
