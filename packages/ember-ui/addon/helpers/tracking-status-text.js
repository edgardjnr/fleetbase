import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import trackingStatusText from '../utils/tracking-status-text';

/**
 * Texto de uma atividade do pedido no idioma ativo (ver utils/tracking-status-text).
 *
 * Uso:
 *   {{tracking-status-text trackingStatus.status}}
 *   {{tracking-status-text trackingStatus.details "details"}}
 */
export default class TrackingStatusTextHelper extends Helper {
    @service intl;

    compute([texto, campo = 'status']) {
        // lê o locale para recalcular quando o idioma muda
        // eslint-disable-next-line no-unused-vars
        const locale = this.intl.locale;

        return trackingStatusText(this.intl, texto, campo);
    }
}
