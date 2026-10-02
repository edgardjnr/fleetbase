import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import smartHumanizeUtil from '../utils/smart-humanize';

/**
 * Humanizes a value for display ("in_progress" -> "In Progress").
 *
 * Known enum values (statuses, priorities, severities...) are shown translated when a key exists under
 * `ember-ui.status.<value>` (the same dictionary used by <Badge>); everything else is humanized as before.
 * Only the displayed text changes — the value itself is never modified.
 */
export default class SmartHumanizeHelper extends Helper {
    @service intl;

    compute([string]) {
        if (typeof string === 'string' && this.intl) {
            // read the locale so the helper recomputes when the language changes
            // eslint-disable-next-line no-unused-vars
            const locale = this.intl.locale;
            const normalized = string
                .trim()
                .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
                .toLowerCase()
                .replace(/[\s_]+/g, '-');

            if (normalized && /^[a-z0-9-]+$/.test(normalized)) {
                const key = `ember-ui.status.${normalized}`;
                try {
                    if (this.intl.exists(key)) {
                        return this.intl.t(key);
                    }
                } catch (error) {
                    // fall back to the humanized value
                }
            }
        }

        return smartHumanizeUtil(string);
    }
}
