# Assist

A visitor asks for help from the page they are on, and someone from support takes the request from a list. Or support sees a live recording and offers help. With [Calls for Laravel](https://github.com/packstub/calls) installed, the two are put on a call between the visitor's page and the agent; without it, the requests are a plain queue your app answers through its own channels.

Everything lives in this package: the requests and their routes, the "Get help" API and button on the visitor's page, and the agent's side in the built-in viewer (the Live tab, Offer help, the Requests page and the live stage). [Filament Session Replay](https://github.com/packstub/filament-session-replay/blob/main/docs/assist.md) brings the same into a panel and hosts the same stage ([Hosting the stage](#hosting-the-stage-in-a-page-of-your-own)).

**What the live view needs.** The queue works with this package alone. The live view (the call, the mirror, remote control, audio) runs on [Calls for Laravel](https://github.com/packstub/calls) (`packstub/calls`), and in a Filament panel also on [Filament Calls](https://github.com/packstub/filament-calls) (`packstub/filament-calls`). Both are released on their own schedule, so check their READMEs for how to install them today; Assist turns the live view on by itself once Calls is there.

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

`canAssist` is asked on every agent endpoint, not only when the list is drawn. Nobody assists their own visit: a request of the agent's own recording is refused whatever the rule says. The list also follows `SessionReplay::visibleUsing()`, like the recordings list.

### Several workspaces

- **Requests without a recording.** `$session` is null for a request from a tab that records nothing, so `visibleUsing()` has nothing to look at. While you have a `visibleUsing()` rule and no `canAssistUsing()` rule, those requests are shown to nobody and cannot be claimed: one workspace's agents never see another's visitors by accident. To take them, register `canAssistUsing()` and check `$request->tenant_type` and `$request->tenant_id` there.
- **The agent's workspace.** When the app registered `SessionReplay::tenantUsing()`, it is asked for the agent's own request too: if it names a workspace (the team in the URL, the panel's tenant), the agent sees and acts on that workspace's requests only, with or without a recording. When it names none (an operator console), the other rules decide alone.
- **Limits.** `assist.max_open_requests` counts per workspace, so a flood in one workspace never fills another's list.

### With Calls

Install [`packstub/calls`](https://github.com/packstub/calls) and Assist uses it on its own (`assist.calls` turns that off). The agent is the viewer guard's user, and Calls rings them by the same id, so in a plain Laravel app `session-replay.viewer.guard` and `packstub-calls.guard` must name the same guard (in Filament both are the panel's). Claiming a request refuses with a clear message when the two see different people (`guard_mismatch`), instead of creating a call the agent's browser never sees.

The call itself is created later, in the visitor's request, where no panel is active. So the claim (or the offer) stores who the agent is to the place they acted from: their model's morph class, the guard's name and Calls' scope there. The visitor's request loads the agent from those and starts the call in that scope, never through the app's default guard: an agent on a panel's `admin` guard is rung as that admin, and their panel's Calls poll sees the call.

With a database per tenant, `session-replay.storage.connection` points at the central database; set `packstub-calls.connection` to the same connection. The visitor's request and the agent's panel run on different default connections, and a call on either one's would never reach the other. While `storage.connection` is set and `packstub-calls.connection` differs, claiming and offering refuse (`connection_mismatch`).

An assist call has the kind `assist`: the Calls widget, which polls for `kind=call`, never sees it. An agent helps one visitor at a time: starting another call ends the assist.

**One call at a time per person, whatever its kind.** Calls keeps a person on a single call (one microphone, one camera), across kinds: starting a call leaves the one you are on. So an agent who is on a Calls widget call with a colleague leaves it when a visitor's page picks up their claim, and a colleague who rings an agent during an assist hears they are busy. Ask agents to hang up before they claim, or check `Calls::openCallsOf($agentId)` in your own UI first.

**ICE servers for both ends.** The visitor's page gets its ICE servers in its own request, outside any panel, so it reads Calls' config (`packstub-calls.ice_servers`, the `CALLS_TURN_*` variables), not a Filament panel's `->iceServers()`. Put a TURN server in the config, not only on the panel, or a visitor behind a strict NAT (and every visitor with `ice_transport_policy` set to `relay`) cannot connect. The visitor's browser receives those TURN credentials, like any caller's; mention the STUN or TURN provider in your privacy notice when it is a third party.

**Versions.** Calls for Laravel needs Laravel 12.69+ or 13.30+, and Filament Calls needs Filament 5.8.3+ (or 4.13.3+), which is more than Session Replay alone asks for.

### Without Calls

A request goes `open`, then `claimed` by an agent, then `ended` by the agent. `AssistClaimed` is where your app reaches out (a chat message, an email). Offering help from a live recording needs Calls.

## How a request moves

| From → to | By | When |
| --- | --- | --- |
| `open` → `claimed` | agent | an agent takes a visitor's request; two agents cannot both get it |
| `open` → `active` | visitor | the visitor accepts an agent's offer; the call is created then |
| `claimed` → `active` | visitor's page | its next poll acknowledges the claim while the agent's stage is open (it polled in the last 10 seconds); the call is created then |
| `active` → `claimed` | Calls | the agent left the live view for longer than `packstub-calls.reconnect_timeout` (15 s) and Calls dropped their side: the request stays the agent's, without a call, and the visitor's page says they are reconnecting ([Leaving the live view](#leaving-the-live-view)) |
| `claimed` → `open` | timeout, agent | the page did not acknowledge within `assist.claim_timeout_seconds` (30), the agent who left the live view did not come back within it, or the agent let go of the claim (the visitor still waits; someone else may take it) |
| `open`, `claimed` → `cancelled` | visitor | Cancel, or the page is signed in as someone else now |
| `open` → `declined` | visitor | "Not now" on an agent's offer |
| `open`, `claimed` → `expired` | timeout | an offer unanswered for `assist.proposal_timeout_seconds` (60), or a page that stopped polling for `assist.visitor_timeout_seconds` (60) |
| `open` → `withdrawn` | agent | the agent takes the offer back, or may no longer help (`canAssist` is asked again when the visitor accepts; `end_reason` `not_allowed`) |
| `claimed`, `active` → `ended` | agent, Calls, timeout | the agent ends it, the call ended (Calls' reason: `hung_up`, or `dropped` when the visitor's page went away), the visitor hung up, the page went quiet, or `assist.max_duration_minutes` (120, counted from the first start) passed |

A recording has at most one request going at a time (a tab without a recording, one per tab: the recorder keeps an id per tab in `sessionStorage` and sends it with the request, so pages served from a full-page cache, which share a nonce, still get one each): asking again returns the one that is going. The timeouts run inside the visitor's poll and the agent's list, the way Calls expires calls inside its poll: no scheduler needed. Over the list they run at most once every 5 seconds per workspace, however many agents have it open.

When a request finishes, the server puts an `assist` marker on the recording: how it was opened, how it ended, how long it ran and what the visitor allowed, with how long each was on ("control 2 min, audio 45 s"), never who the agent is. It sits on the recording's own clock (the browser's, read off its last upload), so it lines up with the events around it. A browser cannot send an `assist` marker itself.

## On the visitor's page

With Assist on, the recorder brings everything the visitor sees; nothing to add to your pages.

The recorder itself only carries a small hook (about 4 KB): the heartbeat, the offer in the upload answer and `SessionReplay.assist`. The rest, the calls engine and the visitor's panel, is a separate script (`{path}/scripts/assist.js`, about 58 KB, 19 KB gzipped) that the recorder loads with a dynamic `import()` the first time it is needed: an agent's offer arrives, the tab has a request going (after a reload), the "Get help" button is on, or the page calls `SessionReplay.assist.request()`. A page where nobody asks for help never downloads it.

**Content Security Policy.** The module comes from the same origin and route as the recorder, with the package's version in the URL; the recorder refuses to `import()` a module URL on another origin. A policy that allows the recorder by host (`script-src 'self'`) allows it too. A policy that allows scripts by nonce only needs `'strict-dynamic'` (a script with the nonce may then import), or `'self'` next to the nonce. The poll timer runs in a Web Worker made from a `blob:` URL (`worker-src blob:`); without it, plain timers take over.

### Asking for help

From your own "Contact support" button:

```js
await SessionReplay.assist.request({ message: 'The checkout button does nothing' })
SessionReplay.assist.status()   // { id, status: 'open' | 'claimed' | 'active' | null, agent: { name, avatar } | null }
SessionReplay.assist.cancel()   // stop waiting
SessionReplay.assist.end()      // end a live assist (or stop waiting)

window.addEventListener('session-replay:assist', ({ detail }) => updateMyButton(detail.status))
```

Or turn on the floating "Get help" button (`assist.button`, off by default; most apps have a place for it already), which opens a small form with an optional message. With the button on, every recorded page loads the Assist module up front, since the button is part of it.

While the request waits, a panel in the corner says so, with Cancel. A tab in mode `on_error` starts uploading when it asks (as `SessionReplay.send()` does), so the agent has a recording to look at. A tab that records nothing (not sampled, no consent) can still ask: the request is queue-only, the agent sees no live page.

### Guests

Guests (nobody signed in, recorded with `session-replay.guests`) may ask while `assist.guests` is on (the default). Anyone can get a guest token by loading a page, so guests have a budget of their own: `assist.guest_requests_per_hour` (20) new requests per hour for all guests of a workspace together, on top of the per-page limit. While that budget is set, a guest asks only from a recording that has events on the server; a request from a tab that records nothing, or before the first upload, is refused (`no_recording`). Set `assist.guests` to `false` to take requests from signed-in people only (`guests`).

### An offer of help

When an agent offers help from a live recording, the page hears of it in the answer to its next upload, or to the heartbeat a visible tab sends every `assist.heartbeat_seconds` (15). The visitor sees "Ana would like to help you with this page", with Accept and Not now. A hidden tab hears of the offer when the visitor comes back to it.

### While an agent is with the visitor

- **Watching.** The agent sees the page as the recording has it, masked the same way: the recorder's own events, sent peer to peer over a WebRTC data channel as they happen. Starting the live view takes one more full snapshot, which the stored recording gets too (one per start and reconnect; [Storage](storage.md#assist) has the sizes).
- **Questions.** Audio (or video, where Calls allows it), remote control, typing into masked fields and screen share are each asked for separately; a question left for 30 seconds counts as no. A question takes the focus to its card, never to Allow, and its buttons take a press only half a second after it shows, so a visitor typing on the page cannot answer it by accident with Enter or Space; screen readers announce it (`alertdialog`). One question of a kind is up at a time (the agent asking again while it is up changes nothing), and after a no the agent cannot ask the same for 10 seconds: the stage says the visitor said no a moment ago. Audio, video and the Accept of an offer are remembered in the tab for the request and its agent, so a reload does not ask again; another agent on the same request (after a claim lapsed) asks again. Remote control is asked again after every reload or navigation. Every answer, and every revocation, goes to the request's grants log (also the screen share the agent stops).
- **Remote control.** The agent's named cursor moves on the page, and a dashed outline frames it while the agent has control. Clicks, scrolls, drags and typing are synthetic events, so what needs a real gesture of the visitor's (a popup, a file picker, the clipboard, fullscreen) is refused by the browser, and the package does not work around it. A password field is never filled, also one a show-password button turned into plain text (rrweb's `data-rr-is-password`, or `autocomplete="current-password"` / `"new-password"`). Nothing the agent cannot see is acted on: an element outside the visitor's viewport or hidden by CSS, or inside `data-replay-skip`, `privacy.block_selector` or rrweb's `.rr-block` (those also count as masked for typing). Typing into a field: with the default `privacy.mask_all_inputs`, every field is masked for the agent, so remote typing needs `assist.control_masked` on and the visitor's yes to its own question ("type into fields hidden from them"); otherwise clicks, scrolling and drags work and typing is refused (`masked`). Turning `privacy.mask_all_inputs` off makes ordinary fields visible and typeable without that question, for every recording, so weigh it as a privacy setting. `data-replay-unmask` does not help here: it keeps text readable under `privacy.mask_all_text` and never touches input values. The visitor always wins: their own mouse or keys pause the agent for a second, Escape or "End remote control" takes control back, and control ends when the tab is hidden, on a navigation and when the connection drops.
- **Annotations.** The agent's strokes are drawn over the page and fade after about four seconds. No question: they change nothing. They never cover a question.
- **Audio.** Once the visitor allows the microphone, the call bar in the panel has Mute and End. The agent is heard only after that answer. A microphone the visitor muted stays muted until their own Unmute (also across a reload): the agent asking again, with audio remembered for the request, never unmutes it. When the agent ends the audio, it comes back only after a new question and the visitor's Allow.
- **Screen share.** The browser's own picker follows the visitor's yes. The panel says "Ana sees your screen" while it runs.

### What the agent never sees

The panel, the questions, the agent's cursor and the strokes are left out of the recording entirely: they sit in an element carrying `data-replay-skip` (a closed shadow root inside it), which the recorder drops with everything inside it. Use the attribute for your own elements too; it must be on the element when it is inserted.

Names are text and an avatar is shown only for an `http(s)` URL. The visitor's UI needs no CSS framework: it brings its own small stylesheet, follows the system's light or dark mode, and its strings come in the five languages of the package (`session-replay::recorder.assist.*`).

### The channel protocol

One ordered data channel labelled `assist` per call, JSON messages `{ t, ... }`, implemented in `resources/js/lib/assist-protocol.js` for both sides. Each side opens with `{ t: 'hello', v: 1, role }`; a side on another major version stops. A message above 64 KB goes as `{ t: 'part', id, i, n, d }` pieces; a sender waits for the channel's buffer to drain and drops queued pointer samples first. Every message is checked against the sender's schema (known `t`, finite coordinates inside the visitor's viewport, node ids the visitor's page knows, values under 4 KB); too many bad messages close the channel. Control messages count only while control is granted, annotations not while a question is up.

The agent's stage bounds what one channel may cost it: at most 1000 stylesheets and 32 MB of stylesheet text (`MAX_LIVE_CSS_KEYS`, `MAX_LIVE_CSS_CHARS` in `lib/assist-stage.js`; past either, a new sheet is dropped and its events show an empty one), and a byte budget for everything the visitor's page sends, 4 MB a second with bursts up to 48 MB for a large snapshot (`AGENT_RATE` in `lib/assist-protocol.js`). A page past the budget is let go (the channel closes with `too_fast`, the stage says so).

| From | Messages |
| --- | --- |
| visitor | `rr` (`e`: one rrweb event), `css` (`key`, `text`: a stylesheet the events point at as `/*sr-live:<key>*/`, sent once per channel), `state` (`hidden`, `url`, `width`, `height`), `control.granted`, `control.released` (`reason`), `control.refused` (`reason`: `password`, `masked`, `blocked`, `not_visible`, `readonly`, `unsupported`, `own_ui`, `declined`, `cooldown`…), `media.granted` / `media.refused` / `media.stopped` (`kind`; refused also `reason`: `declined`, `cooldown`), `screen.granted` / `screen.refused` (`reason`) / `screen.stopped`, `bye` |
| agent | `control.request`, `control.release`, `move` / `click` / `drag.start` / `drag` (`x`, `y` in the visitor's viewport pixels), `drag.stop`, `scroll` (`dx`, `dy`, under the cursor), `focus` (`id`, an rrweb node id), `input` / `select` (`id`, `value`), `annot.start` / `annot.move` (`x`, `y`), `annot.stop`, `media.request` / `media.stop` (`kind`: `audio`, `video`), `screen.request`, `screen.stop`, `bye` |

Attach the endpoint in the call's `datachannel` event, synchronously, so the other side's hello is not missed.

## The agent's side, in the built-in viewer

Everything below sits behind the viewer's middleware and the `viewSessionReplay` gate, asks `visibleUsing()` and `canAssist` again on every request, and posts with the CSRF token. The Filament package brings the same into a panel.

### Live recordings and Offer help

The recordings list has a **Live now** tab: recordings with an upload or a heartbeat in the last two minutes (`ReplaySession::live()`, the window `isLive()` uses). Live is a window, not a promise, so every live row says when the page was last heard from: "last seen 40 s ago". A visible tab sends a heartbeat every `assist.heartbeat_seconds` (15), a hidden one sends nothing, so a page someone left open in the background looks older and older until it drops out.

With Calls installed, a live row (and a live recording's page) has **Offer help**, the propose route as a form. It leads to the stage, where the agent waits for the visitor's Accept. A recording with a request going shows that instead (the agent's own: **Open live view**), and nobody is offered their own visit.

### The Requests page

`/session-replay/assist` (linked from the recordings list while Assist is on) lists the requests that wait and your own: the person, their message (shown as text), the page, how long they have waited, whether the page is still polling, and Claim, Withdraw and End buttons. **Claim** leads to the stage when Calls is installed (without it, back to the list: the request is a queue entry your app answers its own way). Your own requests that are going have **Open live view**.

### The live stage

`/session-replay/assist/requests/{id}/stage`, for the agent who holds the request (claimed, offered or live); anyone else gets a 403, a request that is over sends you back to the list.

- **The status line** says what is happening in words: waiting for the visitor to accept, waiting for their page to pick up, connecting, live, live with the visitor's tab hidden (the page waits until they come back), reconnecting (also when the agent comes back after leaving the live view and the new call is on its way), over and why. It polls the request's status route every two seconds and stops everything once the request ends, whoever ended it, or once it is no longer yours (`mine` false: let go of, or taken by someone else). Who holds a request is the same person by key, model type and guard: Admin #12 is not User #12.
- **The mirror.** The visitor's page replayed live by rrweb's `Replayer` (no player controls), in rrweb's sandboxed iframe without scripts: the same masked events the recording stores, so a masked field shows asterisks and a blocked element an empty box. It plays about 300 ms behind the visitor, with the visitor's clock lined up with the agent's on the first event. A reconnect (either side reloads) starts from a fresh snapshot. Text typed into a field shows when the visitor leaves the field, like in the recording (`size.sampling.input` is `last`; set it to `all` to see every keystroke, live and stored).
- **Request control** asks the visitor (logged in the grants log as asked). Once allowed, the stage gets a dashed frame and the pointer drives the visitor's page: click, drag, the wheel and the arrow and page keys scroll. A click on a field opens a small box over it for typing (Enter sends, Escape closes); the visitor sees each change. Their page refuses a password field always, a masked field unless `assist.control_masked` and their own yes, and says so; the stage shows the reason. **Release control** gives it back; the visitor may take it back any time (Escape), and control ends when their tab is hidden, on a navigation and on a reconnect.
- **Draw** turns the pointer into a pen: strokes show on the stage and on the visitor's page and fade after a few seconds. No question asked, nothing on the page changes.
- **Start audio** asks the visitor for their microphone; once they allow it, the agent's own microphone joins and a small audio bar has Mute and End audio. **Start video** is there while Calls' `video` setting allows it.
- **Their screen** asks the visitor to share their screen (the browser's own picker follows their yes); while they share, the stage shows it as video and the view switch goes between their page and their screen. Control and drawing work on the page view only. **Share my screen** shares the agent's.
- **End assist** ends the request and the call; **Withdraw** takes an offer back or lets go of a claim.
- Every button that cannot be used now is disabled with the reason under it (the live view is still connecting, the visitor's tab is hidden, the visitor has not answered yet, only on the page view, video is off). What the visitor answered, refused or took back is listed under **What happened**.

The stage runs its own Calls client as the agent (`kind=assist` polls, so it never touches the Calls widget's calls, and the Calls widget never touches it) over **Calls' own routes**. In a plain Laravel app register them, in `routes/web.php` so they get the `web` middleware:

```php
use Packstub\Calls\Facades\Calls;

Calls::routes();
```

With another name prefix (`Calls::routes(as: 'support.calls.')`), set `session-replay.assist.calls_routes` to it. The stage polls Calls during the call as often as Calls' own `poll.active` says (`CALLS_POLL_ACTIVE`, 1000 ms; kept between 250 ms and a second under Calls' hold of 5 seconds, so the agent's side is never let go between two polls). Without the routes the stage says so instead of trying to connect. Calls' guard must be the viewer's (see [With Calls](#with-calls)).

`stage.js` and `stage.css` come from the package's script route, like the player (about 240 KB: rrweb's replayer and the calls engine), only on the stage page.

### Leaving the live view

An agent may leave the stage in the middle of an assist: follow a link, reload, lose the network for a moment. The request stays theirs.

- **Back within `packstub-calls.reconnect_timeout`** (15 s): the stage picks up the same call, with a fresh snapshot.
- **Away for longer:** Calls drops the agent's side and the call ends, but the request does not. It goes back to `claimed`, without a call, and the visitor's page says "Ana stepped away and is reconnecting…" (End still works). When the agent opens the stage again, its status poll tells the server they are there, and the visitor's next poll puts both on a new call: live again, from a fresh snapshot. Remote control and screen share are asked again; audio and video allowed before are remembered for the same agent. If the agent does not come back within `claim_timeout_seconds`, a visitor's request goes back to the list and an agent's offer ends (`agent_left`); either way, the server writes a revocation (`by: server`) in the grants log for everything the visitor still had on, so the log and the marker's durations stop when the agent left, and the next agent asks again. `AssistStarted` is not sent again, and `max_duration_minutes` still counts from the first start.
- **Not back within `assist.claim_timeout_seconds`** (30) after the drop: the claim lapses and the request is back in the list, waiting, like a claim the page never picked up.

When it is the visitor's page that goes away for longer than Calls waits, the assist ends (`dropped`), as before.

### Hosting the stage in a page of your own

The Filament package puts the stage in a panel page with the panel's own buttons; any page can do the same. The script mounts on every `[data-session-replay-stage]` element and reads its `data-config` (the JSON the built-in view writes: the request as the agent sees it, the agent routes, Calls' routes, `video`, `csrf` and the strings). Inside that element it only looks for attributes, so the markup is yours:

- `data-ref`: `status`, `seen`, `screen`, `mirror`, `video`, `ink`, `overlay`, `placeholder`, `editor`, `editor-text`, `editor-select`, `notes`, `url`, `audiobar`, `audio`, `face`, `mute`, `end-audio` (see `resources/views/viewer/stage.blade.php` for what each is).
- `data-action` on the buttons: `mirror`, `screen`, `control`, `draw`, `audio`, `video`, `share`, `withdraw`, `end`. The script sets `disabled`, `hidden` and `aria-pressed` on them and their label: into the button's `[data-label]` element when it has one (so an icon beside the text stays), else the whole button.
- `data-why="<action>"`: where the reason a button is disabled goes.

After every change the root dispatches `session-replay:stage` with the state in `event.detail` (`phase`: `waiting`, `connecting`, `live`, `hidden`, `reconnecting` (also while a new call is on its way after the agent left), `ended` or `unavailable`; `request`, `call`, `connected`, `control`, `audio`, `video`, `screen`, `mode`, `drawing`, `events`, `finished`), for a status badge of your own. `root.sessionReplayStage.state` reads the same at any time.

In an app that navigates with `wire:navigate`, the stage leaves the call on `livewire:navigating`, as a page unload does (the request stays the agent's; reopening the stage picks it up with a fresh snapshot, or a new call after a longer absence, see [Leaving the live view](#leaving-the-live-view)), and mounts any stage on the new page on `livewire:navigated`. It is not meant to be kept with `@persist`: a frame moved in the page reloads, which blanks the mirror.

### Measured

On the package's workbenches, 2026-10-28:

- **The extra snapshot** at each live-view start and reconnect: 37 KB stored (gzip; 650 KB before compression) on a Filament panel's 50-row table page, 1.2 KB on a small Blade page. [Storage](storage.md#assist) has the table.
- **A visitor's tab in the background for ten minutes.** Google Chrome 156 on macOS, a real window with a second tab brought to the front, so the visitor's tab was `hidden` the whole time (no automation focus emulation, Chrome's own background throttling on). The page held its side of the call all along: it polled every 2 seconds (median 2.04 s, longest gap 3.0 s, 305 polls), Calls never dropped it, the agent's stage stayed connected and showed "the visitor's tab is hidden", and when the tab came back to the front a click on it reached the agent's mirror within a few seconds, with no reconnect. Two things keep it so: the poll timer runs in a Web Worker, which Chrome does not slow down like a hidden page's own timers, and Chrome leaves pages with an open WebRTC connection out of its intensive throttling.

## Events

| Event | When |
| --- | --- |
| `AssistRequested` | a visitor asked (`$request->opened_by === 'visitor'`) or an agent offered help (`'agent'`) |
| `AssistClaimed` | an agent took a visitor's request (`$request->agent_id`, `agent_name`) |
| `AssistStarted` | the call exists (`$request->call_id`); once per assist, not again for the new call after the agent left the live view |
| `AssistEnded` | every finish: `$request->status`, `end_reason`, and `grants`, the log of what the visitor allowed |

Each carries the `AssistRequest` as `$event->request`.

## The visitor's routes

Stateless, like the upload endpoints: no session, cookie or CSRF token, throttled by `ingest.requests_per_minute` per person (for guests: per rendered page), on the same key as the uploads. With Assist on, a visible tab adds a heartbeat about 4 times a minute; a tab with a request going polls every 3 seconds while it waits and every 2 seconds during an assist (about 30 a minute, plus a burst of WebRTC signals when the call starts). The default of 240 a minute has room for that next to the uploads (about 12 a minute); if you lowered it, leave that room, and on cached guest pages, where every visitor shares one key, raise it.

- A request is created with the recording's triple: `token` (the signed token the page was rendered with), `session` (the recording's id) and `key` (its write key). Only the browser that makes the recording can attach a request to it. A tab that records nothing sends the token alone.
- The answer carries a **secret** once: 32 hex characters, stored as its SHA-256 and compared in constant time. Every later call presents `secret` and `token`. The token keeps the throttle and lets the server refuse one that expired, belongs to another workspace, or now names someone else, which ends the request (`identity_changed`).
- An unknown id and a wrong secret get the same answer.
- Every visitor route is a `POST` and reads the token, the write key and the secret from the body only (JSON or a form), never from the query string: a URL ends up in access logs, proxies and error trackers. The upload endpoints read the token and the key the same way.

| Method | Route | Does |
| --- | --- | --- |
| `POST` | `assist/requests` `{token, session?, key?, message?, url?, tab?}` | create; 201 with `secret`, or 200 and `secret: null` for the request that is going (`tab`, the tab's own id, keeps one per tab without a recording); 403 `guests` or `no_recording` for a guest Assist does not take; 429 past `requests_per_hour`, `max_open_requests` or `guest_requests_per_hour` |
| `POST` | `assist/requests/{id}` `{secret, token, after, tab, hold}` | the poll: `visitor_seen_at`, the timeouts, the call created on acknowledgement, and Calls' poll for the guest once a call exists. With `session` and `key` of the tab's new recording, the request moves to it (same person, same workspace). |
| `POST` | `assist/requests/{id}` `{token, session, key}` | the first answer to an agent's offer, with the triple instead of a secret: the secret is issued in it, once (409 after) |
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
    "reconnecting": false,
    "end_reason": null,
    "call": { "id": 41, "kind": "assist", "status": "active", "peer": { "id": "12", "name": "Ana", "avatar": null } },
    "signals": [],
    "cursor": 0
}
```

`call`, `signals` and `cursor` are Calls' poll as the guest (`guest:<request id>`, also for a signed-in visitor); a page checks that `call.peer.id` equals `agent.id` before it connects. `consent` is `"watch"` while the visitor's Accept is what moves the request on; `reconnecting` is true while the agent left the live view and the page waits for them. Nothing in it names anyone but the agent.

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

A request as the agent sees it: `id`, `status`, `opened_by`, `session`, `session_url`, `user` (`id`, `label`), `message`, `page_url`, `agent` (`id`, `name`), `mine`, `call`, `reconnecting`, `visitor_here`, `visitor_seen_ago`, `waiting` (seconds), `grants`, `claimed_at`, `started_at`, `ended_at`, `end_reason`, `created_at`. Refusals are `{message, reason}`, `reason` one of `taken`, `exists`, `not_allowed`, `guard_mismatch`, `connection_mismatch`, `not_live`, `no_calls`, `state`.

## Privacy

- Nothing of the live view is stored beyond the recording that exists anyway, plus one full snapshot per live-view start and reconnect. Audio, video and screen share are never stored. The mirror and the media go peer to peer, encrypted by WebRTC; a TURN relay forwards ciphertext. ICE candidates show each side's addresses to the other; `packstub-calls.ice_transport_policy` `relay` hides them behind your TURN server.
- The visitor's routes take no identity but the signed token and the request's secret; the agent sees the page's URL with `privacy.redact_query` applied, and the message as text.
- No credential is read from a URL: the token, the write key and the secret come from the request body on every visitor route (the leave beacon sends a form body), and the rate limiter reads the token from the body too. Nothing in the package logs them.
- The grants log is the compliance trail: what the agent asked for (`{kind, asked: true, by: "agent", at}`) and what the visitor allowed or took back (`{kind, granted, by: "visitor", at}`), and when. `AssistEnded` carries it; the `assist` marker summarises what was allowed on the recording.
- Calls deletes a call's signals when it ends; the package never logs a session description or a candidate.
- A request is deleted with its recording by `session-replay:prune`; one that never had a recording, after `retention.days`.

### GDPR and your privacy notice

This is how the package behaves, not legal advice; your privacy team or DPO has the last word.

- **The lawful basis is yours.** Your app is the controller and decides why live help happens. For most apps it is support the person asked for under their contract (Art. 6(1)(b)) or your legitimate interest in helping them (Art. 6(1)(f)). The visitor's yes to each step (the offer, control, audio, video, screen) is how the package keeps them in charge of what the agent may do; it is not meant as the legal basis on its own.
- **What leaves the browser, and to whom.** To your server: the request (the message, the page URL with `privacy.redact_query` applied, the recording's id) and, for the call, the WebRTC signals (session descriptions and ICE candidates, which include IP addresses) in Calls' tables, deleted when the call ends. To the agent's browser, peer to peer and encrypted: the recorder's events, masked as in the recording, and audio, video or the screen only after the visitor allowed each. Through a TURN server when one is set (`packstub-calls.ice_servers`): the same, still encrypted. A STUN server (Calls' default is a public one) sees each side's IP address while the connection is set up; list your own to keep that in house. Nothing goes to Packstub.
- **What is kept.** The recording that exists anyway, one extra full snapshot per live-view start and reconnect, the request row (who, when, the message, the outcome) and its grants log, all deleted with the recording (`retention.days`). The `assist` marker on the recording says what was allowed and for how long, never who the agent was.
- **The grants log** is your record of consent to each step: what the agent asked for, what the visitor allowed or took back, with timestamps. `AssistEnded` hands it to your app if you keep an audit trail elsewhere.
- **Wording for the visitor.** The questions on the page already say what each step does ("Ana would like to control this page: click, scroll and type for you. You can stop it at any time with Escape."). For your privacy notice, something like: "When you ask for help, or accept our offer of help, a support agent can see the page you are on as you use it, with form fields hidden. They can only control the page, talk with you or see your screen after you allow it, and you can stop at any time. We keep a note of what you allowed, with the session recording, for N days."

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `assist.enabled` | `false` (`SESSION_REPLAY_ASSIST`) | The routes, the Requests page and the knock in the upload answer. |
| `assist.calls` | `true` | Use `packstub/calls` when it is installed. `false` keeps the queue alone. |
| `assist.watch_consent` | `false` | The visitor accepts before the agent joins, also for a request they opened. Accepting an offer always counts. |
| `assist.requests_per_hour` | `6` | New requests per person per hour (guests: per rendered page); `null` turns it off. |
| `assist.max_open_requests` | `100` | Requests going at once per workspace (no workspace counts as one); past it, 429. `null` turns it off. |
| `assist.guests` | `true` (`SESSION_REPLAY_ASSIST_GUESTS`) | Guests (recorded with `session-replay.guests`) may ask. `false`: signed-in people only. |
| `assist.guest_requests_per_hour` | `20` | New requests per hour from all guests of a workspace together; while set, a guest asks only from a recording with events. `null` turns both off. |
| `assist.claim_timeout_seconds` | `30` | A claim the page has not acknowledged goes back to the list; so does the claim of an agent who left the live view and has not come back this long after Calls dropped them. |
| `assist.proposal_timeout_seconds` | `60` | An offer nobody answered expires. |
| `assist.visitor_timeout_seconds` | `60` | A request whose page stopped polling expires; a live one ends. |
| `assist.max_duration_minutes` | `120` | An assist ends after this long, counted from its first start. |
| `assist.button` | `false` (`SESSION_REPLAY_ASSIST_BUTTON`) | A floating "Get help" button on every recorded page. |
| `assist.control_masked` | `false` (`SESSION_REPLAY_ASSIST_CONTROL_MASKED`) | Let the agent type into fields they see masked, after a separate question. Password fields never. |
| `assist.heartbeat_seconds` | `15` | A visible tab's sign of life, which also brings an agent's offer. |
| `assist.poll.waiting_ms` | `3000` | How often a waiting page polls its request. |
| `assist.poll.active_ms` | `2000` | How often it polls during an assist (the call's heartbeat; kept between 500 and 4000, under Calls' hold of 5 seconds). |
| `assist.calls_routes` | `calls.` | The name prefix of Calls' routes (`Calls::routes(as: …)`), which the agent's live stage polls. |
