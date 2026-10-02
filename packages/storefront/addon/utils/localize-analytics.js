/**
 * Translate chart payloads from `storefront/int/v1/analytics/*`.
 *
 * The API names datasets in English ("Revenue", "Orders") and uses raw order status
 * codes as the labels of the status mix chart. Both are stable identifiers, so the text
 * is looked up under `storefront.ui.analytics.*` and falls back to the server text when
 * no key exists. Only display copies are produced.
 */
const slugify = (value) =>
    String(value ?? '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();

const tr = (intl, key, fallback) => (intl && intl.exists(key) ? intl.t(key) : fallback);

export function localizeDatasetLabel(intl, label) {
    return typeof label === 'string' ? tr(intl, `storefront.ui.analytics.dataset.${slugify(label)}`, label) : label;
}

export function localizeDatasets(intl, datasets) {
    if (!Array.isArray(datasets)) {
        return datasets;
    }

    return datasets.map((dataset) => ({ ...dataset, label: localizeDatasetLabel(intl, dataset?.label) }));
}

export function localizeStatusLabels(intl, labels) {
    if (!Array.isArray(labels)) {
        return labels;
    }

    return labels.map((label) => (typeof label === 'string' ? tr(intl, `storefront.ui.analytics.status.${slugify(label)}`, label) : label));
}
