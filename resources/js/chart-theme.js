/**
 * The house chart style.
 *
 * Every chart in the product goes through here, so the projector rules are
 * applied once rather than remembered twenty times:
 *
 *   · colours are READ FROM THE CSS TOKENS at render time, never hardcoded —
 *     which is what lets one palette edit reach every chart, and what makes a
 *     theme switch able to recolour charts that are already on screen
 *   · lines 2.5–3px, because a 1px series disappears in a lit hall
 *   · axis labels 13px minimum at weight 500 — the system's type floor
 *   · HORIZONTAL gridlines only; vertical ones add ink without adding meaning
 *   · animations off. A chart that animates on every theme toggle or filter
 *     change reads as a stutter on a projector, and Apex's default 800ms
 *     entrance is long enough to be watched rather than felt.
 *
 * Reading tokens through getComputedStyle rather than importing them keeps ONE
 * source of truth: if the token moves, the chart follows with no second edit.
 */

/**
 * Resolve a list of CSS custom properties against an element.
 *
 * Scoped to an element rather than always to :root, because the design-system
 * gallery renders theme islands — a chart inside a `.dark` pane must resolve
 * the dark palette, not the page's.
 */
export function readTokens(element = document.documentElement) {
    const styles = getComputedStyle(element);
    const token = (name) => styles.getPropertyValue(name).trim();

    return {
        series: [
            token('--color-chart-1'),
            token('--color-chart-2'),
            token('--color-chart-3'),
            token('--color-chart-4'),
            token('--color-chart-5'),
        ].filter(Boolean),
        total: token('--color-chart-total'),
        previous: token('--color-chart-previous'),
        grid: token('--color-chart-grid'),
        axis: token('--color-chart-axis'),
        text: token('--color-text'),
        muted: token('--color-muted'),
        surface: token('--color-surface'),
        border: token('--color-border'),
        channel: {
            telegram: token('--color-channel-telegram'),
            whatsapp: token('--color-channel-whatsapp'),
            instagram: token('--color-channel-instagram'),
        },
    };
}

/** Is stage mode on for this subtree? */
function isStage(element) {
    return element.closest?.('[data-mode="stage"]') !== null
        || document.documentElement.getAttribute('data-mode') === 'stage';
}

/**
 * The base options every chart starts from.
 *
 * Callers merge their own on top; anything not overridden here is Apex's
 * default, which is why the list below is only the things that matter.
 */
export function baseOptions(tokens, { element = document.documentElement } = {}) {
    const stage = isStage(element);

    return {
        chart: {
            fontFamily: 'inherit',
            foreColor: tokens.axis,
            toolbar: { show: false },
            zoom: { enabled: false },
            // See the note at the top: motion is a liability here, not polish.
            animations: { enabled: false },
            parentHeightOffset: 0,
        },
        // 3px in stage mode. A 2.5px line survives a projector; 3px survives a
        // bad one, and stage mode is the setting that assumes the worst.
        stroke: {
            width: stage ? 3 : 2.5,
            curve: 'smooth',
            lineCap: 'round',
        },
        grid: {
            borderColor: tokens.grid,
            strokeDashArray: 0,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
            padding: { left: 8, right: 8, top: 0, bottom: 0 },
        },
        dataLabels: { enabled: false },
        legend: { show: false },
        xaxis: {
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: {
                style: {
                    colors: tokens.axis,
                    fontSize: stage ? '14px' : '13px',
                    fontWeight: 500,
                },
            },
            crosshairs: { stroke: { color: tokens.grid, dashArray: 4 } },
        },
        yaxis: {
            labels: {
                style: {
                    colors: tokens.axis,
                    fontSize: stage ? '14px' : '13px',
                    fontWeight: 500,
                },
            },
        },
        tooltip: {
            theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
            style: { fontSize: '13px' },
        },
        states: {
            hover: { filter: { type: 'lighten', value: 0.06 } },
            active: { filter: { type: 'none' } },
        },
    };
}

/**
 * Deep-merge chart options. Apex's config is a nested plain object, so a
 * shallow spread would silently drop half of `xaxis` the moment a caller sets
 * one property on it.
 */
export function mergeOptions(base, override) {
    if (!override) return base;

    const out = { ...base };

    for (const [key, value] of Object.entries(override)) {
        out[key] = value && typeof value === 'object' && !Array.isArray(value)
            ? mergeOptions(base[key] ?? {}, value)
            : value;
    }

    return out;
}
