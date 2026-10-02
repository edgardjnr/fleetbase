/**
 * Translate payloads from `metrics/dev/*` (developer dashboard widgets).
 *
 * The API builds dataset names ("Requests", "Sent", ...), placeholder labels ("unknown")
 * and activity types in English, plus day labels formatted as "M j" (e.g. "Oct 1").
 * Text is looked up under `developers.ui.api.*` by a slug of the server value and falls
 * back to the server text when no key exists; day labels are re-formatted in the active
 * locale only when they match the "M j" shape. Only display copies are produced.
 */
const slugify = (value) =>
    String(value ?? '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAY_LABEL = /^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{1,2})$/;

const tr = (intl, key, fallback) => (intl && intl.exists(key) ? intl.t(key) : fallback);

export function localizeDevLabel(intl, group, value) {
    return typeof value === 'string' ? tr(intl, `developers.ui.api.${group}.${slugify(value)}`, value) : value;
}

export function localizeDevDatasets(intl, datasets) {
    if (!Array.isArray(datasets)) {
        return datasets;
    }

    return datasets.map((dataset) => ({ ...dataset, label: localizeDevLabel(intl, 'dataset', dataset?.label) }));
}

export function localizeDayLabels(intl, labels) {
    if (!Array.isArray(labels) || !intl || typeof intl.formatDate !== 'function') {
        return labels;
    }

    return labels.map((label) => {
        const match = typeof label === 'string' ? label.match(DAY_LABEL) : null;
        if (!match) {
            return label;
        }

        try {
            const date = new Date(2000, MONTHS.indexOf(match[1]), parseInt(match[2], 10));
            return intl.formatDate(date, { month: 'short', day: 'numeric' });
        } catch {
            return label;
        }
    });
}
