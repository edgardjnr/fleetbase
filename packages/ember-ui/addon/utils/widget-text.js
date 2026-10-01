/**
 * Display text of a registered dashboard widget ("name" | "description"), translated by the
 * widget id so the registered (and saved) English name does not have to change:
 *
 *   widget-text.<widget-id>.name
 *   widget-text.<widget-id>.description
 *
 * Fallbacks: the value itself when it is a translation key, then the value unchanged.
 */
export default function widgetText(intl, widget, field = 'name') {
    if (!widget) return undefined;

    const value = widget[field];
    const id = widget.id ?? widget.widget_id;

    if (intl && typeof id === 'string' && id) {
        const key = `widget-text.${id.replace(/[.\s]+/g, '-')}.${field}`;
        if (intl.exists(key)) return intl.t(key);
    }

    if (intl && typeof value === 'string' && value && intl.exists(value)) {
        return intl.t(value);
    }

    return value;
}
