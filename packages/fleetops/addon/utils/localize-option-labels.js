const optionSlug = (value) =>
    String(value ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '') || 'x';

/**
 * Returns a translated copy of a static option list.
 *
 * Each option is looked up at `<prefix>.<slug of value|key|id>.<field>`; when the
 * translation key does not exist the original (English) text is kept. Nested
 * `fields` arrays are localized under `<prefix>.<option-slug>`.
 *
 * @param {Object} intl ember-intl service
 * @param {String} prefix translation key prefix
 * @param {Array<Object>} options static option list
 * @param {Array<String>} fields option properties to translate
 * @return {Array<Object>}
 */
export default function localizeOptionLabels(intl, prefix, options, fields = ['label', 'description', 'hint', 'source', 'target']) {
    if (!intl || typeof intl.t !== 'function' || !Array.isArray(options)) {
        return options;
    }

    return options.map((option) => {
        if (!option || typeof option !== 'object') {
            return option;
        }

        const id = optionSlug(option.value ?? option.key ?? option.id);
        const localized = { ...option };
        for (const field of fields) {
            const key = `${prefix}.${id}.${field}`;
            if (typeof option[field] === 'string' && intl.exists(key)) {
                localized[field] = intl.t(key);
            }
        }

        if (Array.isArray(option.fields)) {
            localized.fields = localizeOptionLabels(intl, `${prefix}.${id}`, option.fields, fields);
        }

        return localized;
    });
}
