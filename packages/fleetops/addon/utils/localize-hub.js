/**
 * Translate a hub payload from `fleet-ops/hubs/<name>` (resources, maintenance).
 *
 * The API builds these hubs with English text, but every entry carries a stable identifier
 * (kpi/action/section `key`, link `route`, doc `slug`), so the text is looked up under
 * `fleet-ops.ui.hub.<name>.*` and falls back to the server text when no key exists.
 * Counts embedded in action descriptions ("3 drivers do not ...") are passed as {count}.
 */
import isEntregasHiddenRoute from './entregas-hidden-routes';

const slugify = (value) => String(value).replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '');

export default function localizeHub(intl, hub, name) {
    if (!hub || typeof hub !== 'object' || !intl) {
        return hub;
    }

    const base = `fleet-ops.ui.hub.${name}`;
    const tr = (key, fallback, params = {}) => (intl.exists(key) ? intl.t(key, params) : fallback);
    const leadingCount = (text) => {
        const match = typeof text === 'string' ? text.match(/^\s*(\d+)/) : null;
        return match ? parseInt(match[1], 10) : 0;
    };

    // Entregas RestaurantePro: tira cartões, ações e links que levam a telas ocultas
    const visible = (item) => !isEntregasHiddenRoute(item?.route);

    const kpis = (hub.kpis ?? []).filter(visible).map((kpi) => {
        const k = `${base}.kpi.${slugify(kpi.key)}`;
        const value = Number(kpi.value) || 0;
        const captionKey = intl.exists(`${k}.caption-some`) ? `${k}.${value > 0 ? 'caption-some' : 'caption-none'}` : `${k}.caption`;
        return {
            ...kpi,
            label: tr(`${k}.label`, kpi.label),
            caption: tr(captionKey, kpi.caption, { count: value }),
            actionLabel: kpi.actionLabel ?? (intl.exists(`${k}.action`) ? intl.t(`${k}.action`) : undefined),
        };
    });

    const actions = (hub.actions ?? []).filter(visible).map((action) => {
        const k = `${base}.action.${slugify(action.key)}`;
        return {
            ...action,
            label: tr(`${k}.label`, action.label),
            description: tr(`${k}.description`, action.description, { count: leadingCount(action.description) }),
        };
    });

    const sections = (hub.sections ?? []).map((section) => {
        const k = `${base}.section.${slugify(section.key)}`;
        return {
            ...section,
            title: tr(`${k}.title`, section.title),
            description: tr(`${k}.description`, section.description),
            links: (section.links ?? []).filter(visible).map((link) => {
                const lk = `${base}.link.${slugify(link.route)}`;
                return { ...link, label: tr(`${lk}.label`, link.label), description: tr(`${lk}.description`, link.description) };
            }),
        };
    });

    const docs = (hub.docs ?? []).filter((doc) => !/\/(vehicles|trailers|fleets|vendors|issues|fuel[^/]*)\//.test(doc.slug ?? '')).map((doc) => {
        const dk = `${base}.doc.${slugify(doc.slug)}`;
        return {
            ...doc,
            label: tr(`${dk}.label`, doc.label),
            title: tr(`${dk}.title`, doc.title),
            description: tr(`${dk}.description`, doc.description),
        };
    });

    return { ...hub, kpis, actions, sections: sections.filter((section) => section.links.length > 0), docs };
}
