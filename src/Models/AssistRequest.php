<?php

namespace Packstub\SessionReplay\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Packstub\SessionReplay\Support\ContextToken;

/**
 * A visitor asking for help, or an agent offering it from a live session: one
 * row for both directions (opened_by).
 *
 *   open     → claimed    an agent takes a visitor's request (one UPDATE where status = open)
 *   open     → active     the visitor accepts an agent's proposal (the call is created then)
 *   claimed  → active     the visitor's page acknowledges the claim on its next poll (the call is created then)
 *   claimed  → open       no acknowledgement within assist.claim_timeout_seconds, or the agent withdraws the claim
 *   open|claimed → cancelled   the visitor cancels, or the token names someone else
 *   open     → declined   "Not now" on an agent's proposal
 *   open|claimed → expired     a proposal unanswered, or a page that stopped polling
 *   open     → withdrawn  the agent takes the proposal back, or may not help any more when the visitor accepts
 *   claimed|active → ended     the call ended (with Calls' reason), the agent ended it, max_duration
 *
 * @property string $id
 * @property string|null $secret_hash
 * @property string|null $replay_session_id
 * @property string $status
 * @property string $opened_by
 * @property string|null $user_type
 * @property string|null $user_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $identity
 * @property string|null $nonce
 * @property string|null $lock_key
 * @property string|null $agent_id
 * @property string|null $agent_name
 * @property string|null $agent_avatar
 * @property int|null $call_id
 * @property string|null $message
 * @property string|null $page_url
 * @property array<int, array<string, mixed>>|null $grants
 * @property Carbon|null $visitor_seen_at
 * @property Carbon|null $agent_seen_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssistRequest extends ReplayModel
{
    use HasUuids;

    public const OPEN = 'open';

    public const CLAIMED = 'claimed';

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const WITHDRAWN = 'withdrawn';

    public const ENDED = 'ended';

    /** Not finished yet: at most one of these per recording (or per tab without one). */
    public const PENDING = [self::OPEN, self::CLAIMED, self::ACTIVE];

    public const TERMINAL = [self::CANCELLED, self::DECLINED, self::EXPIRED, self::WITHDRAWN, self::ENDED];

    public const BY_VISITOR = 'visitor';

    public const BY_AGENT = 'agent';

    /** What a visitor's browser may report in the grants log. */
    public const GRANTS = ['watch', 'control', 'audio', 'video', 'screen', 'control_masked'];

    /** What an agent may log asking for (the request side of the log, next to the visitor's answers). */
    public const ASKS = ['control', 'audio', 'video', 'screen', 'control_masked'];

    /** The agent's fields, cleared when a claim goes back to the list. */
    public const UNCLAIMED = ['agent_id' => null, 'agent_name' => null, 'agent_avatar' => null, 'claimed_at' => null, 'agent_seen_at' => null];

    /** The most entries the grants log keeps: a page reporting in a loop must not grow the row for ever. */
    public const MAX_GRANTS = 200;

    /** The longest message a visitor may send, in characters. */
    public const MAX_MESSAGE = 500;

    protected $table = 'replay_assist_requests';

    /** Proof of the tab, the identity hash and the keys: never in any JSON. */
    protected $hidden = ['secret_hash', 'identity', 'nonce', 'lock_key'];

    protected function casts(): array
    {
        return [
            'grants' => 'array',
            'call_id' => 'integer',
            'visitor_seen_at' => 'datetime',
            'agent_seen_at' => 'datetime',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReplaySession::class, 'replay_session_id');
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isPending();
    }

    public function openedByAgent(): bool
    {
        return $this->opened_by === self::BY_AGENT;
    }

    /** The id the visitor has on the Calls call: always this, also for a signed-in visitor. */
    public function guestId(): string
    {
        return 'guest:'.$this->id;
    }

    /** Whether this secret is the one issued for the request (compared in constant time). */
    public function acceptsSecret(mixed $secret): bool
    {
        return $this->secret_hash !== null && is_string($secret) && $secret !== '' && strlen($secret) <= 128
            && hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    /**
     * Who the token names, as a hash salted with the request's id (so two requests of one person cannot be linked by
     * it), or null for a visitor nobody signed in as. A pseudonym (privacy.anonymous) counts as a person: it is
     * never stored itself.
     */
    public function identityOf(ContextToken $context): ?string
    {
        if ($context->isVisitor() && $context->impersonatorId === null) {
            return null;
        }

        return hash_hmac('sha256', implode('|', [$context->userType, $context->userId, $context->pseudonym, $context->impersonatorType, $context->impersonatorId]), 'session-replay-assist|'.$this->getKey());
    }

    public function sameIdentityAs(ContextToken $context): bool
    {
        $identity = $this->identityOf($context);

        return $identity === null ? $this->identity === null : ($this->identity !== null && hash_equals($this->identity, $identity));
    }

    public function sameTenantAs(ContextToken $context): bool
    {
        return $context->sameTenantAs($this->tenant_type, $this->tenant_id);
    }

    /** What keeps a second pending request of the same recording (or tab) out. */
    public static function lockKeyFor(?string $sessionId, ?string $nonce): ?string
    {
        return $sessionId !== null ? $sessionId : ($nonce !== null ? 'nonce:'.$nonce : null);
    }

    /**
     * Move from one of these statuses to another in one UPDATE: two polls, two agents or a poll and the lifecycle at
     * the same moment cannot both make the same move. Returns whether this call made it; the model is refreshed.
     *
     * @param  array<int, string>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transition(array $from, string $to, array $attributes = []): bool
    {
        $updates = ['status' => $to, 'updated_at' => now(), ...$attributes];

        if (in_array($to, self::TERMINAL, true)) {
            $updates += ['ended_at' => now(), 'lock_key' => null];
        }

        $moved = static::query()->whereKey($this->getKey())->whereIn('status', $from)->update($updates) === 1;

        $this->refresh();

        return $moved;
    }

    /** @param  Builder<AssistRequest>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', self::PENDING);
    }

    /** Seconds since the visitor's page last polled (or since the request was made, if it never did). */
    public function visitorQuietFor(): int
    {
        return (int) max(0, ($this->visitor_seen_at ?? $this->created_at)->diffInSeconds(now()));
    }

    /** The page polled within assist.visitor_timeout_seconds. */
    public function visitorIsHere(): bool
    {
        return $this->visitor_seen_at !== null && $this->visitorQuietFor() < (int) config('session-replay.assist.visitor_timeout_seconds', 60);
    }

    /** Seconds since the request was made, for "waiting 2 min". */
    public function waitingFor(): int
    {
        return (int) max(0, $this->created_at->diffInSeconds(now()));
    }
}
