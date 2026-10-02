# Privacy

A recording shows what a person saw. The defaults are chosen so that a forgotten setting records less, not more.

## What is recorded

- The page's DOM when it loads and every change to it afterwards, the stylesheets, mouse movement, clicks, scrolling, viewport size and input events.
- The markers described in [Recording](recording.md#markers): errors, `console.error` output, failed Livewire requests, web vitals, rage clicks, page views.
- With the recording: who was signed in (morph type and key), the workspace and impersonator if your app provides them, your own properties, the first URL, the user agent and a device class (`desktop`, `tablet`, `mobile`).

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

## Who is recorded

- `guests` (default `false`): only signed-in people are recorded.
- `SessionReplay::recordWhen(fn ($user, $request) => ...)`: leave out staff, a plan, a route group, anything you can decide from the user and the request.
- `except`: request paths that never get the recorder.
- `sample_rate`: record a share of sessions.

See [Recording](recording.md#who-is-recorded).

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
