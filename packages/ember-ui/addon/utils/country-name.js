/**
 * Country name in the active language, from its ISO 3166-1 alpha-2 code, using the browser's
 * Intl.DisplayNames (e.g. "BR" -> "Brasil" in pt-BR). Falls back to the given name.
 *
 * @param {String} locale  active locale (e.g. intl.primaryLocale)
 * @param {String} iso2    country code
 * @param {String} fallback name to use when the code/locale cannot be resolved
 */
const cache = new Map();

export default function countryName(locale, iso2, fallback = '') {
    if (!iso2 || typeof iso2 !== 'string' || typeof Intl === 'undefined' || typeof Intl.DisplayNames !== 'function') {
        return fallback;
    }

    const lang = Array.isArray(locale) ? locale[0] : locale;
    if (!lang || /^en/i.test(lang)) {
        return fallback || iso2;
    }

    try {
        let names = cache.get(lang);
        if (!names) {
            names = new Intl.DisplayNames([lang], { type: 'region' });
            cache.set(lang, names);
        }
        const name = names.of(iso2.toUpperCase());
        return name && name !== iso2.toUpperCase() ? name : fallback;
    } catch (e) {
        return fallback;
    }
}
