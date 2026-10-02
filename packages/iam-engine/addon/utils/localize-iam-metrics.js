/**
 * Translate the English text built by the API for the IAM dashboard widgets (`metrics/iam/*`).
 *
 * The API is not rebuilt with the console, so the fixed server strings are looked up under
 * `iam.ui.api.*` by exact server text / stable value and always fall back to the API text.
 * Names (users, groups, services, organization roles) are data and are never changed; system role and
 * policy names (FLB managed) are shown translated, by name. Activity timestamps get a client-side relative time.
 */
import { localizeIamName } from '@fleetbase/ember-ui/utils/localize-iam-name';
import { formatRelativeTime } from '@fleetbase/ember-ui/utils/relative-time';

const slugify = (value) =>
    String(value ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

const BASE = 'iam.ui.api';
const MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };

const MANAGED_TYPES = {
    'Fleetbase Managed': 'iam.ui.scheme-types.flb-managed',
    'FLB Managed': 'iam.ui.scheme-types.flb-managed',
    'Organization Managed': 'iam.ui.scheme-types.org-managed',
};

const BUCKETS = { Empty: 'empty', '1-5 members': '1-5', '6-20 members': '6-20', '20+ members': '20-plus' };
const LIFECYCLE = { Created: 'created', Pending: 'pending', Inactive: 'inactive' };
const ACTIVITY_EVENTS = ['created', 'updated', 'deleted', 'restored'];

const lookup = (intl, key, fallback, params) => (intl.exists(key) ? intl.t(key, params) : fallback);

function dayLabel(intl, label) {
    const match = typeof label === 'string' ? label.match(/^([A-Z][a-z]{2}) (\d{1,2})$/) : null;
    if (!match || MONTHS[match[1]] === undefined) {
        return label;
    }

    try {
        return intl.formatDate(new Date(new Date().getFullYear(), MONTHS[match[1]], parseInt(match[2], 10)), { month: 'short', day: 'numeric' });
    } catch {
        return label;
    }
}

function datasetLabel(intl, endpoint, label) {
    if (typeof label !== 'string') {
        return label;
    }

    if (endpoint === 'user-lifecycle' && LIFECYCLE[label]) {
        return lookup(intl, `${BASE}.lifecycle.${LIFECYCLE[label]}`, label);
    }

    if (endpoint === 'users-by-type-created') {
        return lookup(intl, `${BASE}.user-type.${slugify(label)}`, label);
    }

    return label;
}

const managedType = (intl, type) => (MANAGED_TYPES[type] ? lookup(intl, MANAGED_TYPES[type], type) : type);

export default function localizeIamMetrics(intl, endpoint, data) {
    if (!intl || !data || typeof data !== 'object') {
        return data;
    }

    const out = { ...data };

    if (Array.isArray(data.labels)) {
        out.labels = data.labels.map((label) => dayLabel(intl, label));
    }

    if (Array.isArray(data.datasets)) {
        out.datasets = data.datasets.map((dataset) => ({ ...dataset, label: datasetLabel(intl, endpoint, dataset?.label) }));
    }

    if (Array.isArray(data.roles)) {
        out.roles = data.roles.map((role) => ({ ...role, name: localizeIamName(intl, role?.name, 'role', role), type: managedType(intl, role?.type) }));
    }

    if (Array.isArray(data.policies)) {
        out.policies = data.policies.map((policy) => ({ ...policy, name: localizeIamName(intl, policy?.name, 'policy', policy), type: managedType(intl, policy?.type) }));
    }

    if (Array.isArray(data.buckets)) {
        out.buckets = data.buckets.map((bucket) => (BUCKETS[bucket?.label] ? { ...bucket, label: lookup(intl, `${BASE}.group-bucket.${BUCKETS[bucket.label]}`, bucket.label) } : bucket));
    }

    if (endpoint === 'activity' && Array.isArray(data.items)) {
        out.items = data.items.map((item) => ({
            ...item,
            description: ACTIVITY_EVENTS.includes(item?.description) ? lookup(intl, `${BASE}.activity-event.${item.description}`, item.description) : item?.description,
            subject_type: typeof item?.subject_type === 'string' ? lookup(intl, `${BASE}.subject-type.${slugify(item.subject_type)}`, item.subject_type) : item?.subject_type,
            created_ago: item?.created_at ? formatRelativeTime(intl, item.created_at) : null,
        }));
    }

    return out;
}
