/**
 * Relative time ("2 hours ago") in the active language, built on the client with Intl.RelativeTimeFormat.
 *
 * - `formatRelativeTime(intl, date)` — from a timestamp (Date, ISO string or epoch ms).
 * - `localizeTimeAgoText(intl, text)` — translates the English phrase built by the API with Carbon's
 *   `diffForHumans()` ("2 hours ago", "1 day from now", optionally after a "Name / " prefix). Text that
 *   does not match is returned unchanged.
 *
 * Falls back to the original/English value when Intl.RelativeTimeFormat is unavailable.
 */
const UNITS = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['week', 7 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
    ['second', 1],
];

const localeOf = (intl) => {
    const locale = intl?.primaryLocale ?? (Array.isArray(intl?.locale) ? intl.locale[0] : intl?.locale);
    return typeof locale === 'string' && locale ? locale : 'en-us';
};

const formatter = (intl) => {
    try {
        return new Intl.RelativeTimeFormat(localeOf(intl), { numeric: 'auto' });
    } catch (error) {
        return null;
    }
};

const toDate = (value) => {
    if (value instanceof Date) {
        return isNaN(value.getTime()) ? null : value;
    }
    if (typeof value === 'number' || (typeof value === 'string' && value)) {
        const date = new Date(value);
        return isNaN(date.getTime()) ? null : date;
    }
    return null;
};

export function formatRelativeTime(intl, value, now = Date.now()) {
    const date = toDate(value);
    const rtf = formatter(intl);
    if (!date || !rtf) {
        return null;
    }

    const diffSeconds = Math.round((date.getTime() - now) / 1000);
    const abs = Math.abs(diffSeconds);
    for (const [unit, seconds] of UNITS) {
        if (abs >= seconds || unit === 'second') {
            return rtf.format(Math.round(diffSeconds / seconds), unit);
        }
    }

    return null;
}

const TIME_AGO_PATTERN = /(\d+|an?|one)\s+(second|minute|hour|day|week|month|year)s?\s+(ago|from now|before|after)$/i;

export function localizeTimeAgoText(intl, text) {
    if (typeof text !== 'string' || !text) {
        return text;
    }

    const match = text.match(TIME_AGO_PATTERN);
    const rtf = match ? formatter(intl) : null;
    if (!match || !rtf) {
        return text;
    }

    const [phrase, amountText, unit, direction] = match;
    const amount = /^\d+$/.test(amountText) ? parseInt(amountText, 10) : 1;
    const past = /^(ago|before)$/i.test(direction);

    try {
        return text.slice(0, text.length - phrase.length) + rtf.format(past ? -amount : amount, unit.toLowerCase());
    } catch (error) {
        return text;
    }
}

export default formatRelativeTime;
