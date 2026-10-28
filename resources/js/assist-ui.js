/**
 * What the visitor sees of Assist: the "Get help" button and form, the
 * status panel, the prompts (offer, consent, grants), the call bar, the
 * agent's named cursor, the dashed outline while the agent has control and
 * the annotation canvas. Plain DOM in a closed shadow root on a host that
 * carries data-replay-skip: the page's styles cannot reach in, the recorder
 * leaves all of it out of the recording (so the agent's cursor and strokes
 * are never mirrored back) and nothing depends on the app's CSS framework.
 * Names are text nodes; an avatar is shown only for an http(s) URL.
 */

const STYLE = `
:host { all: initial; }
.layer { position: fixed; inset: 0; pointer-events: none; z-index: 2147483646; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #0f172a; color-scheme: light dark; }
.outline { position: absolute; inset: 0; border: 3px dashed #2563eb; box-sizing: border-box; }
.ink { position: absolute; inset: 0; width: 100%; height: 100%; }
.cursor { position: absolute; left: 0; top: 0; transform: translate(-2px, -2px); transition: transform 60ms linear; will-change: transform; }
.cursor svg { display: block; width: 20px; height: 20px; filter: drop-shadow(0 1px 2px rgba(15, 23, 42, 0.4)); }
.cursor span { display: inline-block; margin: 2px 0 0 14px; padding: 2px 8px; border-radius: 999px; background: #2563eb; color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; }
.dock { position: absolute; right: 16px; bottom: 16px; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; max-width: calc(100% - 32px); }
.card { pointer-events: auto; box-sizing: border-box; width: 22rem; max-width: 100%; padding: 14px 16px; border-radius: 12px; background: #fff; color: #0f172a; box-shadow: 0 12px 36px rgba(15, 23, 42, 0.22); }
.prompt { border: 2px solid #2563eb; }
.who { display: flex; align-items: center; gap: 10px; }
.who img { width: 32px; height: 32px; border-radius: 999px; object-fit: cover; flex: none; }
.text { margin: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 12px; }
button { font: inherit; font-weight: 500; padding: 7px 13px; border-radius: 8px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; }
button.primary { border-color: #2563eb; background: #2563eb; color: #fff; }
button.danger { border-color: #dc2626; background: #dc2626; color: #fff; }
button:focus-visible, textarea:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
.launcher { pointer-events: auto; border: 0; border-radius: 999px; padding: 10px 16px; background: #2563eb; color: #fff; font-weight: 600; box-shadow: 0 8px 24px rgba(37, 99, 235, 0.35); }
label { display: block; margin: 10px 0 6px; font-size: 13px; color: #334155; }
textarea { box-sizing: border-box; width: 100%; min-height: 72px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; color: inherit; background: transparent; resize: vertical; }
.title { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
.remote { pointer-events: auto; width: 12rem; max-width: 100%; border-radius: 10px; background: #000; }
[hidden] { display: none !important; }
@media (prefers-color-scheme: dark) {
    .card { background: #0f172a; color: #e2e8f0; box-shadow: 0 12px 36px rgba(0, 0, 0, 0.5); }
    button { background: #1e293b; color: #e2e8f0; border-color: #334155; }
    label { color: #cbd5e1; }
    textarea { border-color: #334155; }
}
@media (prefers-reduced-motion: reduce) { .cursor { transition: none; } }
`;

const ARROW = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 2l13 7-6 1.5L7.5 17z" fill="#2563eb" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>';

const FADE_AFTER_MS = 4000;
const FADE_MS = 600;
const STROKE_TIMEOUT_MS = 10_000;

export function isHttpUrl(value) {
    return typeof value === 'string' && /^https?:\/\/[^\s]+$/i.test(value) && value.length <= 2000;
}

/** A label with `:name` filled in, as text. */
export function label(labels, key, name = '') {
    return String(labels[key] ?? key).replace(/:name/g, name);
}

export function createUi({ doc = document, win = window, labels }) {
    const host = doc.createElement('div');

    host.setAttribute('data-replay-skip', '');
    host.setAttribute('data-session-replay-assist', '');

    const shadow = host.attachShadow({ mode: 'closed' });
    const make = (tag, className, parent) => {
        const element = doc.createElement(tag);

        if (className) element.className = className;
        parent?.appendChild(element);

        return element;
    };

    const style = make('style', '', shadow);

    style.textContent = STYLE;

    const layer = make('div', 'layer', shadow);
    const outline = make('div', 'outline', layer);
    const canvas = make('canvas', 'ink', layer);
    const cursor = make('div', 'cursor', layer);
    const dock = make('div', 'dock', layer);
    const remote = make('video', 'remote', dock);
    const promptCard = make('div', 'card prompt', dock);
    const panel = make('div', 'card', dock);
    const launcher = make('button', 'launcher', dock);

    outline.hidden = true;
    cursor.hidden = true;
    cursor.innerHTML = ARROW;
    const cursorName = make('span', '', cursor);
    remote.hidden = true;
    remote.autoplay = true;
    remote.playsInline = true;
    remote.setAttribute('playsinline', '');
    promptCard.hidden = true;
    promptCard.setAttribute('role', 'alertdialog');
    promptCard.setAttribute('aria-label', label(labels, 'dialog'));
    panel.hidden = true;
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', label(labels, 'dialog'));
    launcher.type = 'button';
    launcher.hidden = true;
    launcher.textContent = label(labels, 'getHelp');

    const prompts = [];
    let current = null;
    let noticeTimer = null;

    function who(agent, text, parent) {
        const row = make('div', 'who', parent);

        if (agent && isHttpUrl(agent.avatar)) {
            const img = make('img', '', row);

            img.alt = '';
            img.referrerPolicy = 'no-referrer';
            img.src = agent.avatar;
        }

        const p = make('p', 'text', row);

        p.textContent = text;

        return p;
    }

    function actions(list, parent) {
        const row = make('div', 'actions', parent);

        for (const action of list) {
            const button = make('button', action.kind || '', row);

            button.type = 'button';
            button.textContent = action.label;
            button.addEventListener('click', action.onClick);
        }

        return row;
    }

    function showNextPrompt() {
        if (current || prompts.length === 0) return;

        current = prompts.shift();
        promptCard.replaceChildren();
        promptCard.hidden = false;
        ink.clear();

        const text = who(current.agent, current.text, promptCard);

        text.id = 'sr-assist-prompt';
        promptCard.setAttribute('aria-describedby', 'sr-assist-prompt');

        const row = actions(
            [
                { label: current.allow, kind: 'primary', onClick: () => answer(true) },
                { label: current.deny, onClick: () => answer(false) },
            ],
            promptCard,
        );

        row.querySelector('button')?.focus({ preventScroll: true });

        if (current.timeoutMs > 0) current.timer = setTimeout(() => answer(false), current.timeoutMs);
    }

    function answer(yes) {
        if (!current) return;

        const done = current;

        current = null;
        clearTimeout(done.timer);
        promptCard.hidden = true;
        promptCard.replaceChildren();
        done.resolve(yes);
        showNextPrompt();
    }

    // ---- annotations ------------------------------------------------------

    const ink = {
        strokes: [],
        frame: 0,
        start(x, y) {
            this.strokes.push({ points: [[x, y]], endedAt: 0, startedAt: Date.now() });
            this.draw();
        },
        move(x, y) {
            const stroke = this.strokes[this.strokes.length - 1];

            if (!stroke || stroke.endedAt) return;

            stroke.points.push([x, y]);
            this.draw();
        },
        stop() {
            const stroke = this.strokes[this.strokes.length - 1];

            if (stroke && !stroke.endedAt) stroke.endedAt = Date.now();
            this.draw();
        },
        clear() {
            this.strokes = [];
            this.paint();
        },
        draw() {
            if (!this.frame) this.frame = win.requestAnimationFrame(() => this.tick());
        },
        tick() {
            this.frame = 0;

            if (this.paint()) this.draw();
        },
        /** Paints every stroke; true while one is still visible. */
        paint() {
            const context = canvas.getContext('2d');
            const ratio = win.devicePixelRatio || 1;
            const width = Math.round(win.innerWidth * ratio);
            const height = Math.round(win.innerHeight * ratio);

            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
            }

            if (!context) return false;

            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.clearRect(0, 0, win.innerWidth, win.innerHeight);

            const now = Date.now();

            this.strokes = this.strokes.filter((stroke) => {
                // A stroke whose stop never came ends on its own.
                const ended = stroke.endedAt || (now - stroke.startedAt > STROKE_TIMEOUT_MS ? stroke.startedAt + STROKE_TIMEOUT_MS : 0);
                const age = ended ? now - ended - FADE_AFTER_MS : -1;

                return age < FADE_MS;
            });

            for (const stroke of this.strokes) {
                const ended = stroke.endedAt;
                const age = ended ? now - ended - FADE_AFTER_MS : -1;

                context.globalAlpha = age > 0 ? Math.max(0, 1 - age / FADE_MS) : 1;
                context.strokeStyle = '#dc2626';
                context.lineWidth = 4;
                context.lineCap = 'round';
                context.lineJoin = 'round';
                context.beginPath();
                stroke.points.forEach(([x, y], index) => (index ? context.lineTo(x, y) : context.moveTo(x, y)));
                if (stroke.points.length === 1) context.lineTo(stroke.points[0][0] + 0.1, stroke.points[0][1]);
                context.stroke();
            }

            context.globalAlpha = 1;

            return this.strokes.length > 0;
        },
    };

    return {
        host,

        mount() {
            if (!host.isConnected) (doc.body || doc.documentElement).appendChild(host);
        },

        /** wire:navigate swapped the body: put the host back. */
        remount() {
            this.mount();
        },

        destroy() {
            this.cancelPrompts();
            host.remove();
        },

        contains(element) {
            return element === host || host.contains(element);
        },

        get promptOpen() {
            return current !== null;
        },

        launcher(visible, onClick) {
            launcher.hidden = !visible;
            launcher.onclick = onClick || null;
        },

        /** The status panel: a line about the agent and some buttons. */
        show({ text, agent = null, actions: list = [] }) {
            clearTimeout(noticeTimer);
            panel.replaceChildren();
            panel.hidden = false;
            const line = who(agent, text, panel);

            line.setAttribute('aria-live', 'polite');
            if (list.length) actions(list, panel);
        },

        hide() {
            clearTimeout(noticeTimer);
            panel.hidden = true;
            panel.replaceChildren();
        },

        notice(text) {
            this.show({ text, actions: [{ label: label(labels, 'close'), onClick: () => this.hide() }] });
            noticeTimer = setTimeout(() => this.hide(), 8000);
        },

        /** The "Get help" form. */
        form({ onSubmit, onCancel }) {
            clearTimeout(noticeTimer);
            panel.replaceChildren();
            panel.hidden = false;

            const title = make('p', 'title', panel);

            title.textContent = label(labels, 'formTitle');
            make('p', 'text', panel).textContent = label(labels, 'formBody');

            const field = make('label', '', panel);

            field.textContent = label(labels, 'message');
            field.htmlFor = 'sr-assist-message';

            const textarea = make('textarea', '', panel);

            textarea.id = 'sr-assist-message';
            textarea.maxLength = 500;
            actions(
                [
                    { label: label(labels, 'send'), kind: 'primary', onClick: () => onSubmit(textarea.value.trim()) },
                    { label: label(labels, 'cancel'), onClick: onCancel },
                ],
                panel,
            );
            textarea.focus({ preventScroll: true });
        },

        /** A question; resolves to the answer. `timeoutMs` without one counts as no (0 waits). */
        prompt(text, { agent = null, allow = label(labels, 'allow'), deny = label(labels, 'deny'), timeoutMs = 30_000, tag = null } = {}) {
            return new Promise((resolve) => {
                prompts.push({ text, agent, allow, deny, timeoutMs, tag, resolve });
                showNextPrompt();
            });
        },

        /** Take prompts down (all, or those with `tag`), each answered no. */
        cancelPrompts(tag = null) {
            for (const prompt of prompts.filter((entry) => tag === null || entry.tag === tag)) {
                prompts.splice(prompts.indexOf(prompt), 1);
                prompt.resolve(false);
            }

            if (current && (tag === null || current.tag === tag)) answer(false);
        },

        cursor: {
            show(name) {
                cursorName.textContent = name;
                cursor.hidden = false;
            },
            move(x, y) {
                cursor.style.transform = `translate(${Math.round(x) - 2}px, ${Math.round(y) - 2}px)`;
            },
            hide() {
                cursor.hidden = true;
            },
        },

        outline(on) {
            outline.hidden = !on;
        },

        ink,

        /** The agent's media: heard only once the visitor allowed audio, shown when it has video. */
        media(stream, audible) {
            if (stream && remote.srcObject !== stream) remote.srcObject = stream;

            remote.muted = !audible;
            remote.hidden = !stream || !stream.getVideoTracks?.().length;

            if (stream && audible) remote.play?.().catch(() => {});
        },

        clearMedia() {
            remote.srcObject = null;
            remote.hidden = true;
        },
    };
}
