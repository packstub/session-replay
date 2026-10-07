/**
 * The question on_error.ask puts to the person after a trigger: send the
 * replay of what just happened, or not. A native <dialog> opened with
 * showModal(), so it sits in the browser's top layer, centred in the viewport
 * whatever the page does, above anything with a z-index and above a modal
 * dialog opened before it (Livewire shows a failed request's error page in
 * one). The rest of the page is inert while it is open, so focus stays in.
 * Plain DOM in a closed shadow root: the page's styles cannot reach in, its
 * own styles cannot leak out, and rrweb cannot see inside it, so the dialog
 * never appears in a replay (the host is recorded as an empty element).
 *
 * With `error` ({ status, message }, a failed request) the dialog shows the
 * status code and the error page's title under its own title, and says the
 * action did not go through: in production it stands in for Livewire's error
 * modal. notice() is the short thank-you after the replay was sent.
 *
 * Resolves to { share, anonymous }.
 */

const STYLE = `
:host { all: initial; }
dialog {
    box-sizing: border-box; width: calc(100% - 32px); max-width: 26rem; max-height: calc(100% - 32px); margin: auto; padding: 20px;
    overflow: auto; border: 0; border-radius: 12px; background: #fff; color: #0f172a;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25); outline: none;
    font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color-scheme: light dark;
}
dialog::backdrop { background: rgba(15, 23, 42, 0.45); }
/* A browser without showModal(): the same place, over the page, without the top layer. */
dialog[open]:not(:modal) { position: fixed; inset: 0; z-index: 2147483647; }
h2 { margin: 0 0 8px; font-size: 17px; font-weight: 600; line-height: 1.35; }
.error { display: flex; align-items: center; gap: 8px; margin: -2px 0 10px; font-size: 13px; color: #64748b; }
.code { padding: 1px 7px; border-radius: 6px; background: #f1f5f9; color: #475569; font-weight: 600; font-variant-numeric: tabular-nums; }
.message { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
p { margin: 0 0 16px; color: #334155; }
p.failed { margin-bottom: 8px; }
.notice {
    position: fixed; inset: auto 16px 16px; margin: 0 auto; width: max-content; max-width: calc(100% - 32px); z-index: 2147483647;
    padding: 10px 14px; border: 0; border-radius: 10px; background: #0f172a; color: #f8fafc;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.3);
    font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
label { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 16px; cursor: pointer; }
input { margin: 3px 0 0; width: 16px; height: 16px; flex: none; accent-color: #2563eb; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
button {
    font: inherit; font-weight: 500; padding: 8px 14px; border-radius: 8px; cursor: pointer;
    border: 1px solid #cbd5e1; background: #fff; color: #0f172a;
}
button.share { border-color: #2563eb; background: #2563eb; color: #fff; }
button:focus-visible, input:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
@media (prefers-color-scheme: dark) {
    dialog::backdrop { background: rgba(2, 6, 23, 0.6); }
    dialog { background: #0f172a; color: #e2e8f0; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5); }
    p { color: #cbd5e1; }
    .error { color: #94a3b8; }
    .code { background: #1e293b; color: #cbd5e1; }
    .notice { background: #f1f5f9; color: #0f172a; }
    button { background: #1e293b; color: #e2e8f0; border-color: #334155; }
    button.share { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    button:focus-visible, input:focus-visible { outline-color: #93c5fd; }
}
`;

function element(tag, attributes = {}, text = null) {
    const node = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);

    if (text !== null) node.textContent = text;

    return node;
}

export function ask(labels, { offerAnonymous = false, error = null } = {}) {
    return new Promise((resolve) => {
        const host = element('div', { 'data-session-replay-ask': '' });
        const root = host.attachShadow({ mode: 'closed' });
        const previous = document.activeElement;

        const style = element('style', {}, STYLE);
        const dialog = element('dialog', { 'aria-labelledby': 'sr-ask-title', 'aria-describedby': 'sr-ask-body', tabindex: '-1' });
        const anonymous = element('input', { type: 'checkbox', id: 'sr-ask-anonymous' });
        const decline = element('button', { type: 'button', class: 'decline' }, labels.decline);
        const share = element('button', { type: 'button', class: 'share' }, labels.share);
        const actions = element('div', { class: 'actions' });

        dialog.append(element('h2', { id: 'sr-ask-title' }, labels.title));

        if (error) {
            const line = element('div', { class: 'error' });

            if (error.status) line.append(element('span', { class: 'code' }, String(error.status)));
            if (error.message) line.append(element('span', { class: 'message', title: error.message }, error.message));
            if (line.childElementCount) dialog.append(line);
            if (labels.failed) dialog.append(element('p', { class: 'failed' }, labels.failed));
        }

        dialog.append(element('p', { id: 'sr-ask-body' }, labels.body));

        if (offerAnonymous) {
            const label = element('label', { for: 'sr-ask-anonymous' });

            label.append(anonymous, document.createTextNode(labels.anonymous));
            dialog.append(label);
        }

        actions.append(decline, share);
        dialog.append(actions);
        root.append(style, dialog);

        let answered = false;

        const close = (answer) => {
            if (answered) return;

            answered = true;
            host.remove();

            try {
                previous?.focus?.({ preventScroll: true });
            } catch {
                // The element that had focus is gone.
            }

            resolve(answer);
        };

        decline.addEventListener('click', () => close({ share: false, anonymous: false }));
        share.addEventListener('click', () => close({ share: true, anonymous: offerAnonymous && anonymous.checked }));

        // Escape is a "no": the browser's cancel of a modal dialog, and the key itself, so the page's own
        // shortcuts (a modal of its own closing on Escape) stay out of it.
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            close({ share: false, anonymous: false });
        });
        root.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;

            event.preventDefault();
            event.stopPropagation();
            close({ share: false, anonymous: false });
        });

        // Closed from outside (a script calling close() on every open dialog): a "no" as well.
        dialog.addEventListener('close', () => close({ share: false, anonymous: false }));

        // Under <html>, next to <body>: a wire:navigate page swap replaces the body and the question stays.
        document.documentElement.append(host);

        // One tick later: the trigger fires inside Livewire's request hook, before Livewire opens its own modal
        // dialog with the error page, and the dialog opened last is the one on top.
        setTimeout(() => {
            if (answered) return;

            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');

            // The dialog itself takes focus, so a screen reader reads the question and a stray Enter sends nothing.
            dialog.focus();
        }, 0);
    });
}

/** A short notice at the bottom of the viewport, gone after a few seconds: in the top layer when the browser has popovers, so a modal dialog does not hide it. */
export function notice(text) {
    if (!text) return;

    const host = element('div', { 'data-session-replay-notice': '' });
    const root = host.attachShadow({ mode: 'closed' });
    const box = element('div', { class: 'notice', role: 'status', popover: 'manual' }, text);

    root.append(element('style', {}, STYLE), box);
    document.documentElement.append(host);

    try {
        box.showPopover();
    } catch {
        // No popover API: the fixed position alone.
    }

    setTimeout(() => host.remove(), 4000);
}
