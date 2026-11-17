import assert from 'node:assert/strict';
import { test } from 'node:test';
import { POINT_IDLE_MS, createUi } from '../assist-ui.js';

// assist-ui.js PROMPT_ARM_MS.
const PROMPT_ARM_MS = 500;

// ---- a small fake DOM: enough for the visitor's panel and its prompts ----------------

class FakeNode {
    constructor(doc, tagName) {
        this.doc = doc;
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.parent = null;
        this.attributes = {};
        this.listeners = {};
        this.style = {};
        this.hidden = false;
        this.className = '';
        this.textContent = '';
    }

    appendChild(child) {
        child.parent = this;
        this.children.push(child);

        return child;
    }

    replaceChildren(...children) {
        this.children = [];
        for (const child of children) this.appendChild(child);
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    getAttribute(name) {
        return this.attributes[name] ?? null;
    }

    addEventListener(type, fn) {
        (this.listeners[type] ||= []).push(fn);
    }

    click() {
        for (const fn of this.listeners.click || []) fn({ type: 'click' });
    }

    focus() {
        this.doc.activeElement = this;
    }

    attachShadow() {
        this.shadow = new FakeNode(this.doc, '#shadow');

        return this.shadow;
    }

    getContext() {
        return null;
    }

    contains(node) {
        for (let current = node; current; current = current.parent) if (current === this) return true;

        return false;
    }

    remove() {}

    get isConnected() {
        return true;
    }

    querySelector(selector) {
        return [...this.all()].find((node) => node.tagName === selector.toUpperCase()) ?? null;
    }

    /** Every descendant, depth first. */
    *all() {
        for (const child of this.children) {
            yield child;
            yield* child.all();
        }
    }
}

function fakeUi() {
    let clock = 10_000;
    const doc = { activeElement: null };

    doc.createElement = (tag) => new FakeNode(doc, tag);
    doc.body = new FakeNode(doc, 'body');

    const win = { innerWidth: 1280, innerHeight: 800, devicePixelRatio: 1, requestAnimationFrame: () => 1 };
    const ui = createUi({ doc, win, labels: { allow: 'Allow', deny: 'Deny', dialog: 'Help' }, now: () => clock });
    const nodes = () => [...ui.host.shadow.all()];
    const card = () => nodes().find((node) => node.className === 'card prompt');
    const button = (text) => nodes().find((node) => node.tagName === 'BUTTON' && node.textContent === text && card().contains(node));

    return { ui, doc, card, button, advance: (ms) => (clock += ms) };
}

test('a new question takes the focus to the card, never to Allow, and is described for screen readers', () => {
    const { ui, doc, card, button } = fakeUi();

    ui.prompt('Ana asks to control this page.', { timeoutMs: 0 });

    assert.equal(card().hidden, false);
    assert.notEqual(doc.activeElement, button('Allow'));
    assert.equal(doc.activeElement, card());
    assert.equal(card().getAttribute('role'), 'alertdialog');
    assert.equal(card().getAttribute('aria-describedby'), 'sr-assist-prompt');
});

test('a press in the first half second after a question shows answers nothing', async () => {
    const { ui, button, advance } = fakeUi();
    let answer = null;

    ui.prompt('Ana asks to control this page.', { timeoutMs: 0 }).then((yes) => (answer = yes));

    // Disabled at first (a key press does nothing), enabled once armed.
    assert.equal(button('Allow').disabled, true);
    assert.equal(button('Deny').disabled, true);
    await new Promise((resolve) => setTimeout(resolve, PROMPT_ARM_MS + 50));
    assert.equal(button('Allow').disabled, false);

    // The visitor was typing: an Enter or a Space right as the prompt appears.
    advance(PROMPT_ARM_MS - 100);
    button('Allow').click();
    await Promise.resolve();
    assert.equal(answer, null);
    assert.equal(ui.promptOpen, true);

    advance(200);
    button('Allow').click();
    await Promise.resolve();
    assert.equal(answer, true);
    assert.equal(ui.promptOpen, false);
});

test('taking a prompt down from code is never delayed', async () => {
    const { ui } = fakeUi();
    let answer = null;

    ui.prompt('Ana asks to control this page.', { timeoutMs: 0, tag: 'control' }).then((yes) => (answer = yes));
    ui.cancelPrompts('control');
    await Promise.resolve();

    assert.equal(answer, false);
});

// ---- the agent's cursor: pointing and control share one element -----------------------

test('the pointer shows the agent\'s named cursor, and goes when told, when a question shows and after a short idle', async () => {
    const { ui } = fakeUi();
    const cursor = () => [...ui.host.shadow.all()].find((node) => node.className === 'cursor');
    const name = () => cursor().children.at(-1).textContent;

    assert.equal(cursor().hidden, true);

    ui.pointer.move('Ana', 100, 50);
    assert.equal(cursor().hidden, false);
    assert.equal(name(), 'Ana');
    assert.equal(cursor().style.transform, 'translate(98px, 48px)');
    assert.equal(ui.pointer.shown, true);

    ui.pointer.hide();
    assert.equal(cursor().hidden, true);

    // A question takes it down, and none is drawn over one.
    ui.pointer.move('Ana', 100, 50);
    ui.prompt('Ana asks to talk.', { timeoutMs: 0, tag: 'media' });
    assert.equal(cursor().hidden, true);
    ui.pointer.move('Ana', 120, 60);
    assert.equal(cursor().hidden, true);
    ui.cancelPrompts();

    // Left lying, it goes on its own.
    ui.pointer.move('Ana', 100, 50);
    await new Promise((resolve) => setTimeout(resolve, POINT_IDLE_MS + 100));
    assert.equal(cursor().hidden, true);
    assert.equal(ui.pointer.shown, false);
});

test('one cursor: control takes it over from the pointer, and neither hides the other\'s', () => {
    const { ui } = fakeUi();
    const cursor = () => [...ui.host.shadow.all()].find((node) => node.className === 'cursor');

    ui.pointer.move('Ana', 100, 50);
    ui.cursor.show('Ana');
    ui.cursor.move(10, 10);

    // The agent has control: a late pointer message moves nothing and hides nothing.
    ui.pointer.move('Ana', 500, 500);
    ui.pointer.hide();
    assert.equal(cursor().hidden, false);
    assert.equal(cursor().style.transform, 'translate(8px, 8px)');

    ui.cursor.hide();
    assert.equal(cursor().hidden, true);

    // And the other way: hiding the control cursor leaves a pointer alone.
    ui.pointer.move('Ana', 100, 50);
    ui.cursor.hide();
    assert.equal(cursor().hidden, false);
    ui.pointer.hide();
});

test('the cursor lives in the panel\'s host, which the recorder skips: the pointer is never recorded or mirrored back', () => {
    const { ui } = fakeUi();
    const cursor = [...ui.host.shadow.all()].find((node) => node.className === 'cursor');

    assert.equal(ui.host.getAttribute('data-replay-skip'), '');
    assert.ok(cursor);
});
