import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import { localizeIamName } from '../utils/localize-iam-name';

/**
 * Display name for a system IAM role/policy (only the text shown changes; the stored name is untouched).
 *
 * Usage:
 *   {{localize-iam-name role.name}}                  (role, by name)
 *   {{localize-iam-name policy.name "policy"}}       (policy, by name)
 *   {{localize-iam-name role.name "role" role}}      (skips records created by the organization)
 */
export default class LocalizeIamNameHelper extends Helper {
    @service intl;

    compute([name, kind = 'role', record = null]) {
        // read the locale so the helper recomputes when the language changes
        // eslint-disable-next-line no-unused-vars
        const locale = this.intl.locale;

        return localizeIamName(this.intl, name, kind, record);
    }
}
