<?php

namespace Packstub\SessionReplay\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recording pointing at a shared snapshot by hash.
 *
 * @property string $replay_session_id
 * @property string $hash
 */
class ReplaySessionAsset extends ReplayModel
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $table = 'replay_session_assets';

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReplaySession::class, 'replay_session_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ReplayAsset::class, 'hash', 'hash');
    }
}
