import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import isEmptyObject from '@fleetbase/ember-core/utils/is-empty-object';

export default class OrderDetailsMetadataComponent extends Component {
    @service intl;
    @service orderActions;

    get emptyMetadata() {
        return isEmptyObject(this.args.resource.meta);
    }

    get actionButtons() {
        return [
            {
                type: 'default',
                text: this.intl.t('fleet-ops.ui.order.details-metadata.edit'),
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update order',
                onClick: () => {
                    this.orderActions.editMetadata(this.args.resource);
                },
            },
        ];
    }
}
