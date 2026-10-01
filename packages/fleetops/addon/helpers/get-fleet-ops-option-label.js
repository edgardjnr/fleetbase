import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import fleetOpsOptions from '../utils/fleet-ops-options';

export function getFleetOpsOptionLabel(optionsKey, value, intl = null) {
    const allOptions = fleetOpsOptions(optionsKey, intl);
    return allOptions.find((opt) => opt.value === value)?.label ?? null;
}

export default class GetFleetOpsOptionLabelHelper extends Helper {
    @service intl;

    compute([optionsKey, value]) {
        return getFleetOpsOptionLabel(optionsKey, value, this.intl);
    }
}
