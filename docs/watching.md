# Watching replays

## The gate

One ability decides who may watch: `viewSessionReplay`.

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    return $user->is_admin;
});
```

- It is called **without a recording** for the list.
- It is called **with the recording** for the player page and for every data route behind it: the manifest, each chunk, each stylesheet. A guessed URL gets a 403, and the player component is protected wherever you embed it.
- **Until your app defines it, only the `local` environment is let in.** A forgotten setup never exposes a recording.

`php artisan session-replay:install` publishes `App\Providers\SessionReplayServiceProvider` with the gate in it, the way Telescope and Horizon do.

### Examples

Support may watch customers, never other staff:

```php
Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    if (! $user->is_staff) {
        return false;
    }

    // The list ($session is null) is open to staff; a single replay only when it is a customer's.
    return ! $session?->user?->is_staff;
});
```

Only recordings of my own team:

```php
Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    if ($session === null) {
        return $user->isTeamAdmin();
    }

    return $user->isTeamAdmin()
        && $session->tenant_type === $user->currentTeam->getMorphClass()
        && $session->tenant_id === (string) $user->currentTeam->getKey();
});
```

The gate decides about one recording at a time, so by itself it cannot shorten a list. When it narrows single recordings, tell the list the same rule as a query, and rows the gate would refuse stay out of sight while every page stays full:

```php
use Illuminate\Database\Eloquent\Builder;
use Packstub\SessionReplay\Facades\SessionReplay;

SessionReplay::visibleUsing(function (Builder $query, $viewer): void {
    $query->where('tenant_type', $viewer->currentTeam->getMorphClass())
        ->where('tenant_id', (string) $viewer->currentTeam->getKey());
});
```

The built-in list applies it, `packstub/filament-session-replay` applies it to its resource, relation manager and widget, and your own page can with `SessionReplay::visibleTo(ReplaySession::query())`. Without it the list shows every recording to whoever may open the list; opening one always asks the gate.

The rule runs as one group of its own (`where(fn ($query) => ...)`), so an `orWhere()` in it only ever narrows: `$query->where('user_id', (string) $viewer->getKey())->orWhereNull('user_id')` shows a viewer their own recordings and guests', and still only in the workspace a panel is scoped to and within the list's filters.

**In a multi-tenant app, define `visibleUsing()` whenever someone who is not an operator may open the list.** A gate that lets every workspace admin open the list (`$session === null`) and checks the workspace only for a single recording keeps each replay safe, but the built-in list at `/session-replay` would still show every workspace's rows. When the recordings are watched in a Filament panel, turn the built-in pages off with `viewer.enabled = false`; the panel scopes its own lists.

Check the rule yourself with `SessionReplay::check()`:

```php
use Packstub\SessionReplay\Facades\SessionReplay;

SessionReplay::check();                 // the list, for the current viewer
SessionReplay::check($session);         // one recording
SessionReplay::check($session, $user);  // for someone else
```

## The built-in viewer

`/session-replay` lists recordings, newest first: person, start, length, pages, errors and rage clicks, the vitals rating, the first page. Filters: **User ID**, **From**, **To**, **With errors**. A recording that received events in the last two minutes is marked live.

`/session-replay/{session}` shows the facts of one recording and the player with its marker list.

| Key | Default | Meaning |
| --- | --- | --- |
| `path` | `session-replay` | The prefix of every package route |
| `domain` | `null` | Serve the routes on one domain only |
| `viewer.enabled` | `true` | `false` removes the two pages; the data routes and the component stay |
| `viewer.middleware` | `['web', 'auth']` | In front of the gate, for the pages and the data routes |
| `viewer.guard` | `null` | The guard whose user is handed to the gate (`null`: the default guard) |
| `viewer.per_page` | `25` | Recordings per page |
| `viewer.user_label` | `email` | The attribute shown for a person; falls back to `#id`, and to "Guest" |

With a guard of its own, set both the middleware and the guard:

```php
'viewer' => [
    'middleware' => ['web', 'auth:admin'],
    'guard' => 'admin',
],
```

The viewer's own pages are never recorded.

## The player

Markers sit on the timeline in their colour and in a list next to the player; a click jumps to one second before the moment.

| Marker | Colour |
| --- | --- |
| `error` | red |
| `request` | orange |
| `console` | amber |
| `rage-click` | purple |
| `navigation` | blue |
| `vital` | green (hidden in the list until its chip is pressed) |
| `custom` | slate |

The controller plays at 1x, 2x, 4x and 8x, skips inactivity and goes full screen.

`?t=83` opens a replay at 1:23:

```
https://app.test/session-replay/0b1c…?t=83
```

**Copy link to this moment**, under the controller, copies that link for the second the replay is at, to paste into a ticket or a chat. It is the page's own URL, so it opens only for someone the gate lets in. On a site served over plain `http://` the browser offers no clipboard and the link is shown to copy by hand.

## Your own page

Embed the player anywhere; the data routes check the gate for that recording on every request.

```blade
<x-session-replay::player :session="$session" />
```

| Prop | Default | Meaning |
| --- | --- | --- |
| `session` | required | A `ReplaySession` or its id |
| `markers` | `true` | Show the marker list next to the player |
| `copy-link` | `true` | Show "Copy link to this moment" under the controller |
| `assets` | `true` | Include the player's stylesheet and script (once per page) |

Other attributes (`class`, `id`) pass through to the element. To load the player's files yourself, for example in `<head>`:

```blade
<head>
    @sessionReplayPlayerAssets
</head>
<body>
    <x-session-replay::player :session="$session" :assets="false" />
</body>
```

In the browser, `window.SessionReplayPlayer.mount(element, { manifestUrl, autoPlay })` mounts a player on an element (it reads `data-manifest` when `manifestUrl` is left out), `mountAll()` mounts every `[data-session-replay-player]` element, `unmount(element)` stops one and empties its element, and the element dispatches `session-replay:ready` once the player is up:

```js
document.addEventListener('session-replay:ready', (event) => {
    const { player, manifest } = event.detail;

    player.goto(30_000, true); // rrweb-player's API
});
```

With Livewire's `wire:navigate` the player's script runs once per tab (its tag has `data-navigate-once`). On `livewire:navigating` every player on the page is paused and destroyed with its listeners, so a replay watched earlier does not stay in memory; on `livewire:navigated` the players of the new page are mounted.

### Mouse trail and clicks

The player draws where the mouse went as a line that fades over a second and a half, and marks every click with a ring that spreads and a dot that lingers. Both run on the replay's clock: they freeze on pause, keep pace at 2x to 8x, and nothing is drawn for a stretch a seek jumps over. The colour is `--sr-pointer` on the player:

```css
.sr-player {
    --sr-pointer: #f59e0b;
}
```

### Links

`$session->url()` is the recording's page in the built-in viewer. When replays are watched somewhere else (your own page, a panel), say where:

```php
SessionReplay::urlUsing(fn (ReplaySession $session): string => route('support.replays.show', $session));
```

With `viewer.enabled = false` and no `urlUsing()`, `url()` is `null`. The same URL is what the [log context](error-tracking.md) carries.

## Models and scopes

Add the trait to the model people sign in with:

```php
use Packstub\SessionReplay\Concerns\HasSessionReplays;

class User extends Authenticatable
{
    use HasSessionReplays;
}
```

```php
$user->sessionReplays()->latest('started_at')->first()?->url();
```

`ReplaySession` has `user()` and `tenant()` (morph relations), `chunks()`, `markers()`, and these scopes:

```php
use Packstub\SessionReplay\Models\ReplaySession;

ReplaySession::query()->forUser($user)->withErrors()->latest('started_at')->get();
ReplaySession::query()->forTenant($team)->poorVitals()->count();
```

| Scope | Recordings |
| --- | --- |
| `forUser($user)` | of that person |
| `forTenant($tenant)` | of that workspace |
| `withErrors()` | with at least one `error` or `request` marker |
| `poorVitals()` | with LCP over 4000 ms, INP over 500 ms or CLS over 0.25 |
| `prunable($days)` | older than the window and not pinned |

Helpers: `durationMs()`, `durationForHumans()` ("4m 12s"), `isLive()`, `vitalsRating()` (`good`, `needs-improvement`, `poor` or `null`). Filter on markers through the relation:

```php
ReplaySession::query()
    ->whereHas('markers', fn ($query) => $query->where('type', 'request')->where('label', 'like', '%(419)%'))
    ->get();
```

`ReplaySessionStarted` is dispatched when the first batch of a new recording is stored:

```php
use Packstub\SessionReplay\Events\ReplaySessionStarted;

Event::listen(function (ReplaySessionStarted $event): void {
    logger()->info('Recording started', ['url' => $event->session->url()]);
});
```
