/**
 * Remote control on the visitor's page: what the agent's pointer and
 * keyboard messages do once the visitor granted control. Synthetic events
 * only (elementFromPoint, MouseEvent, the native value setter and an `input`
 * event, which Alpine, Livewire and React all notice). Synthetic events carry
 * no user activation, so the browser itself refuses popups, file pickers, the
 * clipboard and fullscreen; nothing here works around that.
 *
 * Refused always: password fields, the Assist UI itself, file inputs. Refused
 * unless the visitor allowed it separately: typing into a field the agent sees
 * masked (`maskedAllowed()`). The visitor always wins: any real pointer or key
 * activity pauses the agent for a second (`visitorActivity()`).
 *
 * Everything the DOM offers is passed in (`win`, `doc`), so it runs in Node
 * against small fakes for the tests.
 */

const PAUSE_MS = 1000;
const NOT_TYPABLE = new Set(['button', 'checkbox', 'radio', 'file', 'submit', 'image', 'reset', 'hidden', 'range', 'color']);
const FOCUSABLE = 'input, textarea, select, button, a[href], [tabindex], [contenteditable]';

export class RemoteControl {
    /**
     * @param {object} options
     * @param {Window} options.win
     * @param {Document} options.doc
     * @param {(element: Element) => boolean} options.isOwn  inside the Assist UI
     * @param {(id: number) => Node|null} options.node  rrweb's mirror
     * @param {(element: Element) => boolean} options.isMasked  the agent sees this field's value masked
     * @param {() => boolean} [options.maskedAllowed]  the visitor allowed typing into masked fields
     * @param {(x: number, y: number) => void} [options.onCursor]
     * @param {() => number} [options.now]
     */
    constructor({ win, doc, isOwn, node, isMasked, maskedAllowed = () => false, onCursor = () => {}, now = () => Date.now() }) {
        this.win = win;
        this.doc = doc;
        this.isOwn = isOwn;
        this.node = node;
        this.isMasked = isMasked;
        this.maskedAllowed = maskedAllowed;
        this.onCursor = onCursor;
        this.now = now;
        this.x = 0;
        this.y = 0;
        this.pausedUntil = 0;
        this.hovered = null;
        this.dragging = null;
        // A field the agent typed into: it gets its `change` when the agent moves on.
        this.edited = null;
    }

    /** A real pointer or key event of the visitor's: the agent waits a second. */
    visitorActivity() {
        this.pausedUntil = this.now() + PAUSE_MS;
    }

    get paused() {
        return this.now() < this.pausedUntil;
    }

    /** The element under a point, through open shadow roots; null for the Assist UI or nothing. */
    hit(x, y) {
        let element = this.doc.elementFromPoint(x, y);

        while (element && element.shadowRoot && typeof element.shadowRoot.elementFromPoint === 'function') {
            const inner = element.shadowRoot.elementFromPoint(x, y);

            if (!inner || inner === element) break;

            element = inner;
        }

        return element;
    }

    fire(target, type, extra = {}) {
        const pointer = type.startsWith('pointer');
        const Event = pointer && this.win.PointerEvent ? this.win.PointerEvent : this.win.MouseEvent;

        target.dispatchEvent(
            new Event(type, {
                bubbles: true,
                cancelable: true,
                composed: true,
                view: this.win,
                clientX: this.x,
                clientY: this.y,
                button: 0,
                ...(pointer ? { pointerId: 1, pointerType: 'mouse', isPrimary: true } : {}),
                ...extra,
            }),
        );
    }

    /** Every action starts here: the visitor's own activity and the Assist UI come first. */
    target(x, y) {
        if (this.paused) return { ignored: 'visitor' };

        this.x = x;
        this.y = y;
        this.onCursor(x, y);

        const element = this.hit(x, y);

        if (!element) return { refused: 'nothing' };
        if (this.isOwn(element)) return { refused: 'own_ui' };

        return { element };
    }

    move(x, y) {
        const { element, ...result } = this.target(x, y);

        if (!element) return result.refused === 'own_ui' || result.refused === 'nothing' ? { ok: true } : result;

        if (element !== this.hovered) {
            if (this.hovered) {
                this.fire(this.hovered, 'pointerout', { relatedTarget: element });
                this.fire(this.hovered, 'mouseout', { relatedTarget: element });
            }

            this.fire(element, 'pointerover', { relatedTarget: this.hovered });
            this.fire(element, 'mouseover', { relatedTarget: this.hovered });
            this.hovered = element;
        }

        this.fire(element, 'pointermove');
        this.fire(element, 'mousemove', this.dragging ? { buttons: 1 } : {});

        return { ok: true };
    }

    click(x, y) {
        const { element, ...result } = this.target(x, y);

        if (!element) return result;

        this.settle(element);
        this.fire(element, 'pointerdown', { buttons: 1 });
        this.fire(element, 'mousedown', { buttons: 1 });
        element.closest?.(FOCUSABLE)?.focus?.({ preventScroll: true });
        this.fire(element, 'pointerup');
        this.fire(element, 'mouseup');
        this.fire(element, 'click', { detail: 1 });

        return { ok: true };
    }

    dragStart(x, y) {
        const { element, ...result } = this.target(x, y);

        if (!element) return result;

        this.settle(element);
        this.dragging = element;
        this.fire(element, 'pointerdown', { buttons: 1 });
        this.fire(element, 'mousedown', { buttons: 1 });

        return { ok: true };
    }

    drag(x, y) {
        if (!this.dragging) return { refused: 'not_dragging' };

        return this.move(x, y);
    }

    dragStop() {
        if (!this.dragging) return { refused: 'not_dragging' };

        const element = this.hit(this.x, this.y);
        const target = element && !this.isOwn(element) ? element : this.dragging;

        this.dragging = null;
        this.fire(target, 'pointerup');
        this.fire(target, 'mouseup');

        return { ok: true };
    }

    /** Scroll the first scrollable ancestor of what is under the cursor, or the page. */
    scroll(dx, dy) {
        if (this.paused) return { ignored: 'visitor' };

        let element = this.hit(this.x, this.y);

        if (element && this.isOwn(element)) element = null;

        while (element && element !== this.doc.body && element !== this.doc.documentElement) {
            if (this.scrolls(element, dx, dy)) {
                scrollBy(element, dx, dy);

                return { ok: true };
            }

            element = element.parentElement;
        }

        scrollBy(this.doc.scrollingElement || this.doc.documentElement, dx, dy);

        return { ok: true };
    }

    scrolls(element, dx, dy) {
        const style = this.win.getComputedStyle(element);
        const can = (overflow) => overflow === 'auto' || overflow === 'scroll' || overflow === 'overlay';

        return (dy !== 0 && can(style.overflowY) && element.scrollHeight > element.clientHeight) || (dx !== 0 && can(style.overflowX) && element.scrollWidth > element.clientWidth);
    }

    /** An element the agent names by its rrweb id, if it may act on it. */
    element(id) {
        if (this.paused) return { ignored: 'visitor' };

        const node = this.node(id);

        if (!node || node.nodeType !== 1) return { refused: 'unknown_node' };
        if (this.isOwn(node)) return { refused: 'own_ui' };

        return { element: node };
    }

    focus(id) {
        const { element, ...result } = this.element(id);

        if (!element) return result;

        this.settle(element);
        element.focus?.({ preventScroll: true });

        return { ok: true };
    }

    /** Whether the agent may type into this element: { refused } or null. */
    typable(element, tag) {
        if (tag === 'input' && String(element.type || '').toLowerCase() === 'password') return { refused: 'password' };
        if (tag === 'input' && NOT_TYPABLE.has(String(element.type || '').toLowerCase())) return { refused: 'unsupported' };
        if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') return { refused: 'unsupported' };
        if (element.disabled || element.readOnly) return { refused: 'readonly' };
        if (this.isMasked(element) && !this.maskedAllowed()) return { refused: 'masked' };

        return null;
    }

    input(id, value) {
        const { element, ...result } = this.element(id);

        if (!element) return result;

        const tag = String(element.tagName || '').toLowerCase();

        if (tag === 'select') return { refused: 'unsupported' };

        const refused = this.typable(element, tag);

        if (refused) return refused;

        const max = typeof element.maxLength === 'number' && element.maxLength >= 0 ? element.maxLength : Infinity;

        this.settle(element);
        this.setValue(element, tag === 'textarea' ? this.win.HTMLTextAreaElement : this.win.HTMLInputElement, value.slice(0, max));
        this.edited = element;

        return { ok: true };
    }

    select(id, value) {
        const { element, ...result } = this.element(id);

        if (!element) return result;

        const tag = String(element.tagName || '').toLowerCase();

        if (tag !== 'select') return { refused: 'unsupported' };

        const refused = this.typable(element, tag);

        if (refused) return refused;
        if (![...(element.options || [])].some((option) => option.value === value)) return { refused: 'unknown_option' };

        this.settle(element);
        this.setValue(element, this.win.HTMLSelectElement, value);
        this.dispatch(element, 'change');

        return { ok: true };
    }

    /** The native setter, so a framework that wraps `value` still sees the change, then `input`. */
    setValue(element, Type, value) {
        const setter = Type && Object.getOwnPropertyDescriptor(Type.prototype, 'value')?.set;

        if (setter) setter.call(element, value);
        else element.value = value;

        this.dispatch(element, 'input');
    }

    dispatch(element, type) {
        element.dispatchEvent(new this.win.Event(type, { bubbles: true, composed: true }));
    }

    /** The field the agent typed into gets its `change` once the agent acts elsewhere. */
    settle(next = null) {
        if (this.edited && this.edited !== next) this.dispatch(this.edited, 'change');
        if (this.edited !== next) this.edited = null;
    }

    /** Control ended: the last edit settles, a drag is let go. */
    release() {
        this.settle(null);

        if (this.dragging) {
            this.fire(this.dragging, 'pointerup');
            this.fire(this.dragging, 'mouseup');
            this.dragging = null;
        }

        this.hovered = null;
    }
}

function scrollBy(element, dx, dy) {
    if (typeof element.scrollBy === 'function') element.scrollBy({ left: dx, top: dy });
    else {
        element.scrollLeft += dx;
        element.scrollTop += dy;
    }
}

/**
 * Whether the agent sees this field's value masked: what rrweb masks with the
 * recorder's options (every input, textarea and select with
 * privacy.mask_all_inputs; passwords, hidden and file inputs always), and
 * fields inside privacy.ignore_selector, whose typing is never recorded.
 */
export function maskedField(element, { maskAllInputs = true, ignoreSelector = null } = {}) {
    const tag = String(element.tagName || '').toLowerCase();
    const type = String(element.type || '').toLowerCase();

    if (tag === 'input' && ['password', 'hidden', 'file'].includes(type)) return true;
    if (maskAllInputs && ['input', 'textarea', 'select'].includes(tag)) return true;

    try {
        return !!(ignoreSelector && element.closest?.(ignoreSelector));
    } catch {
        return true;
    }
}
