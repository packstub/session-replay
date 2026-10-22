import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { TEXT, withDefaults } from '../lib/labels.js';

/** The keys of resources/lang/en/player.php, one line per 'key' => ..., nested one level under 'group' => [. */
function phpKeys(source) {
    const keys = [];
    let group = null;

    for (const line of source.split('\n')) {
        const open = line.match(/^\s{4}'([\w-]+)' => \[$/);
        const leaf = line.match(/^\s+'([\w-]+)' => '/);

        if (open) group = open[1];
        else if (line.trim() === '],') group = null;
        else if (leaf) keys.push(group ? `${group}.${leaf[1]}` : leaf[1]);
    }

    return keys.sort();
}

function jsKeys(text) {
    return Object.entries(text)
        .flatMap(([key, value]) => (typeof value === 'object' ? Object.keys(value).map((leaf) => `${key}.${leaf}`) : [key]))
        .sort();
}

test('the English defaults carry every key of lang/en/player.php, so an element without data-labels reads whole', () => {
    const php = readFileSync(new URL('../../lang/en/player.php', import.meta.url), 'utf8');

    assert.deepEqual(jsKeys(TEXT), phpKeys(php));
});

test('given labels replace the English ones group by group, a missing key keeps its English text', () => {
    const text = withDefaults({ loading: 'Lädt…', types: { error: 'Fehler' }, fields: {} });

    assert.equal(text.loading, 'Lädt…');
    assert.equal(text.types.error, 'Fehler');
    assert.equal(text.types.report, 'Report');
    assert.equal(text.fields.status, 'Status');
    assert.equal(text.details.open_logs, 'Open in logs');
    assert.equal(withDefaults(null).skipped_page, TEXT.skipped_page);
});
