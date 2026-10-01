import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';

export default class PointCoordinatesHelper extends Helper {
    @service intl;

    compute([point]) {
        if (point instanceof Point) {
            return `${point.coordinates[1]} ${point.coordinates[0]}`;
        }

        return this.intl.t('ember-ui.point-coordinates.invalid');
    }
}
