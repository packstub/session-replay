<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** Where the bytes live: gzip files on the configured disk, one folder per recording and one for stylesheets and shared snapshots. */
class ReplayStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('session-replay.storage.disk') ?: config('filesystems.default'));
    }

    public function directory(): string
    {
        return trim((string) config('session-replay.storage.directory', 'session-replay'), '/');
    }

    public function chunkPath(string $sessionId, int $seq): string
    {
        return sprintf('%s/sessions/%s/%06d.json.gz', $this->directory(), $sessionId, $seq);
    }

    public function assetPath(string $hash): string
    {
        return sprintf('%s/assets/%s/%s.css.gz', $this->directory(), substr($hash, 0, 2), $hash);
    }

    /** A shared snapshot: the node tree of a page, as JSON, next to the stylesheets. */
    public function snapshotPath(string $hash): string
    {
        return sprintf('%s/assets/%s/%s.json.gz', $this->directory(), substr($hash, 0, 2), $hash);
    }

    /** @return int bytes written */
    public function putChunk(string $sessionId, int $seq, string $gzip): int
    {
        $this->disk()->put($this->chunkPath($sessionId, $seq), $gzip);

        return strlen($gzip);
    }

    /** @return int bytes written */
    public function putAsset(string $hash, string $gzip): int
    {
        $this->disk()->put($this->assetPath($hash), $gzip);

        return strlen($gzip);
    }

    /** @return int bytes written */
    public function putSnapshot(string $hash, string $gzip): int
    {
        $this->disk()->put($this->snapshotPath($hash), $gzip);

        return strlen($gzip);
    }

    public function get(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    /** @return resource|null */
    public function readStream(string $path)
    {
        return $this->disk()->exists($path) ? $this->disk()->readStream($path) : null;
    }

    public function deleteSession(string $sessionId): void
    {
        $this->disk()->deleteDirectory($this->directory().'/sessions/'.$sessionId);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * What a browser sent, as gzip: bytes that already are gzip pass through
     * once they proved to inflate to something reasonable, plain JSON is
     * compressed here, so everything on disk has one shape. Null when the
     * payload is not valid gzip or inflates past the ceiling (a small upload
     * must not expand into all of the viewer's memory).
     */
    public function toGzip(string $body, int $maxRawBytes): ?string
    {
        if (! str_starts_with($body, "\x1f\x8b")) {
            return strlen($body) > $maxRawBytes ? null : (string) gzencode($body, 6);
        }

        return $this->inflate($body, $maxRawBytes) === null ? null : $body;
    }

    /**
     * Inflate with a ceiling. Only one complete gzip stream with nothing after
     * it is accepted: a second member or trailing bytes would be stored and
     * served as sent, and a browser that decodes every member would see more
     * than was checked here (a stylesheet other than its hash, a batch past the
     * ceiling). Small pieces keep the peak memory near the ceiling.
     */
    public function inflate(string $gzip, int $maxRawBytes): ?string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            return null;
        }

        $raw = '';

        foreach (str_split($gzip, 8192) as $piece) {
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                return null;
            }

            $inflated = @inflate_add($context, $piece);

            if ($inflated === false) {
                return null;
            }

            $raw .= $inflated;

            if (strlen($raw) > $maxRawBytes) {
                return null;
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== strlen($gzip)) {
            return null;
        }

        return $raw;
    }
}
