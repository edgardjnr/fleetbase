import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import fleetOpsOptions from '../utils/fleet-ops-options';

export default class GetFleetOpsOptionsHelper extends Helper {
    @service intl;

    compute([key]) {
        return fleetOpsOptions(key, this.intl);
    }
}
