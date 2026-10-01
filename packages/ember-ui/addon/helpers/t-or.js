import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

/**
 * Translates the value when it is an existing translation key, otherwise returns it unchanged.
 * Lets extensions register menu items, panels and widgets with translation keys while plain
 * strings (and data values) keep rendering as before.
 *
 * Usage:
 *   {{t-or @item.title}}
 *   {{t-or this.panel.title "fallback text"}}
 */
export default class TOrHelper extends Helper {
    @service intl;

    compute([value, fallback]) {
        // read the locale so the helper recomputes when the language changes
        // eslint-disable-next-line no-unused-vars
        const locale = this.intl.locale;

        if (typeof value === 'string' && value && this.intl.exists(value)) {
            return this.intl.t(value);
        }

        return value ?? fallback;
    }
}
