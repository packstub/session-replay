<?php

namespace Packstub\SessionReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Packstub\Calls\Exceptions\CallException;
use Packstub\Calls\Models\CallSignal;
use Packstub\SessionReplay\Exceptions\AssistRefused;
use Packstub\SessionReplay\Http\Concerns\ReadsRecorderToken;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Support\Assist;
use Packstub\SessionReplay\Support\ContextToken;

/**
 * The visitor's side of Assist, stateless like ingest: the signed token for
 * identity, workspace and throttling; the recording's triple (token, session,
 * write key) to create a request, re-bind it or answer an agent's knock; the
 * request's secret for everything after. No session, cookie or CSRF token.
 *
 * @internal Wired by the package; not part of the public API.
 */
class AssistController
{
    use ReadsRecorderToken;

    public function __construct(protected Assist $assist) {}

    /** POST {path}/assist/requests — token, session and key (optional: the recording), message, url, tab (the tab's own id). */
    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $session = $this->recording($request, $context);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        $message = $request->input('message');

        if ($message !== null && (! is_string($message) || mb_strlen($message) > AssistRequest::MAX_MESSAGE)) {
            return $this->refuse(422, 'The message is at most '.AssistRequest::MAX_MESSAGE.' characters.');
        }

        $url = $request->input('url');

        try {
            [$assist, $secret, $created] = $this->assist->request($context, $session === false ? null : $session, is_string($message) && trim($message) !== '' ? trim($message) : null, is_string($url) ? $url : null, $this->tab($request));
        } catch (AssistRefused $refused) {
            return $this->refused($refused);
        }

        return response()->json([
            ...$this->assist->presentForVisitor($assist),
            // Once: the tab keeps it (sessionStorage) and presents it from here on.
            'secret' => $secret,
        ], $created ? 201 : 200);
    }

    /**
     * POST {path}/assist/requests/{id} — secret, token, after, tab, hold (in the body, never the URL); session and key to re-bind the request to the
     * tab's new recording. An agent's proposal is answered without a secret, with the triple: the secret is issued in
     * that first answer, once.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $assist = AssistRequest::query()->find(strtolower($id));
        $secret = null;

        if ($assist !== null && $this->fromBody($request, 'secret') === null && $assist->openedByAgent()) {
            $issued = $this->issue($request, $context, $assist);

            if ($issued instanceof JsonResponse) {
                return $issued;
            }

            $secret = $issued;
        } else {
            $assist = $this->authenticate($request, $context, $assist);

            if ($assist instanceof JsonResponse) {
                return $assist;
            }

            $rebound = $this->rebind($request, $context, $assist);

            if ($rebound instanceof JsonResponse) {
                return $rebound;
            }
        }

        $tab = $this->tab($request);
        $poll = $this->assist->visitorPoll($assist, max(0, (int) $request->input('after', 0)), $tab, $request->input('hold') === null || $request->boolean('hold'));

        return response()->json($secret === null ? $poll : [...$poll, 'secret' => $secret]);
    }

    /** POST {path}/assist/requests/{id}/accept — secret, token, tab. */
    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, function (AssistRequest $assist) use ($request): void {
            $this->assist->accept($assist, $this->tab($request));
        });
    }

    /** POST {path}/assist/requests/{id}/decline — secret, token: "Not now" on an agent's proposal. */
    public function decline(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (AssistRequest $assist) => $this->assist->decline($assist));
    }

    /** POST {path}/assist/requests/{id}/cancel — secret, token. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (AssistRequest $assist) => $this->assist->cancel($assist));
    }

    /** POST {path}/assist/requests/{id}/hangup — secret, token. */
    public function hangup(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (AssistRequest $assist) => $this->assist->hangup($assist));
    }

    /** POST {path}/assist/requests/{id}/leave — secret, token, tab: the unload beacon, which never reads a body. */
    public function leave(Request $request, string $id): Response|JsonResponse
    {
        $assist = $this->visitor($request, $id);

        if ($assist instanceof JsonResponse) {
            return $assist;
        }

        $tab = $this->tab($request);

        if ($tab !== null) {
            $this->assist->leave($assist, $tab);
        }

        return response()->noContent();
    }

    /** POST {path}/assist/requests/{id}/signals — secret, token, type, payload (an object), to (optional). */
    public function signal(Request $request, string $id): JsonResponse
    {
        $assist = $this->visitor($request, $id);

        if ($assist instanceof JsonResponse) {
            return $assist;
        }

        if (! $this->assist->callsAvailable() || $assist->call_id === null) {
            return $this->refuse(409, 'The assist is not live.');
        }

        $type = $request->input('type');
        $payload = $request->input('payload');
        $to = $request->input('to');

        if (! in_array($type, CallSignal::TYPES, true) || ! is_array($payload) || ($to !== null && (! is_string($to) || strlen($to) > 64))) {
            return $this->refuse(422, 'A signal needs a known type and a payload object.');
        }

        try {
            $signal = $this->assist->signal($assist, $type, $payload, $to);
        } catch (AssistRefused $refused) {
            return $this->refused($refused);
        } catch (CallException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status);
        }

        return response()->json(['ok' => true, 'id' => $signal], 201);
    }

    /** POST {path}/assist/requests/{id}/grants — secret, token, kind (watch, control, audio, video, screen, control_masked), granted. */
    public function grants(Request $request, string $id): JsonResponse
    {
        $kind = $request->input('kind');
        $granted = $request->input('granted') === null ? null : filter_var($request->input('granted'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! in_array($kind, AssistRequest::GRANTS, true) || $granted === null) {
            return $this->refuse(422, 'A grant needs a known kind and whether it was granted.');
        }

        return $this->act($request, $id, fn (AssistRequest $assist) => $this->assist->grant($assist, $kind, $granted));
    }

    /**
     * POST {path}/assist/heartbeat — token, session, key. A visible tab's sign of life, with no events: the page's
     * request (and the live list) see it, and the answer carries an agent's proposal for the recording (the knock).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $session = $this->recording($request, $context);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        if ($session === false) {
            return response()->json(['ok' => true, 'assist' => null]);
        }

        $this->assist->heartbeat($session);

        return response()->json(['ok' => true, 'assist' => $this->assist->knock($session->getKey())]);
    }

    // ------------------------------------------------------------------

    /**
     * The recording the triple names, false when it names none (or one not stored yet: a tab in on_error mode, the
     * first batch on its way), or a refusal when it belongs to another browser.
     */
    protected function recording(Request $request, ContextToken $context): ReplaySession|false|JsonResponse
    {
        $id = $request->input('session');

        if ($id === null || $id === '') {
            return false;
        }

        if (! is_string($id) || ! Str::isUuid($id)) {
            return $this->refuse(422, 'The session is not a recording id.');
        }

        /** @var ReplaySession|null $session */
        $session = ReplaySession::query()->find(strtolower($id));

        if ($session === null) {
            return false;
        }

        if (! $this->makesRecording($session, $context, $this->writeKey($request))) {
            return $this->refuse(403, 'This recording belongs to someone else.');
        }

        return $session;
    }

    /**
     * The request the secret opens, for a token of the same workspace and the same person. A token that names
     * someone else ends the request (identity_changed): the page changed hands.
     */
    protected function authenticate(Request $request, ContextToken $context, ?AssistRequest $assist): AssistRequest|JsonResponse
    {
        // One answer for an unknown id and a wrong secret: nobody learns which requests exist.
        if ($assist === null || ! $assist->acceptsSecret($this->fromBody($request, 'secret'))) {
            return $this->refuse(403, 'Unknown request, or the wrong secret.');
        }

        if (! $assist->sameTenantAs($context)) {
            return $this->refuse(403, 'This request belongs to another workspace.');
        }

        if (! $assist->sameIdentityAs($context)) {
            $this->assist->identityChanged($assist);

            return response()->json(['ok' => false, 'message' => 'The page is signed in as someone else now.', 'status' => $assist->status, 'end_reason' => $assist->end_reason], 409);
        }

        return $assist;
    }

    protected function visitor(Request $request, string $id): AssistRequest|JsonResponse
    {
        $context = $this->context($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        return $this->authenticate($request, $context, AssistRequest::query()->find(strtolower($id)));
    }

    /** @param  callable(AssistRequest): void  $action */
    protected function act(Request $request, string $id, callable $action): JsonResponse
    {
        $assist = $this->visitor($request, $id);

        if ($assist instanceof JsonResponse) {
            return $assist;
        }

        try {
            $action($assist);
        } catch (AssistRefused $refused) {
            return $this->refused($refused);
        }

        return response()->json($this->assist->presentForVisitor($assist->refresh()));
    }

    /**
     * The secret of an agent's proposal, for the browser that makes the recording it was made for (the triple, the
     * same checks as a batch), issued once.
     */
    protected function issue(Request $request, ContextToken $context, AssistRequest $assist): string|JsonResponse
    {
        $session = $this->recording($request, $context);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        if ($session === false || $assist->replay_session_id !== $session->getKey()) {
            return $this->refuse(403, 'Unknown request, or the wrong secret.');
        }

        $secret = $this->assist->issueSecret($assist, $context);

        if ($secret === null) {
            return $this->refuse(409, 'The secret of this request was issued already.');
        }

        return $secret;
    }

    /** The tab's new recording, when the poll names one: the triple again, the same person and workspace. */
    protected function rebind(Request $request, ContextToken $context, AssistRequest $assist): ?JsonResponse
    {
        $session = $this->recording($request, $context);

        if ($session instanceof JsonResponse || $session === false) {
            return $session instanceof JsonResponse ? $session : null;
        }

        try {
            $this->assist->rebind($assist, $session);
        } catch (AssistRefused $refused) {
            return $this->refused($refused);
        }

        return null;
    }

    /** The window's id, as Calls takes it: letters, digits, dashes and underscores, up to 64. */
    protected function tab(Request $request): ?string
    {
        $tab = $request->input('tab');

        return is_string($tab) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tab) === 1 ? $tab : null;
    }

    protected function refused(AssistRefused $refused): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $refused->getMessage(), 'reason' => $refused->reason, ...$refused->extra], $refused->status);
    }
}
