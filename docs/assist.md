# Assist

A visitor asks for help from the page they are on, and someone from support takes the request from a list. Or support sees a live recording and offers help. With [Calls for Laravel](https://github.com/packstub/calls) installed, the two are put on a call between the visitor's page and the agent; without it, the requests are a plain queue your app answers through its own channels.

> **Status.** This release ships the server side: the requests table, the routes for both sides, the timeouts, the events and a Requests page in the built-in viewer. The visitor's widget in the recorder and the live view of the visitor's page (the DOM mirror, audio, remote control) come in the next releases; until then a request is created through the HTTP API below.

## Turning it on

```env
SESSION_REPLAY_ASSIST=true
```

Assist is off by default: once on, every recorded page can open requests. Run `php artisan migrate` for the `replay_assist_requests` table.

Who may help is your gate's decision by default: whoever may watch a recording may help its person. To narrow it:

```php
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\AssistRequest;
use Packstub\SessionReplay\Models\ReplaySession;

SessionReplay::canAssistUsing(fn ($agent, ?ReplaySession $session, AssistRequest $request): bool => $agent->hasRole('support'));
```

`canAssist` is asked on every agent endpoint, not only when the list is drawn. `$session` is null for a request from a tab that records nothing; in a multi-tenant app, check `$request->tenant_id` there. Nobody assists their own visit: a request of the agent's own recording is refused whatever the rule says. The list also follows `SessionReplay::visibleUsing()`, like the recordings list.

### With Calls

Install [`packstub/calls`](https://github.com/packstub/calls) and Assist uses it on its own (`assist.calls` turns that off). The agent is the viewer guard's user, and Calls rings them by the same id, so in a plain Laravel app `session-replay.viewer.guard` and `packstub-calls.guard` must name the same guard (in Filament both are the panel's). Claiming a request refuses with a clear message when the two see different people, instead of creating a call the agent's browser never sees.

An assist call has the kind `assist`: the Calls widget, which polls for `kind=call`, never sees it. An agent helps one visitor at a time: starting another call ends the assist.

### Without Calls

A request goes `open`, then `claimed` by an agent, then `ended` by the agent. `AssistClaimed` is where your app reaches out (a chat message, an email). Offering help from a live recording needs Calls.

## How a request moves

| From → to | By | When |
| --- | --- | --- |
| `open` → `claimed` | agent | an agent takes a visitor's request; two agents cannot both get it |
| `open` → `active` | visitor | the visitor accepts an agent's offer; the call is created then |
| `claimed` → `active` | visitor's page | its next poll acknowledges the claim; the call is created then |
| `claimed` → `open` | timeout, agent | the page did not acknowledge within `assist.claim_timeout_seconds` (30), or the agent let go of the claim (the visitor still waits; someone else may take it) |
| `open`, `claimed` → `cancelled` | visitor | Cancel, or the page is signed in as someone else now |
| `open` → `declined` | visitor | "Not now" on an agent's offer |
| `open`, `claimed` → `expired` | timeout | an offer unanswered for `assist.proposal_timeout_seconds` (60), or a page that stopped polling for `assist.visitor_timeout_seconds` (60) |
| `open` → `withdrawn` | agent | the agent takes the offer back, or may no longer help (`canAssist` is asked again when the visitor accepts; `end_reason` `not_allowed`) |
| `claimed`, `active` → `ended` | agent, Calls, timeout | the agent ends it, the call ended (Calls' reason: `hung_up`, `dropped`…), the visitor hung up, the page went quiet, or `assist.max_duration_minutes` (120) passed |

A recording has at most one request going at a time (a tab without a recording, one per rendered page): asking again returns the one that is going. The timeouts run inside the visitor's poll and the agent's list, the way Calls expires calls inside its poll: no scheduler needed.

When a request finishes, the server puts an `assist` marker on the recording: how it was opened, how it ended, how long it ran and what the visitor allowed, never who the agent is. A browser cannot send an `assist` marker itself.

## The Requests page

`/session-replay/assist` (linked from the recordings list while Assist is on) lists the requests that wait and your own: the person, their message (shown as text), the page, how long they have waited, whether the page is still polling, and Claim, Withdraw and End buttons. It sits behind the viewer's middleware and gate, and the buttons post with the CSRF token.

## Events

| Event | When |
| --- | --- |
| `AssistRequested` | a visitor asked (`$request->opened_by === 'visitor'`) or an agent offered help (`'agent'`) |
| `AssistClaimed` | an agent took a visitor's request (`$request->agent_id`, `agent_name`) |
| `AssistStarted` | the call exists (`$request->call_id`) |
| `AssistEnded` | every finish: `$request->status`, `end_reason`, and `grants`, the log of what the visitor allowed |

Each carries the `AssistRequest` as `$event->request`.

## The visitor's routes

Stateless, like the upload endpoints: no session, cookie or CSRF token, throttled by `ingest.requests_per_minute` per person (for guests: per rendered page).

- A request is created with the recording's triple: `token` (the signed token the page was rendered with), `session` (the recording's id) and `key` (its write key). Only the browser that makes the recording can attach a request to it. A tab that records nothing sends the token alone.
- The answer carries a **secret** once: 32 hex characters, stored as its SHA-256 and compared in constant time. Every later call presents `secret` and `token`. The token keeps the throttle and lets the server refuse one that expired, belongs to another workspace, or now names someone else, which ends the request (`identity_changed`).
- An unknown id and a wrong secret get the same answer.

| Method | Route | Does |
| --- | --- | --- |
| `POST` | `assist/requests` `{token, session?, key?, message?, url?}` | create; 201 with `secret`, or 200 and `secret: null` for the request that is going; 429 past `requests_per_hour` or `max_open_requests` |
| `GET` | `assist/requests/{id}?secret&token&after&tab&hold` | the poll: `visitor_seen_at`, the timeouts, the call created on acknowledgement, and Calls' poll for the guest once a call exists. With `session` and `key` of the tab's new recording, the request moves to it (same person, same workspace). |
| `GET` | `assist/requests/{id}?token&session&key` | the first answer to an agent's offer, with the triple instead of a secret: the secret is issued in it, once (409 after) |
| `POST` | `assist/requests/{id}/accept` `{secret, token, tab}` | yes to an offer (or, with `watch_consent`, to the agent who claimed); creates the call |
| `POST` | `assist/requests/{id}/decline` | "Not now" on an offer |
| `POST` | `assist/requests/{id}/cancel` | stop waiting |
| `POST` | `assist/requests/{id}/hangup` | end a live assist |
| `POST` | `assist/requests/{id}/leave` `{tab}` | the unload beacon: the guest's side of the call is free for the tab's next page |
| `POST` | `assist/requests/{id}/signals` `{type, payload, to?}` | a WebRTC signal as the guest (`offer`, `answer`, `ice`, `reset`, `state`), capped at Calls' `max_signal_kb`; never trimmed |
| `POST` | `assist/requests/{id}/grants` `{kind, granted}` | the visitor's browser reports a grant or its revocation (`watch`, `control`, `audio`, `video`, `screen`, `control_masked`), added to the log with a timestamp |
| `POST` | `assist/heartbeat` `{token, session, key}` | a visible tab's sign of life: the request and the live list see it; answers `{assist: {id, agent} \| null}`, an agent's offer for the recording |

The upload endpoint's answer carries the same `assist` key while Assist is on, for the recording the batch was accepted for.

A poll answers:

```json
{
    "id": "5f0c…",
    "status": "active",
    "opened_by": "visitor",
    "agent": { "id": "12", "name": "Ana", "avatar": null },
    "consent": null,
    "end_reason": null,
    "call": { "id": 41, "kind": "assist", "status": "active", "peer": { "id": "12", "name": "Ana", "avatar": null } },
    "signals": [],
    "cursor": 0
}
```

`call`, `signals` and `cursor` are Calls' poll as the guest (`guest:<request id>`, also for a signed-in visitor); a page checks that `call.peer.id` equals `agent.id` before it connects. `consent` is `"watch"` while the visitor's Accept is what moves the request on. Nothing in it names anyone but the agent.

## The agent's routes

Behind `viewer.middleware` and the `viewSessionReplay` gate; every request-bound route asks `visibleUsing()` and `canAssist` again. JSON for a client of your own; the Requests page posts forms.

| Method | Route | Does |
| --- | --- | --- |
| `GET` | `assist/queue` | `{requests: [...]}`: the open and claimed requests you may see, and your own live ones |
| `GET` | `assist/requests/{id}/status` | one request; also marks you as seen while you hold it |
| `POST` | `assist/requests/{id}/claim` | take a visitor's request; 409 with `holder` when someone was faster |
| `POST` | `assist/sessions/{session}/propose` | offer help to the person making a live recording; 409 with `holder` when the recording has a request going, 422 when it is not live (no upload for two minutes) |
| `POST` | `assist/requests/{id}/withdraw` | take your offer back (it ends, `withdrawn`), or let go of your claim (it goes back to the list) |
| `POST` | `assist/requests/{id}/asks` `{what}` | log that you asked the visitor for `control`, `audio`, `video`, `screen` or `control_masked`, so the grants log shows what was asked next to what was allowed |
| `POST` | `assist/requests/{id}/end` | end it (and the call) |

A request as the agent sees it: `id`, `status`, `opened_by`, `session`, `session_url`, `user` (`id`, `label`), `message`, `page_url`, `agent` (`id`, `name`), `mine`, `call`, `visitor_here`, `visitor_seen_ago`, `waiting` (seconds), `grants`, `claimed_at`, `started_at`, `ended_at`, `end_reason`, `created_at`. Refusals are `{message, reason}`, `reason` one of `taken`, `exists`, `not_allowed`, `guard_mismatch`, `not_live`, `no_calls`, `state`.

## Privacy

- The visitor's routes take no identity but the signed token and the request's secret; the agent sees the page's URL with `privacy.redact_query` applied, and the message as text.
- The grants log is the compliance trail: what the agent asked for (`{kind, asked: true, by: "agent", at}`) and what the visitor allowed or took back (`{kind, granted, by: "visitor", at}`), and when. `AssistEnded` carries it; the `assist` marker summarises what was allowed on the recording.
- Calls deletes a call's signals when it ends; the package never logs a session description or a candidate.
- A request is deleted with its recording by `session-replay:prune`; one that never had a recording, after `retention.days`.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `assist.enabled` | `false` (`SESSION_REPLAY_ASSIST`) | The routes, the Requests page and the knock in the upload answer. |
| `assist.calls` | `true` | Use `packstub/calls` when it is installed. `false` keeps the queue alone. |
| `assist.watch_consent` | `false` | The visitor accepts before the agent joins, also for a request they opened. Accepting an offer always counts. |
| `assist.requests_per_hour` | `6` | New requests per person per hour (guests: per rendered page); `null` turns it off. |
| `assist.max_open_requests` | `100` | Requests going at once, everyone together; past it, 429. `null` turns it off. |
| `assist.claim_timeout_seconds` | `30` | A claim the page has not acknowledged goes back to the list. |
| `assist.proposal_timeout_seconds` | `60` | An offer nobody answered expires. |
| `assist.visitor_timeout_seconds` | `60` | A request whose page stopped polling expires; a live one ends. |
| `assist.max_duration_minutes` | `120` | An assist ends after this long. |
