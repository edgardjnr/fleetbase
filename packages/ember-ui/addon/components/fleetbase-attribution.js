import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import config from 'ember-get-config';

export default class FleetbaseAttributionComponent extends Component {
    @service modalsManager;
    @service intl;

    licensingUrl = 'https://www.fleetbase.io';

    get disabled() {
        return config.APP?.disableFleetbaseAttribution === true;
    }

    get appVersion() {
        return config.version ? `v${config.version}` : null;
    }

    @action openLegalNotice() {
        this.modalsManager.show('modals/fleetbase-legal-notice', {
            title: this.intl.t('ember-ui.fleetbase-attribution.legal-notices'),
            acceptButtonText: this.intl.t('ember-ui.common.done'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            modalClass: 'modal-md fleetbase-legal-notice-modal',
        });
    }
}
