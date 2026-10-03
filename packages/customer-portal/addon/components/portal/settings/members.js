import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class PortalSettingsMembersComponent extends Component {
    @service customerSession;
    @service fetch;
    @service notifications;
    @service intl;

    @tracked personnels = [];

    get roleOptions() {
        const locale = this.intl.primaryLocale;

        if (!this._roleOptions || this._roleOptionsLocale !== locale) {
            this._roleOptionsLocale = locale;
            this._roleOptions = ['admin', 'member'].map((value) => ({
                value,
                label: this.intl.t(`customer-portal.ui.roles.${value}.label`),
                description: this.intl.t(`customer-portal.ui.roles.${value}.description`),
            }));
        }

        return this._roleOptions;
    }

    constructor() {
        super(...arguments);

        if (this.isCompanyAccount) {
            this.loadPersonnel.perform();
        }
    }

    /** Label shown for a personnel role value ('admin' / 'member'); unknown values are shown as sent. */
    roleLabel = (role) => this.roleOptions.find((option) => option.value === role)?.label ?? role;

    get isCompanyAccount() {
        return this.customerSession.accountType === 'vendor';
    }

    get hasPersonnel() {
        return this.personnels.length > 0;
    }

    /**
     * Entregas: o usuário da loja só vê a lista. Adicionar e remover usuários é da central (tela Lojas do
     * Fleet-Ops) e a API nega `account/personnel-candidates` e `POST/DELETE account/personnels` ao usuário
     * de loja, por isso só `account/personnels` é carregado (um 403 nos candidatos derrubaria a lista toda).
     */
    @task *loadPersonnel() {
        try {
            const response = yield this.fetch.get('account/personnels', {}, { namespace: 'customer-portal/int/v1' });

            this.personnels = response.personnels ?? [];
        } catch (error) {
            this.personnels = [];
            this.notifications.serverError(error);
        }
    }
}
