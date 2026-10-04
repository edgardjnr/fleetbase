import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { traducaoDaAtividade } from '@fleetbase/ember-ui/utils/tracking-status-text';
import { arrayFor, valueFor } from '../../../../utils/model-access';

export default class PortalOrderDetailsActivityComponent extends Component {
    @service intl;

    get activities() {
        const activities = arrayFor(valueFor(this.args.resource, 'tracking_statuses')).sort(
            (a, b) => new Date(valueFor(b, 'created_at') ?? 0).getTime() - new Date(valueFor(a, 'created_at') ?? 0).getTime()
        );

        // Entregas: o status e a descrição vêm gravados em inglês ("Order Created"); a tela mostra a tradução dos textos
        // padrão (ember-ui/utils/tracking-status-text). Sem tradução, o status segue pelo smart-humanize do template
        return activities.map((activity, index) => ({
            status: valueFor(activity, 'status'),
            statusTraduzido: traducaoDaAtividade(this.intl, valueFor(activity, 'status'), 'status'),
            details: traducaoDaAtividade(this.intl, valueFor(activity, 'details'), 'details') ?? valueFor(activity, 'details'),
            created_at: valueFor(activity, 'created_at'),
            isCurrent: index === 0,
        }));
    }

    get currentActivity() {
        return this.activities[0] ?? null;
    }
}
