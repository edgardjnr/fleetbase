import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class SettingsCustomFieldsController extends Controller {
    @service intl;
    get subjects() {
        return [
            {
                model: 'driver',
                type: 'fleet-ops:driver',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.driver'),
                groups: [],
            },
            {
                model: 'vehicle',
                type: 'fleet-ops:vehicle',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.vehicle'),
                groups: [],
            },
            {
                model: 'trailer',
                type: 'fleet-ops:trailer',
                label: this.intl.t('resource.trailer'),
                groups: [],
            },
            {
                model: 'contact',
                type: 'fleet-ops:contact',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.contact'),
                groups: [],
            },
            {
                model: 'vendor',
                type: 'fleet-ops:vendor',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.vendor'),
                groups: [],
            },
            {
                model: 'place',
                type: 'fleet-ops:place',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.place'),
                groups: [],
            },
            {
                model: 'entity',
                type: 'fleet-ops:entity',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.entity'),
                groups: [],
            },
            {
                model: 'fleet',
                type: 'fleet-ops:fleet',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.fleet'),
                groups: [],
            },
            {
                model: 'issue',
                type: 'fleet-ops:issue',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.issue'),
                groups: [],
            },
            {
                model: 'fuel-report',
                type: 'fleet-ops:fuel-report',
                label: this.intl.t('fleet-ops.ui.controller.settings-custom-fields.fuel-report'),
                groups: [],
            },
        ];
    }
}
