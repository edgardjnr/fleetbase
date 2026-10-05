/**
 * Translate the English text built by the API for the admin overview widgets
 * (`metrics/admin/widgets/<slug>` and `metrics/admin/growth`).
 *
 * The API is not rebuilt with the console, so the fixed server strings are looked up under
 * `console.ui.admin-metrics.*` by the widget slug and the exact server text; anything unknown
 * (company names, activity descriptions, driver names) is kept as returned by the API.
 * Default activitylog events ("created", "updated"...) and Carbon's "2 hours ago" are translated too.
 */
import { localizeTimeAgoText } from '@fleetbase/ember-ui/utils/relative-time';

const slugify = (value) =>
    String(value ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

const BASE = 'console.ui.admin-metrics';

/** Fixed item titles (only for widgets whose titles are not user data). */
const TITLE_WIDGETS = ['system-diagnostics', 'configuration-gaps'];

/** Fixed item descriptions/values, by exact server text. */
const TEXTS = {
    'Not configured': 'not-configured',
    'Required platform configuration is missing.': 'configuration-missing',
    Missing: 'missing',
    'Missing owner': 'missing-owner',
    'Incomplete onboarding': 'incomplete-onboarding',
    'Status review': 'status-review',
    'Admin activity': 'admin-activity',
};

/** Empty-state messages, by exact server text. */
const EMPTY_TEXTS = {
    'No growth data available.': 'no-growth-data',
    'No diagnostics available.': 'no-diagnostics',
    'Activity logging is unavailable.': 'activity-logging-unavailable',
    'No recent sensitive admin activity.': 'no-admin-activity',
    'No organizations currently need review.': 'no-organizations-review',
    'No configuration gaps detected.': 'no-configuration-gaps',
};

/** Chart labels / dataset labels of the growth chart, by exact server text. */
const CHART_TEXTS = {
    'Previous 30d': 'previous-30d',
    'Current 30d': 'current-30d',
    Users: 'users',
    Organizations: 'organizations',
};

const lookup = (intl, key, fallback) => (intl && intl.exists(key) ? intl.t(key) : fallback);

/** Default activitylog descriptions/events (the event name), shown with the activity log badge texts. */
const ACTIVITY_EVENTS = ['created', 'updated', 'deleted', 'restored'];
const byEvent = (intl, text) =>
    typeof text === 'string' && ACTIVITY_EVENTS.includes(text.trim().toLowerCase()) ? lookup(intl, `ember-ui.activity-log.badge.${text.trim().toLowerCase()}`, text) : text;
const byText = (intl, table, group, text) => (typeof text === 'string' && table[text] ? lookup(intl, `${BASE}.${group}.${table[text]}`, text) : text);

export function localizeAdminSubtitle(intl, slug, subtitle) {
    return slug && subtitle ? lookup(intl, `${BASE}.${slugify(slug)}.subtitle`, subtitle) : subtitle;
}

export function localizeAdminEmpty(intl, text) {
    return byText(intl, EMPTY_TEXTS, 'empty', text);
}

export function localizeAdminItems(intl, slug, items) {
    if (!intl || !Array.isArray(items)) {
        return items;
    }

    const translateTitle = TITLE_WIDGETS.includes(slug);
    const isActivity = slug === 'admin-activity';
    return items.map((item) => {
        if (!item || typeof item !== 'object') {
            return item;
        }

        // only the fields the item has: an item without title/description/value keeps its shape
        const localized = { ...item };
        if ('title' in item) {
            localized.title = translateTitle
                ? lookup(intl, `${BASE}.item.${slugify(item.title)}`, item.title)
                : byText(intl, TEXTS, 'text', isActivity ? byEvent(intl, item.title) : item.title);
        }
        if ('description' in item) {
            // "<causer> / 2 hours ago" (Carbon diffForHumans): the relative part is rebuilt in the active language
            localized.description = isActivity ? localizeTimeAgoText(intl, item.description) : byText(intl, TEXTS, 'text', item.description);
        }
        if ('value' in item) {
            localized.value = byText(intl, TEXTS, 'text', isActivity ? byEvent(intl, item.value) : item.value);
        }

        return localized;
    });
}

export function localizeAdminChartLabels(intl, labels) {
    return Array.isArray(labels) ? labels.map((label) => byText(intl, CHART_TEXTS, 'chart', label)) : labels;
}

export function localizeAdminDatasets(intl, datasets) {
    return Array.isArray(datasets) ? datasets.map((dataset) => ({ ...dataset, label: byText(intl, CHART_TEXTS, 'chart', dataset?.label) })) : datasets;
}
