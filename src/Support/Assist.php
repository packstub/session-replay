<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packstub\Calls\Calls;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Participant;
use Packstub\SessionReplay\Events\AssistClaimed;
use Packstub\SessionReplay\Events\AssistEnded;
use Packstub\SessionReplay\Events\AssistRequested;
use Packstub\SessionReplay\Events\AssistStarted;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
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

    public function enabled(): bool
    {
        return (bool) config('session-replay.assist.enabled', false);
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
     * @return array{0: AssistRequest, 1: ?string, 2: bool}
     *
     * @throws AssistRefused 429 over requests_per_hour or max_open_requests
     */
    public function request(ContextToken $context, ?ReplaySession $session, ?string $message, ?string $url): array
    {
        $lockKey = AssistRequest::lockKeyFor($session?->getKey(), $context->nonce);
        $existing = $lockKey === null ? null : AssistRequest::query()->pending()->where('lock_key', $lockKey)->first();

        if ($existing !== null) {
            return [$existing, $this->issueSecret($existing, $context), false];
        }

        $this->sweep();
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

        if ($request->status === AssistRequest::CLAIMED && $this->callsAvailable() && ! $this->watchConsent()) {
            $this->startCall($request, [AssistRequest::CLAIMED], $tab);
        }

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
        $claim = $request->status === AssistRequest::CLAIMED && ! $request->openedByAgent() && $this->watchConsent();

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
     * The requests this agent may see that wait or are theirs: open and claimed ones, and their own live ones.
     *
     * @return Collection<int, AssistRequest>
     */
    public function queue(Authenticatable $agent): Collection
    {
        $this->sweep();

        $me = (string) $agent->getAuthIdentifier();

        return AssistRequest::query()
            ->pending()
            ->where(fn ($query) => $query->whereIn('status', [AssistRequest::OPEN, AssistRequest::CLAIMED])->orWhere('agent_id', $me))
            ->where(fn ($query) => $query
                ->whereNull('replay_session_id')
                ->orWhereIn('replay_session_id', SessionReplay::visibleTo(ReplaySession::query(), $agent)->select('id')))
            ->with(['session', 'user'])
            ->oldest()
            ->limit(max(1, (int) config('session-replay.assist.max_open_requests', 100)) + 50)
            ->get()
            ->filter(fn (AssistRequest $request) => SessionReplay::canAssist($agent, $request->session, $request))
            ->values();
    }

    /**
     * Whether this agent may see and act on this request: the recording is in their list (visibleUsing()) and
     * canAssist() says yes. Asked on every agent endpoint.
     */
    public function visibleTo(AssistRequest $request, mixed $agent): bool
    {
        if (! $agent instanceof Authenticatable) {
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

        if (! SessionReplay::canAssist($agent, $session, $request)) {
            throw new AssistRefused(403, __('session-replay::viewer.assist.not_allowed'), 'not_allowed');
        }

        $this->assertSamePersonInCalls($agent);

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

        if ($request->isPending() && $request->agent_id === (string) $agent->getAuthIdentifier()) {
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

    /** The timeouts, over every request that is not finished. */
    public function sweep(): void
    {
        AssistRequest::query()->pending()->get()->each(fn (AssistRequest $request) => $this->expire($request));
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
                // The page never heard of the claim: someone else may take it.
                $request->transition([AssistRequest::CLAIMED], AssistRequest::OPEN, AssistRequest::UNCLAIMED);

                return $this->expire($request);
            }

            if ($quietSince->lt($now->copy()->subSeconds($visitorTimeout))) {
                return $this->finish($request, [AssistRequest::CLAIMED], AssistRequest::EXPIRED, 'visitor_timeout');
            }

            return $request->claimed_at?->lt($maxDuration) === true
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

    /** The CallEnded listener: the assist on that call ends with Calls' reason (hung_up, dropped…). */
    public function callEnded(Call $call): void
    {
        if ($call->kind !== self::KIND) {
            return;
        }

        AssistRequest::query()->pending()->where('call_id', $call->getKey())->get()
            ->each(fn (AssistRequest $request) => $this->finish($request, AssistRequest::PENDING, AssistRequest::ENDED, (string) ($call->end_reason ?? 'ended'), hangUp: false));
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
        }

        return [
            'id' => $request->id,
            'status' => $request->status,
            'opened_by' => $request->opened_by,
            'agent' => $request->agent_id === null ? null : $this->agentOf($request),
            // What the visitor's answer moves on: the widget asks "Accept / Not now".
            'consent' => $this->awaitsConsent($request) ? 'watch' : null,
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
        $me = $agent instanceof Authenticatable ? (string) $agent->getAuthIdentifier() : null;

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
            'mine' => $me !== null && $request->agent_id === $me,
            'call' => $request->call_id,
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
     * UPDATE from $from), and only the poll that moved it creates the call. The guest answers at once (the visitor
     * asked, or just accepted).
     *
     * @param  array<int, string>  $from
     */
    protected function startCall(AssistRequest $request, array $from, ?string $tab): bool
    {
        try {
            $started = $this->connection()->transaction(function () use ($request, $from, $tab): bool {
                if (! $request->transition($from, AssistRequest::ACTIVE, ['started_at' => now()])) {
                    return false;
                }

                $calls = app(Calls::class);
                $agent = $request->agent_id === null ? null : $calls->findUser($request->agent_id);

                if ($agent === null) {
                    throw new AssistRefused(409, 'The agent is not there any more.', 'state');
                }

                $call = $calls->start($agent, new Participant($request->guestId(), __('session-replay::viewer.assist.visitor')), kind: self::KIND);
                $calls->answer($call, $request->guestId(), $tab);

                AssistRequest::query()->whereKey($request->getKey())->update(['call_id' => $call->getKey()]);

                return true;
            });
        } catch (Throwable $exception) {
            $request->refresh();
            $this->finish($request, [AssistRequest::OPEN, AssistRequest::CLAIMED, AssistRequest::ACTIVE], AssistRequest::ENDED, 'call_failed');
            report($exception);

            return false;
        }

        $request->refresh();

        if ($started) {
            AssistStarted::dispatch($request);
        }

        return $started;
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
        if ($this->callsAvailable() && ! $this->watchConsent()) {
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

        return $request->status === AssistRequest::CLAIMED && $this->watchConsent() && $this->callsAvailable();
    }

    protected function watchConsent(): bool
    {
        return (bool) config('session-replay.assist.watch_consent', false);
    }

    /** @throws AssistRefused */
    protected function assertUnderLimits(ContextToken $context): void
    {
        $max = config('session-replay.assist.max_open_requests', 100);

        if ($max && AssistRequest::query()->pending()->count() >= (int) $max) {
            throw new AssistRefused(429, 'Too many people are waiting for help right now.', 'full');
        }

        $perHour = config('session-replay.assist.requests_per_hour', 6);

        if (! $perHour) {
            return;
        }

        $key = 'session-replay|assist|'.now()->format('Y-m-d-H').'|'.$context->throttleKey();

        Cache::add($key, 0, now()->addHour());

        if ((int) Cache::increment($key) > (int) $perHour) {
            throw new AssistRefused(429, 'Too many requests for help; try again later.', 'too_many');
        }
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

    /** @throws AssistRefused */
    protected function assertHolder(Authenticatable $agent, AssistRequest $request): void
    {
        if ($request->agent_id !== (string) $agent->getAuthIdentifier()) {
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
     * @return array{agent_id: string, agent_name: string, agent_avatar: ?string}
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

        return ['agent_id' => (string) $agent->getAuthIdentifier(), 'agent_name' => $name, 'agent_avatar' => $avatar];
    }

    /**
     * The agent of a request as the viewer guard knows them (agent_id is that guard's key), or Calls' user for the
     * id when the guard has no user provider.
     */
    protected function agentModel(AssistRequest $request): ?Authenticatable
    {
        if ($request->agent_id === null) {
            return null;
        }

        $guard = Auth::guard(config('session-replay.viewer.guard'));
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        if ($provider instanceof UserProvider) {
            return $provider->retrieveById($request->agent_id);
        }

        return $this->callsAvailable() ? app(Calls::class)->findUser($request->agent_id) : null;
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
     * what the visitor allowed. Never who the agent is. Written by the server, so a browser cannot make one up.
     */
    protected function mark(AssistRequest $request): void
    {
        if ($request->replay_session_id === null || ! ReplaySession::query()->whereKey($request->replay_session_id)->exists()) {
            return;
        }

        $granted = array_values(array_unique(array_column(array_filter((array) $request->grants, fn (mixed $grant): bool => is_array($grant) && ($grant['granted'] ?? false) === true), 'kind')));

        ReplayMarker::query()->insert([
            'replay_session_id' => $request->replay_session_id,
            'type' => ReplayMarker::ASSIST,
            'label' => Str::limit(($request->openedByAgent() ? 'Help offered' : 'Help requested').': '.$request->status.($request->end_reason ? ' ('.$request->end_reason.')' : ''), 480),
            'payload' => json_encode(array_filter([
                'opened_by' => $request->opened_by,
                'status' => $request->status,
                'reason' => $request->end_reason,
                'durationMs' => $request->started_at === null ? null : (int) $request->started_at->diffInMilliseconds($request->ended_at ?? now()),
                'granted' => $granted === [] ? null : implode(', ', $granted),
            ], fn (mixed $value): bool => $value !== null)),
            'at_ms' => now()->getTimestampMs(),
            'created_at' => now(),
        ]);
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
