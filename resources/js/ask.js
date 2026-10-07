/**
 * The question on_error.ask puts to the person after a trigger: send the
 * replay of what just happened, or not. Plain DOM in a closed shadow root:
 * the page's styles cannot reach in, its own styles cannot leak out, and
 * rrweb cannot see inside it, so the dialog never appears in a replay (the
 * host is recorded as an empty element).
 *
 * Resolves to { share, anonymous }.
 */

const STYLE = `
:host { all: initial; }
.backdrop {
    position: fixed; inset: 0; z-index: 2147483647;
    display: flex; align-items: center; justify-content: center; padding: 16px;
    background: rgba(15, 23, 42, 0.45);
    font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color-scheme: light dark;
}
.dialog {
    box-sizing: border-box; width: 100%; max-width: 26rem; padding: 20px;
    background: #fff; color: #0f172a; border-radius: 12px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25); outline: none;
}
h2 { margin: 0 0 8px; font-size: 17px; font-weight: 600; line-height: 1.35; }
p { margin: 0 0 16px; color: #334155; }
label { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 16px; cursor: pointer; }
input { margin: 3px 0 0; width: 16px; height: 16px; flex: none; accent-color: #2563eb; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
button {
    font: inherit; font-weight: 500; padding: 8px 14px; border-radius: 8px; cursor: pointer;
    border: 1px solid #cbd5e1; background: #fff; color: #0f172a;
}
button.share { border-color: #2563eb; background: #2563eb; color: #fff; }
.dialog:focus { outline: none; }
button:focus-visible, input:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
@media (prefers-color-scheme: dark) {
    .backdrop { background: rgba(2, 6, 23, 0.6); }
    .dialog { background: #0f172a; color: #e2e8f0; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5); }
    p { color: #cbd5e1; }
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

export function ask(labels, { offerAnonymous = false } = {}) {
    return new Promise((resolve) => {
        const host = element('div', { 'data-session-replay-ask': '' });
        const root = host.attachShadow({ mode: 'closed' });
        const previous = document.activeElement;

        const style = element('style', {}, STYLE);
        const backdrop = element('div', { class: 'backdrop' });
        const dialog = element('div', { class: 'dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sr-ask-title', 'aria-describedby': 'sr-ask-body', tabindex: '-1' });
        const anonymous = element('input', { type: 'checkbox', id: 'sr-ask-anonymous' });
        const decline = element('button', { type: 'button', class: 'decline' }, labels.decline);
        const share = element('button', { type: 'button', class: 'share' }, labels.share);
        const actions = element('div', { class: 'actions' });

        dialog.append(element('h2', { id: 'sr-ask-title' }, labels.title), element('p', { id: 'sr-ask-body' }, labels.body));

        if (offerAnonymous) {
            const label = element('label', { for: 'sr-ask-anonymous' });

            label.append(anonymous, document.createTextNode(labels.anonymous));
            dialog.append(label);
        }

        actions.append(decline, share);
        dialog.append(actions);
        backdrop.append(dialog);
        root.append(style, backdrop);

        const close = (answer) => {
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

        // Keep focus inside while it is open; Escape is a "no".
        root.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' && event.key !== 'Tab') return;

            // The page's own shortcuts (a modal of its own closing on Escape) stay out of it.
            event.preventDefault();
            event.stopPropagation();

            if (event.key === 'Escape') {
                close({ share: false, anonymous: false });

                return;
            }

            const focusable = [offerAnonymous ? anonymous : null, decline, share].filter(Boolean);
            const index = focusable.indexOf(root.activeElement);
            const next = index === -1 ? (event.shiftKey ? focusable.length - 1 : 0) : (index + (event.shiftKey ? -1 : 1) + focusable.length) % focusable.length;

            focusable[next].focus();
        });

        // Under <html>, next to <body>: a wire:navigate page swap replaces the body and the question stays.
        document.documentElement.append(host);

        // The dialog itself takes focus, so a screen reader reads the question and a stray Enter sends nothing.
        dialog.focus();
    });
}
