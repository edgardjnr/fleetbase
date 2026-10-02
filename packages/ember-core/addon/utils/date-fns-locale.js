import { ptBR, es, fr, de, it, ru, zhCN, vi, ar, bg, mn, uz } from 'date-fns/locale';

/**
 * date-fns locale shared by the console and every extension.
 *
 * date-fns formats in English unless a `locale` option is passed, and each engine bundles its
 * own copy of date-fns (so `setDefaultOptions` would not reach them). The active locale is kept on
 * `globalThis` instead, set by the console whenever ember-intl changes language, and every
 * display call passes `dateFnsLocaleOptions()`:
 *
 *   formatDistanceToNow(date, dateFnsLocaleOptions({ addSuffix: true }))
 *   format(date, 'MMM d, yyyy', dateFnsLocaleOptions())
 */
const LOCALES = {
    'pt-br': ptBR,
    'es-es': es,
    'es-mx': es,
    'fr-fr': fr,
    'de-de': de,
    'it-it': it,
    'ru-ru': ru,
    'zh-cn': zhCN,
    'vi-vn': vi,
    'ar-ae': ar,
    'bg-bg': bg,
    'mn-mn': mn,
    'uz-uz': uz,
};

const KEY = '__fleetbaseDateFnsLocale';

export function setDateFnsLocale(code) {
    const normalized = String(Array.isArray(code) ? code[0] : code ?? '').toLowerCase();
    globalThis[KEY] = LOCALES[normalized] ?? LOCALES[normalized.split('-')[0]] ?? undefined;
}

export function getDateFnsLocale() {
    return globalThis[KEY];
}

export default function dateFnsLocaleOptions(options = {}) {
    const locale = globalThis[KEY];
    return locale ? { locale, ...options } : options;
}
