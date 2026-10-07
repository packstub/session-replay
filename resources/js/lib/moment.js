/**
 * The link to a moment of a replay: the page's own URL with ?t=<seconds>,
 * which the player opens at. Pure, tested with node --test.
 */
export function momentUrl(href, milliseconds) {
    const url = new URL(href);
    const seconds = Math.floor(Math.max(0, Number(milliseconds) || 0) / 1000);

    url.hash = '';

    if (seconds > 0) url.searchParams.set('t', String(seconds));
    else url.searchParams.delete('t');

    return url.toString();
}
