import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementVendorsIndexDetailsController extends Controller {
    @service intl;
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.vendors.index.details.index',
            label: this.intl.t('fleet-ops.ui.controller.management-vendors-index-details.overview'),
        },
        {
            route: 'management.vendors.index.details.personnel',
            label: this.intl.t('fleet-ops.ui.controller.management-vendors-index-details.personnel'),
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.vendors.index.edit', this.model),
        },
    ];
}
