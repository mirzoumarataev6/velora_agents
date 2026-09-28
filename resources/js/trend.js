/**
 * Period-over-period trend, derived from a daily series.
 *
 * WHY IT IS COMPUTED HERE AND NOT IN THE METRICS SERVICE
 * -----------------------------------------------------
 * A true trend compares this period against the one BEFORE it, which needs
 * data from outside the fetched window — and fetching it means changing
 * DashboardMetrics, which this migration is explicitly not allowed to touch.
 *
 * So this compares the two halves of the window the service already returns:
 * over 30 days, the last 15 against the previous 15. That is a real,
 * defensible number, and the UI labels it as exactly that rather than implying
 * a comparison it did not make. If a genuine previous-period comparison is
 * wanted later, it belongs in the service and this file goes away.
 *
 * Returns null when there is too little data to say anything — better than a
 * confident "+100%" derived from one message.
 */
export function halfOverHalf(values) {
    const points = (values ?? []).filter((value) => typeof value === 'number');

    if (points.length < 4) return null;

    const half = Math.floor(points.length / 2);
    const previous = points.slice(0, half);
    const current = points.slice(points.length - half);

    const sum = (list) => list.reduce((total, value) => total + value, 0);
    const before = sum(previous);
    const after = sum(current);

    // No baseline to divide by. Going from nothing to something is real, but
    // it is not a percentage, so say nothing rather than say infinity.
    if (before === 0) return null;

    return Math.round(((after - before) / before) * 1000) / 10;
}

/** Average of a numeric series, ignoring nulls. Used for response-time KPIs. */
export function meanOf(values) {
    const points = (values ?? []).filter((value) => typeof value === 'number');

    return points.length === 0 ? null : points.reduce((a, b) => a + b, 0) / points.length;
}
