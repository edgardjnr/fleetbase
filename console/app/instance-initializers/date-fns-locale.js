import { setDateFnsLocale } from '@fleetbase/ember-core/utils/date-fns-locale';

/**
 * Keep the shared date-fns locale (relative times, month/day names) in sync with ember-intl.
 */
export function initialize(appInstance) {
    const intl = appInstance.lookup('service:intl');
    if (!intl) return;

    setDateFnsLocale(intl.primaryLocale ?? intl.locale);

    if (typeof intl.onLocaleChanged === 'function') {
        intl.onLocaleChanged(() => setDateFnsLocale(intl.primaryLocale ?? intl.locale));
    }
}

export default {
    name: 'date-fns-locale',
    initialize,
};
