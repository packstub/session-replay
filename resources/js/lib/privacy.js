/**
 * Privacy helpers for what the recorder sends besides the DOM: URLs lose the
 * values of query parameters that carry secrets (password-reset and API
 * tokens, signed-URL signatures, OAuth codes), and hidden inputs lose their
 * values. Pure functions, tested with node --test.
 */

const NODE_ELEMENT = 2;
const EVENT_FULL_SNAPSHOT = 2;
const EVENT_INCREMENTAL = 3;
const EVENT_PLUGIN = 6;
const SOURCE_MUTATION = 0;

export const REDACTED = 'redacted';

function nameSet(names) {
    return new Set((names || []).map((name) => String(name).toLowerCase()));
}

/** The URL with the value of every listed query parameter (any case) replaced. Unparseable input comes back as is. */
export function redactUrl(url, names, base = undefined) {
    const redacted = nameSet(names);

    if (!url || redacted.size === 0) return url;

    let parsed;

    try {
        parsed = new URL(url, base);
    } catch {
        return url;
    }

    let changed = false;

    for (const key of [...parsed.searchParams.keys()]) {
        if (redacted.has(key.toLowerCase()) && parsed.searchParams.get(key) !== REDACTED) {
            parsed.searchParams.set(key, REDACTED);
            changed = true;
        }
    }

    return changed ? parsed.toString() : url;
}

/** Every http(s) URL inside a text (a stack trace, a message) redacted the same way. */
export function redactUrls(text, names) {
    if (!text || nameSet(names).size === 0) return text;

    // A stack frame ends the URL with :line:column, which is not part of the query.
    return String(text).replace(/(https?:\/\/[^\s"'<>()]+?)((?::\d+){1,2})?(?=[\s"'<>()]|$)/g, (match, url, position = '') => redactUrl(url, names) + position);
}

const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'xlink:href'];
// Lists of URLs: "a.jpg?sig=1 1x, b.jpg?sig=2 2x" (srcset, imagesrcset) and space-separated pings.
const URL_LIST_ATTRIBUTES = ['srcset', 'imagesrcset'];

/** Every URL of a srcset ("url descriptor, url descriptor") redacted. */
function redactSrcset(value, names, base) {
    return value
        .split(',')
        .map((entry) => {
            const [, space = '', url = '', rest = ''] = entry.match(/^(\s*)(\S*)(.*)$/s) || [];

            return space + redactUrl(url, names, base) + rest;
        })
        .join(',');
}

/**
 * Redact the URLs the page itself carries: links, images, form actions, in a
 * snapshot, in the nodes a mutation adds and in changed attributes (a skip
 * link to the current page, a signed link in a list). Mutates.
 */
export function redactUrlAttributes(event, names, base = undefined) {
    if (nameSet(names).size === 0) return event;

    const redactIn = (attributes) => {
        if (!attributes) return;

        for (const name of URL_ATTRIBUTES) {
            if (typeof attributes[name] === 'string' && attributes[name].includes('?')) attributes[name] = redactUrl(attributes[name], names, base);
        }

        for (const name of URL_LIST_ATTRIBUTES) {
            if (typeof attributes[name] === 'string' && attributes[name].includes('?')) attributes[name] = redactSrcset(attributes[name], names, base);
        }

        if (typeof attributes.ping === 'string' && attributes.ping.includes('?')) {
            attributes.ping = attributes.ping
                .split(/\s+/)
                .map((url) => redactUrl(url, names, base))
                .join(' ');
        }
    };

    for (const root of rootsOf(event)) walk(root, (node) => node.type === NODE_ELEMENT && redactIn(node.attributes));

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION) {
        for (const change of event.data.attributes || []) redactIn(change.attributes);
    }

    return event;
}

function rootsOf(event) {
    const roots = [];

    if (event.type === EVENT_FULL_SNAPSHOT && event.data && event.data.node) roots.push(event.data.node);

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION) {
        for (const add of event.data.adds || []) if (add.node) roots.push(add.node);
    }

    return roots;
}

function walk(node, visit) {
    const stack = [node];

    while (stack.length) {
        const current = stack.pop();

        visit(current);

        if (current.childNodes) for (const child of current.childNodes) stack.push(child);
    }
}

/**
 * Drop the value of every <input type="hidden"> in a snapshot or in the nodes
 * a mutation adds: rrweb masks what people type, not what the page put there
 * (a CSRF token, an id, a signature). Mutates.
 */
export function dropHiddenValues(event) {
    for (const root of rootsOf(event)) {
        walk(root, (node) => {
            if (node.type === NODE_ELEMENT && node.tagName === 'input' && node.attributes && String(node.attributes.type || '').toLowerCase() === 'hidden') {
                delete node.attributes.value;
            }
        });
    }

    return event;
}

/**
 * What rrweb masks for privacy.mask_all_inputs. rrweb's own "all inputs"
 * list names input types one by one and leaves out hidden and file inputs:
 * a value a script writes into a hidden input later (Livewire, Alpine) and
 * the name of a chosen file ("C:\\fakepath\\passport.pdf") went out in clear.
 * By tag, every input is masked whatever its type; checkboxes and radios keep
 * their checked state. Hidden and file inputs are masked even when the
 * setting is off, like passwords.
 */
export function inputMaskOptions(maskAllInputs) {
    return maskAllInputs ? { input: true, textarea: true, select: true, password: true, hidden: true, file: true } : { password: true, hidden: true, file: true };
}

/**
 * Text the page shows through attributes rather than text nodes: rrweb masks
 * text nodes only, so with privacy.mask_all_text or inside a masked element
 * an avatar's alt, a tooltip, a placeholder or a button's caption stayed
 * readable.
 */
export const TEXT_ATTRIBUTES = ['alt', 'title', 'aria-label', 'aria-description', 'aria-valuetext', 'placeholder'];
const CAPTION_INPUT_TYPES = new Set(['submit', 'button', 'reset']);

function maskText(value) {
    return typeof value === 'string' ? value.replace(/\S/g, '*') : value;
}

/**
 * Mask the text attributes of every element that isMasked(id) says is masked,
 * in a snapshot, in the nodes a mutation adds and in changed attributes.
 * Mutates.
 */
export function maskTextAttributes(event, isMasked) {
    const maskIn = (attributes, id, tagName) => {
        if (!attributes) return;

        const names = TEXT_ATTRIBUTES.filter((name) => typeof attributes[name] === 'string');
        // The caption of <input type="submit" value="Remove Jane Doe">.
        const caption = tagName === 'input' && typeof attributes.value === 'string' && CAPTION_INPUT_TYPES.has(String(attributes.type || '').toLowerCase());

        // Asked only for elements that carry such text: the lookup walks up the page.
        if ((names.length === 0 && !caption) || !isMasked(id)) return;

        for (const name of names) attributes[name] = maskText(attributes[name]);

        if (caption) attributes.value = maskText(attributes.value);
    };

    for (const root of rootsOf(event)) walk(root, (node) => node.type === NODE_ELEMENT && maskIn(node.attributes, node.id, node.tagName));

    if (event.type === EVENT_INCREMENTAL && event.data && event.data.source === SOURCE_MUTATION) {
        for (const change of event.data.attributes || []) maskIn(change.attributes, change.id, null);
    }

    return event;
}

/**
 * URLs inside a plugin's payload (the console plugin's messages and stack
 * frames), redacted like the error markers: rrweb's console plugin reports
 * uncaught errors too, so without this the event stream carried what the
 * marker had redacted. Mutates.
 */
export function redactPluginUrls(event, names) {
    if (event.type !== EVENT_PLUGIN || !event.data || !event.data.payload || nameSet(names).size === 0) return event;

    const payload = event.data.payload;

    for (const key of ['payload', 'trace']) {
        if (Array.isArray(payload[key])) payload[key] = payload[key].map((entry) => (typeof entry === 'string' ? redactUrls(entry, names) : entry));
    }

    return event;
}
