/**
 * Translate English text that the Fleet-Ops API builds on the server (getting-started checklist,
 * analytics chart labels, issue timeline events).
 *
 * The API is not rebuilt with the console, so the text is looked up on the frontend from stable
 * identifiers in the payload (step/recommendation `key`, dataset axis, event `type`/`meta.field`)
 * and always falls back to the server text when no translation key exists. Values that go back to
 * the API are never changed — only display copies are returned.
 */
const slugify = (value) =>
    String(value ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

const tr = (intl, key, fallback, params = {}) => (intl && intl.exists(key) ? intl.t(key, params) : fallback);

/** Option label from `fleet-ops.ui.options.<list>.<value>.label`, or null. */
export function optionLabel(intl, list, value) {
    if (!intl || value === null || value === undefined || typeof value === 'object') {
        return null;
    }

    const key = `fleet-ops.ui.options.${list}.${slugify(value)}.label`;
    return intl.exists(key) ? intl.t(key) : null;
}

/**
 * Getting-started payload from `fleet-ops/getting-started/status`
 * (keys under `fleet-ops.ui.getting-started.*`).
 */
export function localizeGettingStarted(intl, data) {
    if (!data || typeof data !== 'object' || !intl) {
        return data;
    }

    const base = 'fleet-ops.ui.getting-started';
    const steps = (data.steps ?? []).map((step) => {
        const k = `${base}.step.${slugify(step?.key)}`;
        return { ...step, title: tr(intl, `${k}.title`, step.title), description: tr(intl, `${k}.description`, step.description) };
    });
    const recommendations = (data.recommendations ?? []).map((feature) => {
        const k = `${base}.recommendation.${slugify(feature?.key)}`;
        return { ...feature, title: tr(intl, `${k}.title`, feature.title), description: tr(intl, `${k}.description`, feature.description) };
    });

    return { ...data, steps, recommendations };
}

const MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };

/**
 * Chart day labels formatted by PHP as `M j` ("Oct 1") are re-formatted in the active locale.
 * Anything that does not match is returned unchanged.
 */
export function localizeDayLabels(intl, labels) {
    if (!intl || !Array.isArray(labels)) {
        return labels;
    }

    const year = new Date().getFullYear();
    return labels.map((label) => {
        const match = typeof label === 'string' ? label.match(/^([A-Z][a-z]{2}) (\d{1,2})$/) : null;
        if (!match || MONTHS[match[1]] === undefined) {
            return label;
        }

        try {
            return intl.formatDate(new Date(year, MONTHS[match[1]], parseInt(match[2], 10)), { month: 'short', day: 'numeric' });
        } catch {
            return label;
        }
    });
}

/** Known English dataset labels of the analytics endpoints, mapped to translation keys. */
const DATASET_LABEL_KEYS = {
    Completed: 'completed',
    'In Progress': 'in-progress',
    Dispatched: 'dispatched',
    Canceled: 'canceled',
    Failed: 'failed',
    'Fuel Cost': 'fuel-cost',
    'Cost per km': 'cost-per-km',
};

/** Chart.js datasets from `fleet-ops/analytics/*`: translate the legend label only. */
export function localizeDatasets(intl, datasets) {
    if (!intl || !Array.isArray(datasets)) {
        return datasets;
    }

    return datasets.map((dataset) => {
        const id = DATASET_LABEL_KEYS[dataset?.label];
        if (!id) {
            return dataset;
        }

        return { ...dataset, label: tr(intl, `fleet-ops.ui.analytics-api.dataset.${id}`, dataset.label) };
    });
}

/** Issue category labels from `fleet-ops/analytics/issues-insights` (raw category values). */
export function localizeIssueCategoryLabels(intl, labels) {
    if (!intl || !Array.isArray(labels)) {
        return labels;
    }

    return labels.map((label) => {
        if (label === 'Uncategorized') {
            return tr(intl, 'fleet-ops.ui.analytics-api.uncategorized', label);
        }

        return optionLabel(intl, 'issueCategories', label) ?? label;
    });
}

/** Zone labels from `fleet-ops/analytics/geofence-violations` ("Unnamed" placeholder only). */
export function localizeZoneLabels(intl, labels) {
    if (!intl || !Array.isArray(labels)) {
        return labels;
    }

    return labels.map((label) => (label === 'Unnamed' ? tr(intl, 'fleet-ops.ui.analytics-api.unnamed', label) : label));
}

/* ---------------------------------------------------------------- issue timeline */

const FIELD_KEYS = {
    status: 'status',
    priority: 'priority',
    assigned_to_uuid: 'assignee',
    vehicle_uuid: 'vehicle',
    driver_uuid: 'driver',
    order_uuid: 'order',
    type: 'type',
    category: 'category',
    tags: 'tags',
    resolved_at: 'resolved-at',
};

const FIELD_OPTION_LISTS = {
    status: 'issueStatuses',
    priority: 'issuePriorities',
    type: 'issueTypes',
    category: 'issueCategories',
};

/** Mirrors Laravel's Str::headline closely enough for display ("in_progress" -> "In Progress"). */
const headline = (value) =>
    String(value)
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[-_]+/g, ' ')
        .trim()
        .replace(/\b\w/g, (c) => c.toUpperCase());

const PLACEHOLDER_NAMES = {
    Someone: 'fleet-ops.ui.issue.timeline.someone',
    'Unknown reporter': 'fleet-ops.ui.issue-timeline.unknown-reporter',
};

function localizeActor(intl, name) {
    const key = PLACEHOLDER_NAMES[name];
    return key ? tr(intl, key, name) : name;
}

function changeValue(intl, field, value) {
    if (value === null || value === undefined || value === '') {
        return tr(intl, 'fleet-ops.ui.issue-timeline.none', 'none');
    }

    if (field === 'resolved_at') {
        return String(value);
    }

    const list = FIELD_OPTION_LISTS[field];
    return (list && optionLabel(intl, list, value)) || headline(value);
}

/** One event from `issues/<id>/timeline`; label/description/actor are display-only. */
export function localizeIssueTimelineEvent(intl, event) {
    if (!event || typeof event !== 'object' || !intl) {
        return event;
    }

    const base = 'fleet-ops.ui.issue-timeline';
    const field = event.meta?.field;
    const fieldKey = field ? FIELD_KEYS[field] : null;
    const fieldLabel = fieldKey ? tr(intl, `${base}.field.${fieldKey}`, null) : null;
    const localized = { ...event, actor_name: localizeActor(intl, event.actor_name) };

    // Label: by event type; generic "X changed" events use the field name.
    if (event.type === 'issue_updated' && field) {
        localized.label = fieldLabel ? tr(intl, `${base}.label.field-changed`, event.label, { field: fieldLabel }) : event.label;
    } else {
        localized.label = tr(intl, `${base}.label.${slugify(event.type)}`, event.label);
    }

    // Description
    if (event.type === 'issue_opened' && typeof event.description === 'string') {
        const reporter = event.description.replace(/^Reported by /, '');
        if (reporter !== event.description) {
            localized.description = tr(intl, `${base}.reported-by`, event.description, { name: localizeActor(intl, reporter) });
        }
    } else if (field && fieldLabel && event.meta && 'to' in event.meta) {
        const { from, to } = event.meta;
        const scalar = (v) => v === null || v === undefined || ['string', 'number', 'boolean'].includes(typeof v);
        if (scalar(from) && scalar(to)) {
            localized.description = tr(intl, `${base}.change`, event.description, {
                field: fieldLabel,
                from: changeValue(intl, field, from),
                to: changeValue(intl, field, to),
            });
        }
    } else if (event.type === 'document_removed' && event.description === 'Document') {
        localized.description = tr(intl, `${base}.document`, event.description);
    }

    return localized;
}
