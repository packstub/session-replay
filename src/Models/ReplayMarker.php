<?php

namespace Packstub\SessionReplay\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $replay_session_id
 * @property string $type
 * @property string $label
 * @property array<string, mixed>|null $payload
 * @property int $at_ms
 */
class ReplayMarker extends ReplayModel
{
    /**
     * "report" is the person's own SessionReplay.send() (a "report a problem" button); "custom" is the app's mark();
     * "assist" is written by the server when an assist request ends (a browser cannot send one).
     */
    public const TYPES = ['error', 'console', 'request', 'navigation', 'vital', 'rage-click', 'custom', 'report', 'assist'];

    public const REPORT = 'report';

    public const ASSIST = 'assist';

    public const UPDATED_AT = null;

    protected $table = 'replay_markers';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReplaySession::class, 'replay_session_id');
    }
}
