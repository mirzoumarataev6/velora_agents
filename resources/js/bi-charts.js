import ApexCharts from 'apexcharts';

const charts = new Map();
const locale = () => document.documentElement.lang || 'ru';
const number = value => new Intl.NumberFormat(locale(), {maximumFractionDigits: 2}).format(Number(value) || 0);
const compact = value => new Intl.NumberFormat(locale(), {notation: 'compact', maximumFractionDigits: 1}).format(Number(value) || 0);
const css = name => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();
const dark = () => document.documentElement.classList.contains('dark');
const palette = () => Array.from({length: 6}, (_, i) => css(`series-${dark() ? 'dark-' : ''}${i + 1}`));

function axisLabel(value, grain) {
    if (value == null || value === '') return '—';
    if (grain === 'quarter' || grain === 'year') return String(value);
    if (grain && /^\d{4}-\d{2}-\d{2}/.test(String(value))) {
        const options = grain === 'month' ? {month: 'short', year: 'numeric'} : {day: 'numeric', month: 'short'};
        return new Intl.DateTimeFormat(locale(), options).format(new Date(`${String(value).slice(0, 10)}T12:00:00`));
    }
    return String(value);
}

function seriesOptions(payload) {
    const {type, rows, columns} = payload;
    const dimensions = columns.filter(c => c.kind === 'dimension');
    const measures = columns.filter(c => c.kind === 'measure');
    const first = dimensions[0], second = dimensions[1];
    const labels = [...new Set(rows.map(row => axisLabel(row[first.alias], first.grain)))];
    if (type === 'donut') {
        const values = rows.map(row => Math.max(0, Number(row[measures[0].alias]) || 0));
        const slices = rows.map(row => axisLabel(row[first.alias], first.grain));
        if (values.length > 6) {
            const kept = values.slice(0, 5);
            kept.push(values.slice(5).reduce((sum, item) => sum + item, 0));
            return {labels: [...slices.slice(0, 5), payload.otherLabel], series: kept};
        }
        return {labels: slices, series: values};
    }
    if (second) {
        const names = [...new Set(rows.map(row => axisLabel(row[second.alias], second.grain)))];
        return {
            labels,
            series: names.map(name => ({name, data: labels.map(label => {
                const row = rows.find(item => axisLabel(item[first.alias], first.grain) === label && axisLabel(item[second.alias], second.grain) === name);
                return row ? Number(row[measures[0].alias]) || 0 : 0;
            })})),
        };
    }
    return {
        labels,
        series: measures.map(measure => ({name: measure.label || payload.title, data: rows.map(row => Number(row[measure.alias]) || 0)})),
    };
}

function options(payload) {
    const data = seriesOptions(payload);
    const kind = payload.type === 'column' ? 'bar' : payload.type;
    const isDonut = kind === 'donut';
    const base = {
        chart: {type: kind, height: '100%', background: 'transparent', fontFamily: 'Onest, sans-serif', toolbar: {show: false}, animations: {enabled: !matchMedia('(prefers-reduced-motion: reduce)').matches}, zoom: {enabled: false}},
        colors: palette(),
        theme: {mode: dark() ? 'dark' : 'light'},
        series: data.series,
        labels: data.labels,
        stroke: {curve: 'smooth', width: kind === 'area' || kind === 'line' ? 2.5 : 0},
        fill: kind === 'area' ? {type: 'gradient', gradient: {opacityFrom: 0.32, opacityTo: 0.03}} : {colors: palette()},
        dataLabels: {enabled: false},
        legend: {show: isDonut || data.series.length > 1, position: 'bottom', fontSize: '12px', labels: {colors: dark() ? css('night-muted') : css('muted')}},
        tooltip: {theme: dark() ? 'dark' : 'light', y: {formatter: value => number(value)}},
        noData: {text: payload.emptyLabel},
        grid: {borderColor: dark() ? css('night-raised') : css('hairline'), strokeDashArray: 4},
    };
    if (!isDonut) {
        // A categorical axis with twenty tilted dates reads like texture, not
        // time. Keep every point and the full tooltip; omit only intermediate
        // visual labels at a viewport-dependent cadence.
        const maxAxisLabels = window.innerWidth < 640 ? 4 : window.innerWidth < 1200 ? 6 : 8;
        const labelStep = payload.type === 'bar' ? 1 : Math.ceil(data.labels.length / maxAxisLabels);
        base.xaxis = {categories: data.labels, labels: {
            style: {colors: dark() ? css('night-muted') : css('muted'), fontSize: '12px'},
            rotate: 0, hideOverlappingLabels: true, trim: false,
            formatter: value => {
                const index = data.labels.indexOf(String(value));
                return index < 0 || index % labelStep === 0 || index === data.labels.length - 1 ? String(value) : '';
            },
        }, axisBorder: {show: false}, axisTicks: {show: false}, crosshairs: {show: true}};
        const measure = payload.columns.filter(column => column.kind === 'measure');
        base.yaxis = {
            labels: {formatter: compact, style: {colors: dark() ? css('night-muted') : css('muted'), fontSize: '12px'}},
            ...(measure.length === 1 && window.innerWidth >= 640 ? {title: {text: measure[0].label}} : {}),
        };
        base.plotOptions = {bar: {horizontal: payload.type === 'bar', borderRadius: 4, columnWidth: '52%'}};
        base.tooltip.x = {formatter: (_, {dataPointIndex}) => data.labels[dataPointIndex]};
        base.chart.stacked = !!payload.stacked;
    } else {
        base.plotOptions = {pie: {donut: {size: '72%', labels: {show: true, total: {show: true, label: payload.totalLabel, formatter: () => number(data.series.reduce((a, b) => a + b, 0))}}}}};
        base.tooltip.y = {formatter: value => number(value)};
    }
    return base;
}

export function mountBiCharts(root = document) {
    root.querySelectorAll('[data-bi-chart]').forEach(element => {
        if (charts.has(element)) return;
        const payload = JSON.parse(element.dataset.biChart);
        element.replaceChildren();
        const chart = new ApexCharts(element, options(payload));
        charts.set(element, {chart, payload});
        chart.render().catch(error => {
            console.error('BI chart failed to render', payload.type, error);
            element.replaceChildren();
            element.textContent = payload.emptyLabel;
        });
    });
}

export function refreshBiChart(element, payload) {
    const entry = charts.get(element);
    if (!entry) { element.dataset.biChart = JSON.stringify(payload); mountBiCharts(element.parentElement); return; }
    entry.payload = payload;
    entry.chart.updateOptions(options(payload), false, true);
}

const themeObserver = new MutationObserver(() => {
    for (const {chart, payload} of charts.values()) chart.updateOptions(options(payload), false, true);
});
themeObserver.observe(document.documentElement, {attributes: true, attributeFilter: ['class', 'lang']});
