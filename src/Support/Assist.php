<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packstub\Calls\Calls;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;
use Packstub\Calls\Participant;
use Packstub\SessionReplay\Events\AssistClaimed;
use Packstub\SessionReplay\Events\AssistEnded;
use Packstub\SessionReplay\Events\AssistRequested;
use Packstub\SessionReplay\Events\AssistStarted;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplayChunk;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;
use Throwable;

/**
 * Assist requests: a visitor asks for help, an agent claims it, or an agent
 * offers help from a live session; with packstub/calls installed the visitor's
 * page and the agent are put on a Calls call (kind "assist") when the page
 * acknowledges. The timeouts run inside the visitor's poll and the agent's
 * queue poll, the way Calls expires calls inside its poll: no scheduler.
 *
 * Everything about Calls is behind callsAvailable(): without the package a
 * request is a plain queue (open, claimed, ended by the agent).
 *
 * @internal Wired by the package; the routes and the Filament package use it.
 */
class Assist
{
    /** The Calls kind of an assist call, so the Calls widget (which polls kind=call) never sees it. */
    public const KIND = 'assist';

    /**
     * How recently the agent's stage must have polled the request's status (agent_seen_at) for the visitor's poll to
     * put them on a call: the stage polls every 2 s, so an agent who left the live view is not rung until they are back.
     */
    public const AGENT_PRESENT_SECONDS = 10;

    /**
     * How often the timeouts run over a workspace's pending requests at most: the queue is read every few seconds by
     * every agent (the panel's table, notifier and badge), and each request's own poll runs its timeouts anyway.
     */
    public const SWEEP_EVERY_SECONDS = 5;

    public function enabled(): bool
    {
        return (bool) config('session-replay.assist.enabled', false);
    }

    /** How often the agent's stage polls Calls during the call: Calls' own `poll.active` (ms), kept between 250 ms and 10 s. */
    public function callsPollMs(): int
    {
        $ms = config('packstub-calls.poll.active');

        return is_numeric($ms) ? (int) min(10_000, max(250, (int) $ms)) : 1000;
    }

    /** Whether Calls lets a call carry video (its `video` setting, through the context). */
    public function videoAllowed(): bool
    {
        return $this->callsAvailable() && app(CallContext::class)->hasVideo();
    }

    /** packstub/calls is installed and booted, and assist.calls leaves it on. */
    public function callsAvailable(): bool
    {
        return (bool) config('session-replay.assist.calls', true)
            && class_exists(Calls::class)
            && app()->bound(CallContext::class);
    }

    // ------------------------------------------------------------------
    // The visitor's side
    // ------------------------------------------------------------------

    /**
     * A visitor asks for help. Returns the request, the secret when this call issued it (once), and whether the
     * request is new: a recording (or a tab without one) with a request going gets that one back.
     *
     *
     * @param  ?string  $tab  the tab's own id (the recorder keeps one in sessionStorage): with the token's nonce, what
     *                        keeps one request per tab without a recording
     * @return array{0: AssistRequest, 1: ?string, 2: bool}
     *
     * @throws AssistRefused 403 for a guest Assist does not take, 429 over requests_per_hour, max_open_requests or
     *                       guest_requests_per_hour
     */
    public function request(ContextToken $context, ?ReplaySession $session, ?string $message, ?string $url, ?string $tab = null): array
    {
        $this->assertGuestMayAsk($context, $session);

        $lockKey = AssistRequest::lockKeyFor($session?->getKey(), $context->nonce, $tab);
        $existing = $lockKey === null ? null : AssistRequest::query()->pending()->where('lock_key', $lockKey)->first();

        if ($existing !== null) {
            return [$existing, $this->issueSecret($existing, $context), false];
        }

        $this->sweep([$context->tenantType, $context->tenantId]);
        $this->assertUnderLimits($context);

        $secret = self::newSecret();
        $request = (new AssistRequest)->forceFill(['id' => strtolower((string) Str::uuid())]);

        $request->forceFill([
            'secret_hash' => hash('sha256', $secret),
            'replay_session_id' => $session?->getKey(),
            'status' => AssistRequest::OPEN,
            'opened_by' => AssistRequest::BY_VISITOR,
            'user_type' => $context->userType,
            'user_id' => $context->userId,
            'tenant_type' => $context->tenantType,
            'tenant_id' => $context->tenantId,
            'identity' => $request->identityOf($context),
            'nonce' => $context->nonce === null ? null : Str::limit($context->nonce, 64, ''),
            'lock_key' => $lockKey,
            'message' => $message,
            'page_url' => $this->pageUrl($url),
            'visitor_seen_at' => now(),
        ]);

        try {
            $request->save();
        } catch (UniqueConstraintViolationException) {
            // The same tab asked twice at once: the other request is the one.
            $existing = AssistRequest::query()->pending()->where('lock_key', $lockKey)->firstOrFail();

            return [$existing, $this->issueSecret($existing, $context), false];
        }

        AssistRequested::dispatch($request);

        return [$request, $secret, true];
    }

    /**
     * The secret of an agent's proposal, issued to the first page that answers the knock with the recording's
     * triple (the caller checked it): set once, null when it was issued before.
     */
    public function issueSecret(AssistRequest $request, ContextToken $context): ?string
    {
        if ($request->secret_hash !== null) {
            return null;
        }

        $secret = self::newSecret();

        $issued = AssistRequest::query()->whereKey($request->getKey())->whereNull('secret_hash')->update([
            'secret_hash' => hash('sha256', $secret),
            'identity' => $request->identityOf($context),
            'nonce' => $context->nonce === null ? null : Str::limit($context->nonce, 64, ''),
            'updated_at' => now(),
        ]) === 1;

        $request->refresh();

        return $issued ? $secret : null;
    }

    /**
     * Move the request to the tab's new recording (an idle timeout or a navigation changed its id); the caller
     * checked the new triple.
     *
     * @throws AssistRefused 409 when that recording has a request of its own going
     */
    public function rebind(AssistRequest $request, ReplaySession $session): void
    {
        if ($request->replay_session_id === $session->getKey() || $request->isTerminal()) {
            return;
        }

        try {
            AssistRequest::query()->whereKey($request->getKey())->pending()->update([
                'replay_session_id' => $session->getKey(),
                'lock_key' => $session->getKey(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new AssistRefused(409, 'That recording has a request of its own.', 'exists');
        }

        $request->refresh();
    }

    /** The token now names someone else (or nobody): the request ends, whatever it was doing. */
    public function identityChanged(AssistRequest $request): void
    {
        $this->finish($request, [AssistRequest::OPEN, AssistRequest::CLAIMED], AssistRequest::CANCELLED, 'identity_changed')
            || $this->finish($request, [AssistRequest::ACTIVE], AssistRequest::ENDED, 'identity_changed');
    }

    /**
     * The visitor's poll: the page is here, the timeouts run, a claim is acknowledged (the call is created), and
     * once a call exists the page's Calls poll is made for it (hold: the page keeps the guest's side).
     *
     * @return array<string, mixed>
     */
    public function visitorPoll(AssistRequest $request, int $after = 0, ?string $tab = null, bool $hold = true): array
    {
        if ($request->isPending()) {
            AssistRequest::query()->whereKey($request->getKey())->update(['visitor_seen_at' => now()]);
            $request->refresh();
            $this->expire($request);
        }

        // The claim is acknowledged by creating the call, once the agent's stage is there (a claim made a moment ago,
        // or an agent back on the live view after leaving it). Back from leaving, no new consent is asked.
        if ($request->status === AssistRequest::CLAIMED && $this->callsAvailable() && (! $this->watchConsent() || $request->rejoining()) && $this->agentPresent($request)) {
            $this->startCall($request, [AssistRequest::CLAIMED], $tab);
        }

        $this->reconcile($request);

        return $this->presentForVisitor($request, $after, $tab, $hold);
    }

    /**
     * The visitor says yes: to an agent's proposal, or (with assist.watch_consent) to the agent who claimed their
     * request. The call is created here.
     *
     * @throws AssistRefused
     */
    public function accept(AssistRequest $request, ?string $tab = null): void
    {
        $proposal = $request->status === AssistRequest::OPEN && $request->openedByAgent();
        // A rejoin is not a consent question: the call waits for the agent's stage, never for an accept.
        $claim = $request->status === AssistRequest::CLAIMED && ! $request->rejoining() && ! $request->openedByAgent() && $this->watchConsent();

        if (! $proposal && ! $claim) {
            throw new AssistRefused(409, 'There is nothing to accept.', 'state');
        }

        if (! $this->callsAvailable()) {
            throw new AssistRefused(409, 'Live help needs packstub/calls.', 'no_calls');
        }

        // The agent's rights may have changed since they offered or claimed (a role taken away, the person removed):
        // asked again now, before the visitor's page is put on a call with them.
        $agent = $this->agentModel($request);

        if ($agent === null || ! SessionReplay::canAssist($agent, $request->session, $request)) {
            $proposal
                ? $this->finish($request, [AssistRequest::OPEN], AssistRequest::WITHDRAWN, 'not_allowed')
                : $request->transition([AssistRequest::CLAIMED], AssistRequest::OPEN, AssistRequest::UNCLAIMED);

            throw new AssistRefused(409, 'This person cannot help with this request any more.', 'not_allowed');
        }

        if (! $this->startCall($request, [$request->status], $tab)) {
            throw new AssistRefused(409, 'The request changed meanwhile.', 'state');
        }
    }

    /** @throws AssistRefused */
    public function decline(AssistRequest $request): void
    {
        if (! $request->openedByAgent() || ! $this->finish($request, [AssistRequest::OPEN], AssistRequest::DECLINED, 'declined')) {
            throw new AssistRefused(409, 'There is nothing to decline.', 'state');
        }
    }

    /** @throws AssistRefused */
    public function cancel(AssistRequest $request): void
    {
        if (! $this->finish($request, [AssistRequest::OPEN, AssistRequest::CLAIMED], AssistRequest::CANCELLED, 'cancelled')) {
            throw new AssistRefused(409, 'The request is not waiting any more.', 'state');
        }
    }

    /** The visitor hangs up. @throws AssistRefused */
    public function hangup(AssistRequest $request): void
    {
        if (! $this->finish($request, [AssistRequest::ACTIVE], AssistRequest::ENDED, 'hung_up')) {
            throw new AssistRefused(409, 'The assist is not live.', 'state');
        }
    }

    /** The unload beacon: the guest's side of the call is free for the next page of the tab. */
    public function leave(AssistRequest $request, string $tab): void
    {
        if ($request->call_id === null || ! $this->callsAvailable()) {
            return;
        }

        Call::query()->with('participants')->find($request->call_id)?->participant($request->guestId())?->release($tab);
    }

    /**
     * Queue a WebRTC signal as the guest (Calls::signal()).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AssistRefused when there is no live call
     */
    public function signal(AssistRequest $request, string $type, array $payload, ?string $to = null): ?int
    {
        $call = $this->liveCall($request);

        return app(Calls::class)->signal($call, $request->guestId(), $type, $payload, $to)?->getKey();
    }

    /**
     * The visitor's browser reports a grant or its revocation, for the request's log.
     *
     * @throws AssistRefused
     */
    public function grant(AssistRequest $request, string $kind, bool $granted): void
    {
        if (! in_array($request->status, [AssistRequest::CLAIMED, AssistRequest::ACTIVE], true)) {
            throw new AssistRefused(409, 'The assist is not live.', 'state');
        }

        $this->log($request, ['kind' => $kind, 'granted' => $granted, 'by' => 'visitor', 'at' => now()->toIso8601String()]);
    }

    /**
     * Append an entry to the request's grants log (capped at MAX_GRANTS, the oldest dropped).
     *
     * @param  array<string, mixed>  $entry
     */
    protected function log(AssistRequest $request, array $entry): void
    {
        $this->connection()->transaction(function () use ($request, $entry): void {
            $fresh = AssistRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $grants = array_slice((array) $fresh->grants, -(AssistRequest::MAX_GRANTS - 1));
            $grants[] = $entry;

            $fresh->forceFill(['grants' => $grants])->save();
        });

        $request->refresh();
    }

    /**
     * What the recorder hears about an agent's proposal (in the ingest response and the heartbeat): the open
     * proposal for this recording, or null.
     *
     * @return array{id: string, agent: array{id: ?string, name: ?string, avatar: ?string}}|null
     */
    public function knock(string $sessionId): ?array
    {
        $request = AssistRequest::query()->pending()->where('replay_session_id', $sessionId)->first();

        if ($request !== null) {
            $this->expire($request);
        }

        if ($request === null || $request->status !== AssistRequest::OPEN || ! $request->openedByAgent()) {
            return null;
        }

        // A recording from before write keys cannot answer (the visitor routes take it as no recording): no knock.
        if (! ReplaySession::query()->whereKey($sessionId)->whereNotNull('write_key')->exists()) {
            return null;
        }

        return ['id' => $request->id, 'agent' => $this->agentOf($request)];
    }

    /** The heartbeat: the page of this recording is here (visible), its request knows, and so does the live list. */
    public function heartbeat(ReplaySession $session): void
    {
        AssistRequest::query()->pending()->where('replay_session_id', $session->getKey())->update(['visitor_seen_at' => now()]);

        // Only forward: a batch with a later browser time may have moved it further.
        ReplaySession::query()->whereKey($session->getKey())
            ->where(fn ($query) => $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<', now()))
            ->update(['last_activity_at' => now()]);
    }

    // ------------------------------------------------------------------
    // The agent's side
    // ------------------------------------------------------------------

    /**
     * The requests this agent may see that wait or are theirs: open and claimed ones, and their own live ones. Only
     * the workspace the app's tenantUsing() names for the agent's request, and only $workspace when one is given (a
     * panel scoped to its tenant).
     *
     * @return Collection<int, AssistRequest>
     */
    public function queue(Authenticatable $agent, ?Model $workspace = null): Collection
    {
        $tenant = $this->agentTenant();
        $scopes = array_filter([$tenant, $workspace]);

        $this->sweep($scopes === [] ? null : [reset($scopes)->getMorphClass(), (string) reset($scopes)->getKey()]);

        return AssistRequest::query()
            ->pending()
            ->where(fn ($query) => $query->whereIn('status', [AssistRequest::OPEN, AssistRequest::CLAIMED])->orWhere(fn ($mine) => $this->whereHolder($mine, $agent)))
            ->when($tenant, fn ($query, Model $tenant) => $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', (string) $tenant->getKey()))
            ->when($workspace, fn ($query, Model $workspace) => $query->where('tenant_type', $workspace->getMorphClass())->where('tenant_id', (string) $workspace->getKey()))
            ->where(function ($query) use ($agent): void {
                // A request without a recording has nothing for visibleUsing() to look at: with a rule there, it is
                // shown only when canAssistUsing() decides (fail closed), or a viewer of one workspace would see another's.
                if (SessionReplay::assistsWithoutRecording()) {
                    $query->whereNull('replay_session_id');
                }

                $query->orWhereIn('replay_session_id', SessionReplay::visibleTo(ReplaySession::query(), $agent)->select('id'));
            })
            ->with(['session', 'user'])
            ->oldest()
            ->limit(max(200, (int) config('session-replay.assist.max_open_requests', 100) + 50))
            ->get()
            ->filter(fn (AssistRequest $request) => SessionReplay::canAssist($agent, $request->session, $request))
            ->values();
    }

    /**
     * Whether this agent may see and act on this request: the workspace the app's tenantUsing() gives for the agent's
     * request (when it gives one), the recording in their list (visibleUsing()), and canAssist(). A request without a
     * recording, while visibleUsing() narrows the list and no canAssistUsing() decides, is nobody's. Asked on every
     * agent endpoint.
     */
    public function visibleTo(AssistRequest $request, mixed $agent): bool
    {
        if (! $agent instanceof Authenticatable) {
            return false;
        }

        $tenant = $this->agentTenant();

        if ($tenant !== null && ($request->tenant_type !== $tenant->getMorphClass() || $request->tenant_id !== (string) $tenant->getKey())) {
            return false;
        }

        if ($request->replay_session_id === null && ! SessionReplay::assistsWithoutRecording()) {
            return false;
        }

        if ($request->replay_session_id !== null && ! SessionReplay::visibleTo(ReplaySession::query(), $agent)->whereKey($request->replay_session_id)->exists()) {
            return false;
        }

        return SessionReplay::canAssist($agent, $request->session, $request);
    }

    /**
     * An agent takes a visitor's request: one UPDATE where status = open, so two agents cannot both get it.
     *
     * @throws AssistRefused
     */
    public function claim(Authenticatable $agent, AssistRequest $request): void
    {
        if (! $this->visibleTo($request, $agent)) {
            throw new AssistRefused(403, __('session-replay::viewer.assist.not_allowed'), 'not_allowed');
        }

        $this->expire($request);

        if ($request->openedByAgent()) {
            throw new AssistRefused(409, 'This is an offer of help, not a request.', 'state');
        }

        $this->assertSamePersonInCalls($agent);
        $this->assertSameDatabaseAsCalls();

        $claimed = $request->transition([AssistRequest::OPEN], AssistRequest::CLAIMED, [
            ...$this->agentAttributes($agent),
            'claimed_at' => now(),
            'agent_seen_at' => now(),
        ]);

        if (! $claimed) {
            throw new AssistRefused(409, $request->agent_name !== null && $request->isPending()
                ? __('session-replay::viewer.assist.taken_by', ['name' => $request->agent_name])
                : __('session-replay::viewer.assist.not_open'), 'taken', ['holder' => $request->isPending() ? $request->agent_name : null]);
        }

        AssistClaimed::dispatch($request);
    }

    /**
     * An agent offers help to the person making a live recording. The page hears of it on its next upload or
     * heartbeat; the call is created when the visitor accepts.
     *
     * @throws AssistRefused
     */
    public function propose(Authenticatable $agent, ReplaySession $session): AssistRequest
    {
        if (! $this->callsAvailable()) {
            throw new AssistRefused(422, 'Offering help needs packstub/calls.', 'no_calls');
        }

        if (! $session->isLive()) {
            throw new AssistRefused(422, __('session-replay::viewer.assist.not_live'), 'not_live');
        }

        $existing = AssistRequest::query()->pending()->where('lock_key', $session->getKey())->first();

        if ($existing !== null && $this->expire($existing)) {
            $existing = null;
        }

        if ($existing !== null) {
            throw $this->exists($existing);
        }

        $request = (new AssistRequest)->forceFill([
            'id' => strtolower((string) Str::uuid()),
            'replay_session_id' => $session->getKey(),
            'status' => AssistRequest::OPEN,
            'opened_by' => AssistRequest::BY_AGENT,
            'user_type' => $session->user_type,
            'user_id' => $session->user_id,
            'tenant_type' => $session->tenant_type,
            'tenant_id' => $session->tenant_id,
            'lock_key' => $session->getKey(),
            'agent_seen_at' => now(),
            ...$this->agentAttributes($agent),
        ]);
        $request->setRelation('session', $session);

        if (! $this->visibleTo($request, $agent)) {
            throw new AssistRefused(403, __('session-replay::viewer.assist.not_allowed'), 'not_allowed');
        }

        $this->assertSamePersonInCalls($agent);
        $this->assertSameDatabaseAsCalls();

        try {
            $request->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->exists(AssistRequest::query()->pending()->where('lock_key', $session->getKey())->firstOrFail());
        }

        AssistRequested::dispatch($request);

        return $request;
    }

    /** The agent's status poll: the timeouts run, and the agent who holds it is seen. */
    public function agentPoll(Authenticatable $agent, AssistRequest $request): AssistRequest
    {
        $this->expire($request);

        if ($request->isPending() && $this->holds($agent, $request)) {
            AssistRequest::query()->whereKey($request->getKey())->update(['agent_seen_at' => now()]);
            $request->refresh();
        }

        return $request;
    }

    /**
     * The agent takes a proposal back (it ends, withdrawn) or lets go of a claim: the visitor still waits, so the
     * request goes back to the list for someone else.
     *
     * @throws AssistRefused
     */
    public function withdraw(Authenticatable $agent, AssistRequest $request): void
    {
        $this->assertHolder($agent, $request);

        $done = $request->openedByAgent()
            ? $this->finish($request, [AssistRequest::OPEN], AssistRequest::WITHDRAWN, 'withdrawn')
            : $request->transition([AssistRequest::CLAIMED], AssistRequest::OPEN, AssistRequest::UNCLAIMED);

        if (! $done) {
            throw new AssistRefused(409, __('session-replay::viewer.assist.not_open'), 'state');
        }
    }

    /**
     * The agent's side of the grants log: what they asked the visitor for (sent over the channel, which the server
     * never sees), so the row shows asked next to granted.
     *
     * @throws AssistRefused
     */
    public function ask(Authenticatable $agent, AssistRequest $request, string $what): void
    {
        $this->assertHolder($agent, $request);

        if (! in_array($request->status, [AssistRequest::CLAIMED, AssistRequest::ACTIVE], true)) {
            throw new AssistRefused(409, __('session-replay::viewer.assist.not_open'), 'state');
        }

        $this->log($request, ['kind' => $what, 'asked' => true, 'by' => 'agent', 'at' => now()->toIso8601String()]);
    }

    /**
     * The agent ends the assist (hangs up the call, if there is one).
     *
     * @throws AssistRefused
     */
    public function end(Authenticatable $agent, AssistRequest $request): void
    {
        $this->assertHolder($agent, $request);

        if (! $this->finish($request, [AssistRequest::CLAIMED, AssistRequest::ACTIVE], AssistRequest::ENDED, 'ended_by_agent')) {
            throw new AssistRefused(409, __('session-replay::viewer.assist.not_open'), 'state');
        }
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * The timeouts, over every request that is not finished, or those of one workspace ([tenant_type, tenant_id]; a
     * null pair is the requests without a workspace). At most once every SWEEP_EVERY_SECONDS per workspace (and for
     * all of them), across processes through the cache: the queue is read every few seconds by every agent.
     *
     * @param  array{0: ?string, 1: ?string}|null  $workspace
     */
    public function sweep(?array $workspace = null): void
    {
        $key = 'session-replay|assist-sweep|'.($workspace === null ? '*' : ($workspace[0] ?? '').':'.($workspace[1] ?? ''));

        if (! Cache::add($key, true, self::SWEEP_EVERY_SECONDS)) {
            return;
        }

        AssistRequest::query()->pending()
            ->when($workspace !== null, fn (Builder $query) => $query->where('tenant_type', $workspace[0])->where('tenant_id', $workspace[1]))
            ->get()
            ->each(fn (AssistRequest $request) => $this->expire($request));
    }

    /**
     * The timeouts of one request: a proposal nobody answered, a page that stopped polling, a claim the page never
     * acknowledged (back to open), an assist past max_duration. Returns whether the request is finished now.
     */
    public function expire(AssistRequest $request): bool
    {
        if ($request->isTerminal()) {
            return true;
        }

        $now = now();
        $quietSince = $request->visitor_seen_at ?? $request->created_at;
        $visitorTimeout = $this->seconds('visitor_timeout_seconds', 60);

        if ($request->status === AssistRequest::OPEN) {
            if ($request->openedByAgent()) {
                return $request->created_at->lt($now->copy()->subSeconds($this->seconds('proposal_timeout_seconds', 60)))
                    && $this->finish($request, [AssistRequest::OPEN], AssistRequest::EXPIRED, 'proposal_timeout');
            }

            return $quietSince->lt($now->copy()->subSeconds($visitorTimeout))
                && $this->finish($request, [AssistRequest::OPEN], AssistRequest::EXPIRED, 'visitor_timeout');
        }

        $maxDuration = $now->copy()->subMinutes(max(1, (int) config('session-replay.assist.max_duration_minutes', 120)));

        if ($request->status === AssistRequest::CLAIMED) {
            if (! $this->acknowledged($request) && $request->claimed_at?->lt($now->copy()->subSeconds($this->seconds('claim_timeout_seconds', 30)))) {
                // An agent's own offer whose agent left the live view and did not come back ends here: there is
                // nobody to hand it to, and it was never the visitor's request to put back in the list.
                if ($request->rejoining() && $request->openedByAgent()) {
                    return $this->finish($request, [AssistRequest::CLAIMED], AssistRequest::ENDED, 'agent_left');
                }

                // The page never heard of the claim, or the agent who left the live view did not come back in time:
                // someone else may take it.
                $request->transition([AssistRequest::CLAIMED], AssistRequest::OPEN, AssistRequest::UNCLAIMED);

                return $this->expire($request);
            }

            if ($quietSince->lt($now->copy()->subSeconds($visitorTimeout))) {
                return $this->finish($request, [AssistRequest::CLAIMED], AssistRequest::EXPIRED, 'visitor_timeout');
            }

            // Waiting for an agent who left the live view counts towards max_duration from the start of the assist.
            return ($request->started_at ?? $request->claimed_at)?->lt($maxDuration) === true
                && $this->finish($request, [AssistRequest::CLAIMED], AssistRequest::ENDED, 'max_duration');
        }

        // Active: Calls drops a side that stops polling (and the CallEnded listener ends the request), but only while
        // someone polls Calls; a page nobody has heard from past the visitor timeout is gone either way.
        if ($request->started_at?->lt($maxDuration) === true) {
            return $this->finish($request, [AssistRequest::ACTIVE], AssistRequest::ENDED, 'max_duration');
        }

        return $quietSince->lt($now->copy()->subSeconds($visitorTimeout))
            && $this->finish($request, [AssistRequest::ACTIVE], AssistRequest::ENDED, 'visitor_timeout');
    }

    /**
     * A live request whose Calls call is over (or gone) ends too, with Calls' reason: the CallEnded listener does this
     * as it happens, this catches what it missed (a call ended on another connection, a listener that failed).
     */
    public function reconcile(AssistRequest $request): void
    {
        if ($request->status !== AssistRequest::ACTIVE || $request->call_id === null || ! $this->callsAvailable()) {
            return;
        }

        $call = Call::query()->with('participants')->find($request->call_id);

        if ($call === null || $call->isEnded()) {
            $this->callOver($request, $call);
        }
    }

    /**
     * The CallEnded listener: the assist on that call ends with Calls' reason (hung_up, dropped…), unless only the
     * agent went away (callOver()).
     */
    public function callEnded(Call $call): void
    {
        if ($call->kind !== self::KIND) {
            return;
        }

        $call->load('participants');

        AssistRequest::query()->pending()->where('call_id', $call->getKey())->get()
            ->each(fn (AssistRequest $request) => $this->callOver($request, $call));
    }

    /**
     * The call of a live request is over. When Calls dropped the agent's side (they left the live view: navigated
     * away, closed the tab, lost the network for longer than packstub-calls.reconnect_timeout) while the visitor's
     * page was still on it, the request goes back to claimed without a call: the visitor's page says the agent is
     * reconnecting, and the visitor's poll makes a new call once the agent's stage polls again. An agent who does not
     * come back within claim_timeout_seconds lets the claim lapse, as an unanswered claim does. Anything else ends it.
     */
    protected function callOver(AssistRequest $request, ?Call $call): void
    {
        if ($call !== null && $request->status === AssistRequest::ACTIVE && $this->agentWentAway($request, $call)
            && AssistRequest::query()->whereKey($request->getKey())->where('status', AssistRequest::ACTIVE)->where('call_id', $call->getKey())
                ->update(['status' => AssistRequest::CLAIMED, 'call_id' => null, 'claimed_at' => now(), 'updated_at' => now()]) === 1) {
            $request->refresh();

            return;
        }

        $this->finish($request, AssistRequest::PENDING, AssistRequest::ENDED, (string) ($call?->end_reason ?? 'ended'), hangUp: false);
    }

    /** Calls ended the call because the agent's side stopped polling, and the visitor's side was still there. */
    protected function agentWentAway(AssistRequest $request, Call $call): bool
    {
        if ($call->end_reason !== 'dropped' || $request->agent_id === null) {
            return false;
        }

        return $call->participant($request->agent_id)?->status === CallParticipant::DROPPED
            && $call->participant($request->guestId())?->status === CallParticipant::JOINED;
    }

    /**
     * The agent's stage polled the request's status a moment ago: they are on the live view. Back from leaving it, the
     * poll must also be later than the drop (claimed_at was set then), to the second.
     */
    protected function agentPresent(AssistRequest $request): bool
    {
        return $request->agent_seen_at !== null
            && $request->agent_seen_at->gte(now()->subSeconds(self::AGENT_PRESENT_SECONDS))
            && (! $request->rejoining() || ($request->claimed_at !== null && $request->agent_seen_at->gt($request->claimed_at)));
    }

    /**
     * Move to a terminal status (one UPDATE, so once), hang up the call, put an "assist" marker on the recording and
     * tell the app (AssistEnded). Returns whether this call made the move.
     *
     * @param  array<int, string>  $from
     */
    public function finish(AssistRequest $request, array $from, string $status, string $reason, bool $hangUp = true): bool
    {
        if (! $request->transition($from, $status, ['end_reason' => Str::limit($reason, 32, '')])) {
            return false;
        }

        if ($hangUp && $request->call_id !== null && $this->callsAvailable()) {
            $call = Call::query()->find($request->call_id);

            if ($call !== null) {
                // Calls' end_reason is 16 characters.
                app(Calls::class)->end($call, Str::limit($reason, 16, ''));
            }
        }

        $this->mark($request);

        AssistEnded::dispatch($request);

        return true;
    }

    // ------------------------------------------------------------------
    // What each side is shown
    // ------------------------------------------------------------------

    /**
     * The visitor's view: their request, the agent's name and avatar, and once a call exists the page's Calls poll
     * (the call as the guest sees it, the signals for the guest and the cursor). Nobody else's identifiers.
     *
     * @return array<string, mixed>
     */
    public function presentForVisitor(AssistRequest $request, int $after = 0, ?string $tab = null, bool $hold = true): array
    {
        $poll = ['call' => null, 'signals' => [], 'cursor' => $after, 'rtc' => null];

        if ($request->call_id !== null && $this->callsAvailable()) {
            $calls = app(Calls::class)->poll($request->guestId(), $after, $request->call_id, $tab, $hold, self::KIND);
            // What the page's RTCPeerConnection needs (ICE servers, transport policy): only once there is a call.
            $poll = ['call' => $calls['call'], 'signals' => $calls['signals'], 'cursor' => $calls['cursor'], 'rtc' => $calls['call'] === null ? null : app(Calls::class)->rtcConfiguration()];

            // This very poll may have ended the call (Calls drops a side inside its poll): the page hears what became
            // of the request now, not on its next poll.
            if (($calls['call']['status'] ?? null) === Call::ENDED) {
                $request->refresh();
            }
        }

        return [
            'id' => $request->id,
            'status' => $request->status,
            'opened_by' => $request->opened_by,
            'agent' => $request->agent_id === null ? null : $this->agentOf($request),
            // What the visitor's answer moves on: the widget asks "Accept / Not now".
            'consent' => $this->awaitsConsent($request) ? 'watch' : null,
            // The agent left the live view and the page waits for them to come back.
            'reconnecting' => $request->rejoining(),
            'end_reason' => $request->end_reason,
            ...$poll,
        ];
    }

    /**
     * The agent's view of a request: its own fields, the person as the viewer labels them, and whether the page is
     * still polling.
     *
     * @return array<string, mixed>
     */
    public function presentForAgent(AssistRequest $request, mixed $agent): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'opened_by' => $request->opened_by,
            'session' => $request->replay_session_id,
            'session_url' => $request->session === null ? null : $request->session->url(),
            'user' => $request->user_id === null ? null : ['id' => $request->user_id, 'label' => $this->userLabel($request)],
            'message' => $request->message,
            'page_url' => $request->page_url,
            'agent' => $request->agent_id === null ? null : ['id' => $request->agent_id, 'name' => $request->agent_name],
            'mine' => $this->holds($agent, $request),
            'call' => $request->call_id,
            'reconnecting' => $request->rejoining(),
            'visitor_here' => $request->visitorIsHere(),
            'visitor_seen_ago' => $request->visitor_seen_at === null ? null : (int) max(0, $request->visitor_seen_at->diffInSeconds(now())),
            'waiting' => $request->waitingFor(),
            'grants' => $request->grants ?? [],
            'claimed_at' => $request->claimed_at?->toIso8601String(),
            'started_at' => $request->started_at?->toIso8601String(),
            'ended_at' => $request->ended_at?->toIso8601String(),
            'end_reason' => $request->end_reason,
            'created_at' => $request->created_at->toIso8601String(),
        ];
    }

    /** viewer.user_label of the person, #key without a model, "Guest" for nobody. */
    public function userLabel(AssistRequest $request): string
    {
        if ($request->user_id === null) {
            return __('session-replay::viewer.guest');
        }

        $label = $request->user instanceof Model ? $request->user->getAttribute((string) config('session-replay.viewer.user_label', 'email')) : null;

        return is_scalar($label) && (string) $label !== '' ? (string) $label : '#'.$request->user_id;
    }

    // ------------------------------------------------------------------

    /**
     * Put the visitor and the agent on a Calls call and mark the request active, once: the status moves first (one
     * UPDATE from $from), and only the poll that moved it creates the call, outside any transaction (Calls may sit on
     * another connection, where a rollback here would leave its call behind). A call that cannot be created, or that
     * the request no longer wants, is ended again. The guest answers at once (the visitor asked, or just accepted).
     *
     * This runs in the visitor's request, where no panel is active: the agent is loaded from what the claim or the
     * proposal stored (their type, guard and Calls scope), never through the default guard.
     *
     * @param  array<int, string>  $from
     */
    protected function startCall(AssistRequest $request, array $from, ?string $tab): bool
    {
        // Back from the agent leaving the live view, the assist keeps its start (max_duration, the marker's duration).
        $rejoining = $request->rejoining();

        if (! $request->transition($from, AssistRequest::ACTIVE, ['started_at' => $request->started_at ?? now()])) {
            return false;
        }

        $calls = app(Calls::class);
        $call = null;

        try {
            $agent = $this->agentModel($request);

            if ($agent === null) {
                throw new AssistRefused(409, 'The agent is not there any more.', 'state');
            }

            $call = $calls->start($agent, new Participant($request->guestId(), __('session-replay::viewer.assist.visitor')), kind: self::KIND, scope: $request->agent_scope ?? $calls->scope());
            $calls->answer($call, $request->guestId(), $tab);

            $attached = AssistRequest::query()->whereKey($request->getKey())->where('status', AssistRequest::ACTIVE)->whereNull('call_id')
                ->update(['call_id' => $call->getKey(), 'updated_at' => now()]) === 1;

            if (! $attached) {
                // The request finished while the call was being made (the visitor hung up, a timeout): nobody wants it.
                $calls->end($call, 'cancelled');
                $request->refresh();

                return false;
            }
        } catch (Throwable $exception) {
            if ($call !== null) {
                try {
                    $calls->end($call, 'call_failed');
                } catch (Throwable) {
                    // Calls' own timeouts end it.
                }
            }

            $request->refresh();
            $this->finish($request, [AssistRequest::ACTIVE], AssistRequest::ENDED, 'call_failed');
            report($exception);

            return false;
        }

        $request->refresh();

        if (! $rejoining) {
            AssistStarted::dispatch($request);
        }

        return true;
    }

    /** @throws AssistRefused */
    protected function liveCall(AssistRequest $request): Call
    {
        $call = $request->status === AssistRequest::ACTIVE && $request->call_id !== null && $this->callsAvailable()
            ? Call::query()->with('participants')->find($request->call_id)
            : null;

        if ($call === null) {
            throw new AssistRefused(409, 'The assist is not live.', 'state');
        }

        return $call;
    }

    /** The claim reached the page: with a call to create, only the call does that (the page creates it on its poll). */
    protected function acknowledged(AssistRequest $request): bool
    {
        if ($this->callsAvailable() && (! $this->watchConsent() || $request->rejoining())) {
            return false;
        }

        // Later than the claim, to the second: a poll in the same second may have come just before it.
        return $request->visitor_seen_at !== null && $request->claimed_at !== null && $request->visitor_seen_at->gt($request->claimed_at);
    }

    protected function awaitsConsent(AssistRequest $request): bool
    {
        if ($request->status === AssistRequest::OPEN) {
            return $request->openedByAgent();
        }

        return $request->status === AssistRequest::CLAIMED && ! $request->rejoining() && $this->watchConsent() && $this->callsAvailable();
    }

    protected function watchConsent(): bool
    {
        return (bool) config('session-replay.assist.watch_consent', false);
    }

    /**
     * Who may ask at all: a guest (nobody signed in) only with assist.guests on, and while guests have a budget of
     * their own (assist.guest_requests_per_hour), only from a recording that has events on the server: a script that
     * mints a token per page render must at least upload a recording first.
     *
     * @throws AssistRefused
     */
    protected function assertGuestMayAsk(ContextToken $context, ?ReplaySession $session): void
    {
        if (! $context->isVisitor()) {
            return;
        }

        if (! config('session-replay.assist.guests', true)) {
            throw new AssistRefused(403, 'Help requests need you to be signed in.', 'guests');
        }

        if (config('session-replay.assist.guest_requests_per_hour', 20) && ($session === null || (int) $session->event_count < 1)) {
            throw new AssistRefused(403, 'Help requests from guests need a recording.', 'no_recording');
        }
    }

    /**
     * The caps, per workspace (tenant_type / tenant_id of the token; no workspace is one of its own), so one
     * workspace's visitors cannot fill another's list: requests going at once (max_open_requests), new ones per
     * person or guest page (requests_per_hour), and all guests of the workspace together (guest_requests_per_hour).
     *
     * @throws AssistRefused
     */
    protected function assertUnderLimits(ContextToken $context): void
    {
        $max = config('session-replay.assist.max_open_requests', 100);

        if ($max && AssistRequest::query()->pending()->where('tenant_type', $context->tenantType)->where('tenant_id', $context->tenantId)->count() >= (int) $max) {
            throw new AssistRefused(429, 'Too many people are waiting for help right now.', 'full');
        }

        $hour = now()->format('Y-m-d-H');
        $perHour = config('session-replay.assist.requests_per_hour', 6);

        if ($perHour && $this->countUp('session-replay|assist|'.$hour.'|'.$context->throttleKey()) > (int) $perHour) {
            throw new AssistRefused(429, 'Too many requests for help; try again later.', 'too_many');
        }

        $guests = config('session-replay.assist.guest_requests_per_hour', 20);

        if ($context->isVisitor() && $guests && $this->countUp('session-replay|assist-guests|'.$hour.'|'.$context->tenantType.':'.$context->tenantId) > (int) $guests) {
            throw new AssistRefused(429, 'Too many requests for help; try again later.', 'too_many');
        }
    }

    /** One more in this hour's counter; the new count. */
    protected function countUp(string $key): int
    {
        Cache::add($key, 0, now()->addHour());

        return (int) Cache::increment($key);
    }

    /**
     * Under a database per tenant the visitor's request and the agent's panel run on different default connections:
     * Session Replay's storage.connection points both at the central database, and Calls must sit on the same one,
     * or the agent's panel polls a calls table the visitor's call never reaches.
     *
     * @throws AssistRefused
     */
    protected function assertSameDatabaseAsCalls(): void
    {
        $storage = config('session-replay.storage.connection');

        if (! $this->callsAvailable() || $storage === null || $storage === '') {
            return;
        }

        if (config('packstub-calls.connection') !== $storage) {
            throw new AssistRefused(409, __('session-replay::viewer.assist.connection_mismatch'), 'connection_mismatch');
        }
    }

    /**
     * The workspace of the agent's request as the app's tenantUsing() names it, or null (no rule, or the agent is in
     * no workspace): requests of other workspaces are out of their reach.
     */
    protected function agentTenant(): ?Model
    {
        return SessionReplay::tenantFor(request());
    }

    /**
     * The agent must be the same person to Calls as to the viewer: Calls rings the id the viewer guard gave, and the
     * agent's browser polls Calls as Calls' guard sees them. Two different guards would create a call the agent's
     * client never sees.
     *
     * @throws AssistRefused
     */
    protected function assertSamePersonInCalls(Authenticatable $agent): void
    {
        if (! $this->callsAvailable()) {
            return;
        }

        $calls = app(Calls::class);
        $id = (string) $agent->getAuthIdentifier();
        $found = $calls->currentUserId() === $id ? $calls->findUser($id) : null;

        if ($found === null || $found::class !== $agent::class) {
            throw new AssistRefused(409, __('session-replay::viewer.assist.guard_mismatch'), 'guard_mismatch');
        }
    }

    /**
     * Whether this agent holds the request (claimed or proposed it): the same key, the same type (Admin #12 is not
     * User #12) and the guard they claimed through, the one the viewer authenticates with now.
     */
    public function holds(mixed $agent, AssistRequest $request): bool
    {
        return $agent instanceof Authenticatable
            && $request->agent_id !== null
            && $request->agent_id === (string) $agent->getAuthIdentifier()
            && $request->agent_type === self::typeOf($agent)
            && $request->agent_guard === self::guardName();
    }

    /**
     * The requests this agent holds, as holds() asks it, for a query.
     *
     * @param  Builder<AssistRequest>  $query
     * @return Builder<AssistRequest>
     */
    public function whereHolder(Builder $query, Authenticatable $agent): Builder
    {
        $guard = self::guardName();

        return $query->where('agent_id', (string) $agent->getAuthIdentifier())
            ->where('agent_type', self::typeOf($agent))
            ->when($guard === null, fn (Builder $query) => $query->whereNull('agent_guard'), fn (Builder $query) => $query->where('agent_guard', $guard));
    }

    /** The guard the viewer authenticates agents with (viewer.guard, or the default), as a claim stores it. */
    protected static function guardName(): ?string
    {
        $guard = config('session-replay.viewer.guard') ?? Auth::getDefaultDriver();

        return is_string($guard) ? Str::limit($guard, 64, '') : null;
    }

    /** @throws AssistRefused */
    protected function assertHolder(Authenticatable $agent, AssistRequest $request): void
    {
        if (! $this->holds($agent, $request)) {
            throw new AssistRefused(403, __('session-replay::viewer.assist.not_yours'), 'not_allowed');
        }
    }

    protected function exists(AssistRequest $existing): AssistRefused
    {
        return new AssistRefused(409, $existing->agent_name !== null
            ? __('session-replay::viewer.assist.taken_by', ['name' => $existing->agent_name])
            : __('session-replay::viewer.assist.already_asked'), 'exists', ['id' => $existing->id, 'holder' => $existing->agent_name]);
    }

    /**
     * The agent as the visitor sees them, stored on the request when it is claimed or proposed: Calls' name and
     * avatar for them when Calls is there, so both say the same, or else the model's name.
     *
     * Also who they are to the place they acted from: the morph class of the viewer guard's user, that guard's name
     * and Calls' scope there, which the visitor's request (no panel, the default guard) uses to load and ring them.
     *
     * @return array{agent_id: string, agent_type: string, agent_guard: ?string, agent_scope: ?string, agent_name: string, agent_avatar: ?string}
     */
    protected function agentAttributes(Authenticatable $agent): array
    {
        $name = null;
        $avatar = null;

        if ($this->callsAvailable()) {
            $who = app(Calls::class)->who($agent);
            $name = $who['name'] ?? null;
            $avatar = $who['avatar'] ?? null;
        } elseif ($agent instanceof Model) {
            $name = $agent->getAttribute('name');
        }

        $name = is_scalar($name) && trim((string) $name) !== '' ? Str::limit(trim((string) $name), 200, '') : __('session-replay::viewer.assist.support');
        // The visitor's widget shows an avatar only as an http(s) image.
        $avatar = is_string($avatar) && preg_match('~^https?://~i', $avatar) === 1 && strlen($avatar) <= 2000 ? $avatar : null;

        return [
            'agent_id' => (string) $agent->getAuthIdentifier(),
            'agent_type' => self::typeOf($agent),
            'agent_guard' => self::guardName(),
            'agent_scope' => $this->callsAvailable() ? app(Calls::class)->scope() : null,
            'agent_name' => $name,
            'agent_avatar' => $avatar,
        ];
    }

    /**
     * The agent of a request, from what the claim or the proposal stored: the provider of the guard they acted through,
     * else their model by its morph class, and only someone of the stored type with the stored key. Never the default
     * guard of the request at hand (the visitor's, where no panel is active).
     */
    protected function agentModel(AssistRequest $request): ?Authenticatable
    {
        if ($request->agent_id === null || $request->agent_type === null) {
            return null;
        }

        $agent = null;

        if ($request->agent_guard !== null && is_array(config('auth.guards.'.$request->agent_guard))) {
            try {
                $guard = Auth::guard($request->agent_guard);
                $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
                $agent = $provider instanceof UserProvider ? $provider->retrieveById($request->agent_id) : null;
            } catch (Throwable) {
                $agent = null;
            }
        }

        if (! $agent instanceof Authenticatable || self::typeOf($agent) !== $request->agent_type) {
            $class = Relation::getMorphedModel($request->agent_type) ?? $request->agent_type;
            $agent = is_a($class, Model::class, true) && is_a($class, Authenticatable::class, true) ? $class::query()->find($request->agent_id) : null;
        }

        return $agent instanceof Authenticatable && self::typeOf($agent) === $request->agent_type && (string) $agent->getAuthIdentifier() === $request->agent_id
            ? $agent
            : null;
    }

    /** The morph class of a model, the class of anything else. */
    protected static function typeOf(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }

    /** @return array{id: ?string, name: ?string, avatar: ?string} */
    protected function agentOf(AssistRequest $request): array
    {
        return ['id' => $request->agent_id, 'name' => $request->agent_name, 'avatar' => $request->agent_avatar];
    }

    /** An http(s) URL with privacy.redact_query applied, like every URL the recorder sends; anything else is dropped. */
    protected function pageUrl(?string $url): ?string
    {
        if ($url === null || preg_match('~^https?://[^\s]+$~i', $url) !== 1 || strlen($url) > 2000) {
            return null;
        }

        return ServerErrors::redactUrls($url, (array) config('session-replay.privacy.redact_query', []));
    }

    /**
     * An "assist" marker on the recording the request ended on: how it was opened, how it ended, how long it ran and
     * what the visitor allowed, each with how long it was on ("control 2 min, audio 45 s"). Never who the agent is.
     * Written by the server, so a browser cannot make one up.
     */
    protected function mark(AssistRequest $request): void
    {
        if ($request->replay_session_id === null || ! ReplaySession::query()->whereKey($request->replay_session_id)->exists()) {
            return;
        }

        $end = $request->ended_at ?? now();
        $granted = [];

        foreach ($this->grantDurations($request, $end) as $kind => $ms) {
            $granted[] = $ms >= 1000 ? $kind.' '.self::humanDuration($ms) : $kind;
        }

        ReplayMarker::query()->insert([
            'replay_session_id' => $request->replay_session_id,
            'type' => ReplayMarker::ASSIST,
            'label' => Str::limit(($request->openedByAgent() ? 'Help offered' : 'Help requested').': '.$request->status.($request->end_reason ? ' ('.$request->end_reason.')' : ''), 480),
            'payload' => json_encode(array_filter([
                'opened_by' => $request->opened_by,
                'status' => $request->status,
                'reason' => $request->end_reason,
                'durationMs' => $request->started_at === null ? null : (int) $request->started_at->diffInMilliseconds($end),
                'granted' => $granted === [] ? null : Str::limit(implode(', ', $granted), 480),
            ], fn (mixed $value): bool => $value !== null)),
            'at_ms' => $this->recordingTime($request->replay_session_id),
            'created_at' => now(),
        ]);
    }

    /**
     * How long each thing the visitor allowed was on, in the order first allowed: from each grant to the next
     * revocation of the same kind, or to the end of the assist.
     *
     * @return array<string, int> kind => milliseconds
     */
    protected function grantDurations(AssistRequest $request, \DateTimeInterface $end): array
    {
        $since = [];
        $total = [];
        $endMs = (int) $end->format('Uv');

        foreach ((array) $request->grants as $grant) {
            if (! is_array($grant) || ($grant['by'] ?? null) !== 'visitor' || ! is_bool($grant['granted'] ?? null) || ! is_string($grant['kind'] ?? null)) {
                continue;
            }

            $kind = $grant['kind'];
            $at = is_string($grant['at'] ?? null) && strtotime($grant['at']) !== false ? strtotime($grant['at']) * 1000 : $endMs;

            if ($grant['granted']) {
                $total[$kind] ??= 0;
                $since[$kind] ??= $at;
            } elseif (isset($since[$kind])) {
                $total[$kind] += max(0, $at - $since[$kind]);
                unset($since[$kind]);
            }
        }

        foreach ($since as $kind => $at) {
            $total[$kind] += max(0, $endMs - $at);
        }

        return $total;
    }

    /** "45 s", "2 min", "1 h 5 min". */
    protected static function humanDuration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        if ($seconds < 60) {
            return $seconds.' s';
        }

        $minutes = (int) round($seconds / 60);

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }

    /**
     * Now on the recording's own clock: the browser's events carry its clock, the server's may differ. The offset is
     * read off the last chunk (its last event against when it arrived, upload time included, so the marker may land
     * a moment early, never before that event). The server's clock when no chunk is stored.
     */
    protected function recordingTime(string $sessionId): int
    {
        $now = now()->getTimestampMs();
        $last = ReplayChunk::query()->where('replay_session_id', $sessionId)->orderByDesc('seq')->first(['to_ms', 'created_at']);

        if ($last === null || $last->created_at === null) {
            return $now;
        }

        return max((int) $last->to_ms, $now + ((int) $last->to_ms - $last->created_at->getTimestampMs()));
    }

    protected function seconds(string $key, int $default): int
    {
        return max(1, (int) config('session-replay.assist.'.$key, $default));
    }

    protected function connection(): Connection
    {
        return DB::connection(config('session-replay.storage.connection'));
    }

    /** 32 hex characters from the system's CSPRNG. */
    protected static function newSecret(): string
    {
        return bin2hex(random_bytes(16));
    }
}
