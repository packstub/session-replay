# Error tracking

While a recording runs, every log line and error report of that person's requests can point at the replay.

## How it works

1. The recorder writes the recording's id into a plain cookie (`context.cookie`, default `session_replay_id`; `SameSite=Lax`, `Secure` on HTTPS, expires with `idle_timeout`). It holds a UUID and nothing else.
2. The browser sends the cookie with every request to your app: page loads, Livewire updates, `fetch` calls.
3. `AddReplayContext`, a global middleware the package registers, reads it, checks that it is a UUID and adds two keys to Laravel's `Context`:

| Key | Value |
| --- | --- |
| `session_replay` | The recording's id |
| `session_replay_url` | Where to watch it: the built-in viewer's page, or what `SessionReplay::urlUsing()` returns. Left out when there is nowhere to link to. |
| `session_replay_moment` | The same page with `?at=` and the request's time, so it opens the replay a few seconds before this request. Left out with `session_replay_url`. |
| `session_replay_report` | Added when an exception is reported during the request (`capture.server_errors`): the id of that report, the same the `error` marker on the replay carries in its payload as `report`. See [From the replay to the logs](#from-the-replay-to-the-logs). |

The middleware runs no query.

## From the replay to the logs

The keys above take you from a log line to the replay. The other way round starts at the marker: when Laravel reports an exception during a recorded request, the package gives the report an id (a ULID), puts it on the `error` marker's payload as `report` and adds it to `Context` as `session_replay_report` before Laravel logs the exception, so the log lines of exactly that exception, and the event your error tracker receives, carry the same id. The marker's details in the player show the id with a **Copy report id** button, and an **Open in logs** button when your app says where to look:

```php
// app/Providers/SessionReplayServiceProvider.php, boot()
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplayMarker;

SessionReplay::logUrlUsing(function (ReplayMarker $marker): ?string {
    $report = $marker->payload['report'] ?? null;

    return $report ? url('/log-viewer?query='.$report) : null;
});
```

The closure receives every marker the player shows and returns a URL or `null`. Three tools, by example: [Log Viewer](https://log-viewer.opcodes.io) searches the files for `?query=<report id>`; Telescope's exceptions page lists the exception by class and time (`url('/telescope/exceptions')`); Sentry and Flare take the id as a search on the `session_replay_report` context key in their own URLs. The button opens in a new tab and is only ever shown to someone the `viewSessionReplay` gate let in.

## Where it shows up

Laravel attaches `Context` to everything it logs, and carries it into queued jobs dispatched during the request:

```
[2026-09-18 10:57:12] production.ERROR: Undefined array key "total"
{"exception":"…"} {"session_replay":"0b1c6a0e-…","session_replay_url":"https://app.test/session-replay/0b1c6a0e-…"}
```

Error trackers that read Laravel's `Context`, such as Flare, Sentry and Nightwatch, receive the keys as context on the report, next to whatever else your app adds. From a report, open `session_replay_moment` to land just before the failing request. The player turns `?at=` into a position, three seconds early, because the server's clock and the browser's rarely agree to the second. `?t=` with seconds into the recording works on `session_replay_url` as before.

In your own code:

```php
use Illuminate\Support\Facades\Context;

$replay = Context::get('session_replay_url');
```

## Server errors on the replay

The replay shows a failed request from the browser's side ("Livewire request failed (500)"). With `capture.server_errors` (default `'class'`), an exception Laravel reports during one of the recording's requests is put on the replay too, as an `error` marker:

| | |
| --- | --- |
| Label | `Server error: QueryException` |
| Payload | `source: server`, the exception's class, the status (500, or an HTTP exception's own), the request's method and path (never its query) |
| When | The time Laravel reported it |

- **What counts:** what Laravel reports. Exceptions it doesn't report (validation errors, 404s, whatever is in your `dontReport`) don't appear.
- **Whose recording:** only the recording of the person who made the request. The marker is skipped when the request is signed in as someone else. Recordings that name nobody (guests, `privacy.anonymous`) take it on their id alone, a random UUID only that browser and your logs know.
- **Recordings not stored yet:** in mode `on_error` the failed request is what makes the browser upload, so the recording often isn't stored yet. The marker then waits in your cache for `idle_timeout`, and the recording's next batch takes it.
- **Requests only:** queued jobs carry the Context keys along but are never put on a timeline. The package's own routes (uploads, the viewer) are left out too.
- **Pages the app does not record:** a request to an `except` path or an `except_routes` name carries none of the keys and raises no marker, even while the tab's recording runs around it (a page reached with `wire:navigate` inside a recorded session still sends the cookie). Livewire's update requests from such a page keep the keys: their path is not the page's.
- **The message:** left out by default, because it often carries personal data (a query's values, an email address). `'message'` adds it to the label, with the URLs in it redacted by `privacy.redact_query`. `false` turns server errors off.

It needs `context.enabled`: the recording's cookie is how a request is matched to its recording.

## Link to your own page

When replays are watched somewhere other than the built-in viewer:

```php
use Packstub\SessionReplay\Facades\SessionReplay;
use Packstub\SessionReplay\Models\ReplaySession;

SessionReplay::urlUsing(fn (ReplaySession $session): string => route('support.replays.show', $session->id));
```

For the context the closure receives an unsaved model that carries only the id, so build the URL from `$session->id` and do not read other attributes there.

## Turning it off

```php
'context' => ['enabled' => false],
```

No cookie is written and the middleware is not registered. Rename the cookie with `context.cookie`. If your app lists cookies for a consent banner, this one is functional: it identifies a recording, not a person, and is only set while recording runs.
