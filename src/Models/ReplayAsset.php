<?php

namespace Packstub\SessionReplay\Models;

/**
 * A stylesheet or a shared snapshot, stored once under the SHA-256 of its content.
 *
 * @property string $hash
 * @property string $kind
 * @property string $path
 * @property int $bytes
 * @property int $raw_bytes
 */
class ReplayAsset extends ReplayModel
{
    public const STYLESHEET = 'stylesheet';

    public const SNAPSHOT = 'snapshot';

    protected $table = 'replay_assets';

    protected $attributes = ['kind' => self::STYLESHEET];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
