/**
 * Resolve the display text of a registered menu item / panel / shortcut.
 *
 * Extensions register menus with plain English titles, and the title also drives the item's
 * id/slug/view (URLs, saved nav customizations). So instead of registering translation keys,
 * the text is looked up by the item's id:
 *
 *   menu-text.<id>.title         (also used for "label" / "text")
 *   menu-text.<panel-slug>-<item-view>.title   for admin panel items
 *   menu-text.<id>.description
 *
 * Fallbacks: the value itself when it is a translation key, then the value unchanged.
 *
 * @param {Object} intl  the intl service
 * @param {Object} item  menu item, panel or shortcut
 * @param {String} field "title" | "label" | "text" | "description"
 * @param {String} [idOverride] id to use instead of item.id / item.slug
 * @return {String|undefined}
 */
export default function menuText(intl, item, field = 'title', idOverride = null) {
    if (!item) return undefined;

    const isTitleLike = field === 'title' || field === 'label' || field === 'text';
    const value = isTitleLike ? (item[field] ?? item.label ?? item.title ?? item.text) : item[field];
    // admin panel items: slug = panel slug, view = item slug -> "<panel>-<item>"
    const panelSlug = item._panelSlug ?? (item._isPanelItem ? item.slug : null);
    const ids = [idOverride, panelSlug && item.view ? `${panelSlug}-${item.view}` : null, item.id, item.slug];

    if (intl) {
        for (const id of ids) {
            if (typeof id !== 'string' || !id) continue;
            const key = `menu-text.${id.replace(/[.\s]+/g, '-')}.${isTitleLike ? 'title' : field}`;
            if (intl.exists(key)) return intl.t(key);
        }
    }

    if (intl && typeof value === 'string' && value && intl.exists(value)) {
        return intl.t(value);
    }

    return value;
}
