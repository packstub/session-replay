<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;

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

    /** The file's bytes, or null when there is no such file (read once: an exists() first is a second round trip on S3). */
    public function get(string $path): ?string
    {
        try {
            return $this->disk()->get($path);
        } catch (UnableToReadFile|FileNotFoundException) {
            return null;
        }
    }

    /** @return resource|null */
    public function readStream(string $path)
    {
        try {
            return $this->disk()->readStream($path) ?: null;
        } catch (UnableToReadFile|FileNotFoundException) {
            return null;
        }
    }

    /** Whether the recording's files are gone; false keeps its row, so the next prune tries again instead of orphaning them. */
    public function deleteSession(string $sessionId): bool
    {
        try {
            return $this->disk()->deleteDirectory($this->directory().'/sessions/'.$sessionId);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            return $this->disk()->delete($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * What a browser sent, as gzip: bytes that already are gzip pass through
     * once they proved to inflate to something reasonable, plain JSON is
     * compressed here, so everything on disk has one shape. Null when the
     * payload is not valid gzip or inflates past the ceiling (a small upload
     * must not expand into all of the viewer's memory).
     *
     * @internal
     */
    public function toGzip(string $body, int $maxRawBytes): ?string
    {
        if (! str_starts_with($body, "\x1f\x8b")) {
            return strlen($body) > $maxRawBytes ? null : (string) gzencode($body, 6);
        }

        return $this->inflatedSize($body, $maxRawBytes) === null ? null : $body;
    }

    /**
     * Inflate with a ceiling. Only one complete gzip stream with nothing after
     * it is accepted: a second member or trailing bytes would be stored and
     * served as sent, and a browser that decodes every member would see more
     * than was checked here (a stylesheet other than its hash, a batch past the
     * ceiling). Small pieces keep the peak memory near the ceiling.
     *
     * @internal
     */
    public function inflate(string $gzip, int $maxRawBytes): ?string
    {
        $raw = '';

        return $this->walk($gzip, $maxRawBytes, function (string $piece) use (&$raw): void {
            $raw .= $piece;
        }) === null ? null : $raw;
    }

    /**
     * What the gzip inflates to, in bytes, by the same rules as inflate(), without keeping any of it: a batch is
     * stored as sent, so checking it must not cost its inflated size in memory (up to 60 MB per request).
     *
     * @internal
     */
    public function inflatedSize(string $gzip, int $maxRawBytes): ?int
    {
        return $this->walk($gzip, $maxRawBytes, fn () => null);
    }

    /** Inflates piece by piece, handing each to `$sink`; the inflated size, or null when the stream is refused. */
    protected function walk(string $gzip, int $maxRawBytes, callable $sink): ?int
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            return null;
        }

        $size = 0;

        for ($offset = 0, $length = strlen($gzip); $offset < $length; $offset += 8192) {
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                return null;
            }

            $inflated = @inflate_add($context, substr($gzip, $offset, 8192));

            if ($inflated === false) {
                return null;
            }

            $size += strlen($inflated);

            if ($size > $maxRawBytes) {
                return null;
            }

            $sink($inflated);
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== $length) {
            return null;
        }

        return $size;
    }
}
