<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Packstub\SessionReplay\Facades\SessionReplay;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET {path}/scripts/{file} — the built recorder and player, so nothing has to be published.
 *
 * @internal Wired by the package; not part of the public API.
 */
class ScriptController
{
    protected const FILES = [
        'recorder.js' => 'application/javascript; charset=utf-8',
        'player.js' => 'application/javascript; charset=utf-8',
        'player.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        $path = SessionReplay::scriptPath($file);

        abort_unless(is_file($path), 404);

        // The URL carries a version, so a year is safe.
        return response()->file($path, [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
