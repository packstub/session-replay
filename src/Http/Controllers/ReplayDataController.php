<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\ReplayStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the player loads. Every route here runs behind AuthorizeViewer with
 * the recording, so the component is protected wherever it is embedded.
 */
class ReplayDataController
{
    public function __construct(protected ReplayStorage $storage) {}

    /** GET {path}/{session}/manifest */
    public function manifest(ReplaySession $session): JsonResponse
    {
        $startedAt = $session->started_at?->getTimestampMs() ?? 0;

        return response()->json([
            'id' => $session->id,
            'startedAt' => $startedAt,
            'durationMs' => $session->durationMs(),
            'live' => $session->isLive(),
            'truncated' => $session->truncated,
            'chunks' => $session->chunks->map(fn ($chunk): array => [
                'seq' => $chunk->seq,
                'url' => SessionReplay::route('chunk', ['session' => $session->id, 'seq' => $chunk->seq]),
                'bytes' => $chunk->bytes,
                'from' => $chunk->from_ms,
                'to' => $chunk->to_ms,
            ])->all(),
            'assetUrl' => SessionReplay::route('asset', ['session' => $session->id, 'hash' => '__hash__']),
            'snapshotUrl' => SessionReplay::route('snapshot', ['session' => $session->id, 'hash' => '__hash__']),
            'markers' => $session->markers->map(fn ($marker): array => [
                'type' => $marker->type,
                'label' => $marker->label,
                'payload' => $marker->payload,
                'at' => $marker->at_ms,
                'offset' => max(0, $marker->at_ms - $startedAt),
            ])->all(),
        ]);
    }

    /** GET {path}/{session}/chunks/{seq} — the gzip file as stored; the browser inflates it. */
    public function chunk(ReplaySession $session, int $seq): StreamedResponse
    {
        $chunk = $session->chunks()->where('seq', $seq)->firstOrFail();

        return $this->gzip($chunk->path, 'application/json', $session->isLive() ? 'private, no-store' : 'private, max-age=3600');
    }

    /** GET {path}/{session}/assets/{hash} — a stylesheet taken out of the snapshots. */
    public function asset(ReplaySession $session, string $hash): StreamedResponse
    {
        // Stylesheets only: a shared snapshot is served by snapshot(), to a viewer of a recording that points at it.
        $asset = ReplayAsset::query()->where('hash', $hash)->where('kind', ReplayAsset::STYLESHEET)->firstOrFail();

        return $this->gzip($asset->path, 'text/css; charset=utf-8', 'private, max-age=86400');
    }

    /**
     * GET {path}/{session}/snapshots/{hash} — a shared page snapshot (JSON), only to a viewer of a
     * recording that points at it: the hash alone opens nothing.
     */
    public function snapshot(ReplaySession $session, string $hash): StreamedResponse
    {
        abort_unless($session->snapshotReferences()->where('hash', $hash)->exists(), 404);

        $asset = ReplayAsset::query()->where('hash', $hash)->firstOrFail();

        return $this->gzip($asset->path, 'application/json', 'private, max-age=86400');
    }

    protected function gzip(string $path, string $type, string $cache): StreamedResponse
    {
        $stream = $this->storage->readStream($path);

        abort_if($stream === null, 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $type,
            'Content-Encoding' => 'gzip',
            'Cache-Control' => $cache,
            'Vary' => 'Accept-Encoding',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
