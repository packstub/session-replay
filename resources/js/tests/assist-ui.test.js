import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createUi } from '../assist-ui.js';

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
