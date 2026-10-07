# Privacy

A recording shows what a person saw. The defaults are chosen so that a forgotten setting records less, not more.

## What is recorded

- The page's DOM when it loads and every change to it afterwards, the stylesheets, mouse movement, clicks, scrolling, viewport size and input events.
- The markers described in [Recording](recording.md#markers): errors, `console.error` output, failed Livewire requests, web vitals, rage clicks, page views.
- With the recording: who was signed in (morph type and key; nobody with `privacy.anonymous`), the workspace and impersonator if your app provides them, your own properties, the first URL, the user agent (unless `privacy.store_user_agent` is off) and a device class (`desktop`, `tablet`, `mobile`).

## What is not recorded

- **No IP address.** Not stored, not used for rate limiting.
- **Input values.** Every input, textarea and select is masked with asterisks (`privacy.mask_all_inputs`, default `true`). Password inputs are masked even when that setting is off.
- **Rich editors.** Text in a `contenteditable` element (Tiptap, Trix, CodeMirror) is masked like an input, through the default `privacy.mask_text_selector`.
- **Hidden inputs.** The value of every `<input type="hidden">` (a CSRF token, an id, a signature) is dropped before upload.
- **Secrets in URLs.** The query parameters in `privacy.redact_query` (`token`, `signature`, `code`, `state`, `email` and others) keep their name and lose their value in every URL the recorder sends: the first page, page views, error sources, stack traces and the links, images and form actions in the page itself.
- **Password-reset and verification pages.** The default `except` list leaves out the pages whose URL carries such a link (`*password-reset*`, `*reset-password*`, `email/verify*` and the like), and `except_routes` the same pages by route name (`password.*`, `verification.*`, Filament's `filament.*.auth.password-reset.*`), whatever their URL.
- **Scripts.** Script contents are left out of the snapshot and the player never executes anything; it rebuilds the DOM in a sandboxed iframe.
- **Livewire component state.** `wire:snapshot` and `wire:effects` are dropped before upload, along with Alpine expressions (`size.strip_attributes`).
- **Guests**, unless you turn `guests` on.
- **Canvas contents and cross-origin iframes.**
- **Cookies, local storage, request or response bodies.**

Console output can contain data your own code logs; set `capture.console` to `[]` if that is a concern.

## Mask, block, ignore

Three attributes cover what the defaults cannot know about:

```blade
{{-- The text is replaced with asterisks; layout stays. --}}
<p data-replay-mask>IBAN {{ $account->iban }}</p>

{{-- Recorded as an empty box of the same size. Children are never serialized. --}}
<section data-replay-block>
    @include('billing.card-details')
</section>

{{-- The element is recorded, typing into it is not. --}}
<input type="text" name="notes" data-replay-ignore>
```

`data-replay-mask` applies to the element and everything inside it. The selectors are configurable (`privacy.mask_text_selector`, `privacy.block_selector`, `privacy.ignore_selector`), so existing classes can be reused:

```php
'privacy' => [
    'mask_text_selector' => '[data-replay-mask], [data-replay-mask] *, .pii, .pii *',
    'block_selector' => '[data-replay-block], .card-form',
],
```

### Layout only

```php
'privacy' => ['mask_all_text' => true],
```

Every text node is replaced with asterisks. The replay still shows where people click and what breaks, without a readable word.

## Consent

| `consent` | Behaviour |
| --- | --- |
| `always` (default) | Recording starts with the page. For a product used under a contract or terms that cover it. |
| `opt-in` | The recorder loads and waits. Nothing is recorded or uploaded until the person agrees. |

With `opt-in`, call the browser API from your consent banner:

```js
// The person agreed: remembered in localStorage, recording starts now.
SessionReplay.consent(true);

// The person declined or withdrew: remembered, recording stops, the buffer is dropped.
SessionReplay.consent(false);
```

`respect_gpc = true` leaves people alone whose browser sends the Global Privacy Control signal, in both modes.

## Asking before a replay is sent

With `mode = on_error` and `on_error.ask = true`, the recorder asks the person after something went wrong whether to send the replay of what just happened. Nothing is uploaded unless they agree.

- **Send** uploads the kept window, and the tab keeps recording for the rest of its session; the dialog says so.
- **Don't send** (or Escape) drops what was kept, and the tab is not recorded again until its session ends (`idle_timeout`, or a change of person or workspace).
- **Anonymously.** When someone is signed in, a checkbox sends the replay without their name: the same rule as `privacy.anonymous`, for this recording only. No person and no impersonator are stored, the workspace and your properties stay, and the upload still counts against the person's daily allowance. The choice only ever removes identity from the signed token; the request carries nothing else about who someone is.
- **Nothing is kept before the answer.** Only a window the person agreed to send may wait in the browser's storage when its upload fails (`on_error.keep_pending`, see [below](#what-stays-in-the-browser)), and the anonymous choice is kept with it.
- **The dialog** is drawn by the recorder in plain DOM, inside a closed shadow root: your page's styles do not reach it, its styles do not leak into your page, and it never appears in a replay. It is a labelled modal dialog, takes focus when it opens, keeps it inside and gives it back when it closes, and follows the system's light or dark scheme. Its strings follow the app's locale; change them in `lang/vendor/session-replay/{locale}/recorder.php` (see [Languages](configuration.md#languages)).

## What stays in the browser

The recorder keeps nothing recorded at rest, with one exception. In `mode = on_error`, a window whose upload failed waits in the browser's IndexedDB (same origin as your app) until it gets through, so a reload while the server is down does not lose the replay of the error. It holds the same masked events the upload holds, is dropped after `idle_timeout` (30 minutes by default), when another person signs in or when the tab stops recording, and is never sent by another tab. `on_error.keep_pending = false` turns it off. See [When the upload fails](recording.md#when-the-upload-fails).

## Who is recorded

- `guests` (default `false`): only signed-in people are recorded.
- `SessionReplay::recordWhen(fn ($user, $request) => ...)`: leave out staff, a plan, a route group, anything you can decide from the user and the request.
- `except` and `except_routes`: request paths and route names that never get the recorder.
- `sample_rate`: record a share of sessions.
- `mode = on_error`: upload only the tabs where something went wrong, optionally after asking (see [above](#asking-before-a-replay-is-sent)).

See [Recording](recording.md#who-is-recorded).

## Recordings that name nobody

`privacy.anonymous = true` records signed-in people without linking the recording to them: no person and no impersonator are stored, the workspace and your properties stay. Who is signed in still decides whether a page is recorded (`guests`, `recordWhen()`), and a change of person still starts a new recording. The server limits uploads per person through a keyed hash of the person that travels in the signed token and is never stored. The Filament plugin follows the same setting.

The list then shows "Guest" for every recording, a person's Session replays tab stays empty, and `WatchLastSessionAction` has nothing to open: support can no longer go from a user to their recordings, which is the point. Combine it with `privacy.mask_all_text` for recordings that cannot identify anyone from what is on screen either.

`privacy.store_user_agent = false` drops the browser's user agent; the device class (desktop, tablet, mobile) is kept.

## Who may watch

Nobody outside the `local` environment, until your app defines the `viewSessionReplay` gate. The gate receives the recording, and it guards every file the player loads, not only the page. See [Watching replays](watching.md#the-gate).

## Retention and deletion

- `session-replay:prune` deletes recordings older than `retention.days` (default 30) with their files. Schedule it daily.
- `$session->delete()` deletes one recording, its chunks, its markers and its files, for example to answer an erasure request:

```php
use Packstub\SessionReplay\Models\ReplaySession;

ReplaySession::query()->forUser($user)->each->delete();
```

- A pinned recording (`pinned = true`) is kept until it is unpinned.

## Wording for your privacy policy

Adapt this to your product:

> To help us find and fix problems, we record how signed-in users interact with the application: the pages shown, clicks, scrolling and technical errors. What you type into form fields is masked before it leaves your browser, and passwords are never recorded. Recordings are stored on our own infrastructure, are accessible only to authorized support and engineering staff, and are deleted after 30 days. We do not share them with third parties.

This page describes what the software does; it is not legal advice.
