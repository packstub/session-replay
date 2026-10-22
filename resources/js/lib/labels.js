// The player's strings in English, the same keys as resources/lang/en/player.php: the Blade component passes the
// app's language as data-labels, an element mounted by hand may carry none, and a key a given set leaves out keeps
// its English text so that nothing reads "undefined".
export const TEXT = {
    loading: 'Loading the recording…',
    forbidden: 'You are not allowed to watch this recording.',
    failed: 'The recording could not be loaded.',
    just_started: 'This recording just started; there is nothing to play yet.',
    no_snapshot: 'This recording has no page snapshot to play.',
    none_selected: 'Nothing of the selected kinds.',
    none_marked: 'Nothing was marked in this recording.',
    types: {
        error: 'Error',
        request: 'Request',
        console: 'Console',
        'rage-click': 'Rage click',
        navigation: 'Page',
        vital: 'Vital',
        custom: 'Custom',
        report: 'Report',
    },
    copy_link: 'Copy link to this moment',
    link_copied: 'Link copied',
    skipped_page: 'A page the app does not record',
    details: {
        open_logs: 'Open in logs',
        copy_report: 'Copy report id',
        report_copied: 'Report id copied',
        skipped_hint: 'The page matched except or except_routes, or recordWhen() said no. Nothing of it is in the recording, which continues on the next recorded page.',
    },
    fields: {
        source: 'Source',
        status: 'Status',
        method: 'Method',
        path: 'Path',
        url: 'URL',
        title: 'Title',
        exception: 'Exception',
        report: 'Report id',
        durationMs: 'Lasted',
        level: 'Level',
        name: 'Name',
        value: 'Value',
        rating: 'Rating',
        x: 'X',
        y: 'Y',
    },
};

/** The given strings over the English ones, group by group. */
export function withDefaults(given) {
    const text = { ...TEXT, ...(given || {}) };

    for (const group of ['types', 'details', 'fields']) text[group] = { ...TEXT[group], ...(given?.[group] || {}) };

    return text;
}
