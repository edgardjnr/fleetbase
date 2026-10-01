import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import menuText from '../utils/menu-text';

/**
 * Display text of a registered menu item, panel or shortcut, translated by its id
 * (see utils/menu-text).
 *
 * Usage:
 *   {{menu-text @item "title"}}
 *   {{menu-text item "description"}}
 */
export default class MenuTextHelper extends Helper {
    @service intl;

    compute([item, field = 'title'], { id = null } = {}) {
        // read the locale so the helper recomputes when the language changes
        // eslint-disable-next-line no-unused-vars
        const locale = this.intl.locale;

        return menuText(this.intl, item, field, id);
    }
}
