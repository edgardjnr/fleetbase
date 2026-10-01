import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const DRIVER_COPY = {
    taler: {
        name: 'GNU Taler',
        icon: 'wallet',
        typeKey: 'ledger.ui.gateway.driver.taler.type',
        descriptionKey: 'ledger.ui.gateway.driver.taler.description',
    },
    stripe: {
        name: 'Stripe',
        icon: 'credit-card',
        typeKey: 'ledger.ui.gateway.driver.stripe.type',
        descriptionKey: 'ledger.ui.gateway.driver.stripe.description',
    },
    cash: {
        name: 'Cash',
        nameKey: 'ledger.ui.gateway.driver.cash.name',
        icon: 'money-bill-wave',
        typeKey: 'ledger.ui.gateway.driver.cash.type',
        descriptionKey: 'ledger.ui.gateway.driver.cash.description',
    },
    qpay: {
        name: 'QPay',
        icon: 'qrcode',
        typeKey: 'ledger.ui.gateway.driver.qpay.type',
        descriptionKey: 'ledger.ui.gateway.driver.qpay.description',
    },
};

export default class GatewayHubComponent extends Component {
    @service gatewayActions;
    @service intl;
    @tracked table;

    get gateways() {
        return Array.from(this.args.gateways ?? []);
    }

    get summary() {
        return this.args.summary?.summary ?? {};
    }

    get drivers() {
        return Array.from(this.args.drivers ?? []);
    }

    get driverCards() {
        const configuredByDriver = new Map();

        this.gateways.forEach((gateway) => {
            const list = configuredByDriver.get(gateway.driver) ?? [];
            list.push(gateway);
            configuredByDriver.set(gateway.driver, list);
        });

        return ['taler', 'stripe', 'cash', 'qpay'].map((code) => {
            const manifest = this.drivers.find((driver) => driver.code === code) ?? {};
            const copy = DRIVER_COPY[code];
            const configured = configuredByDriver.get(code) ?? [];
            const active = configured.filter((gateway) => gateway.status === 'active');

            return {
                code,
                name: copy.nameKey ? this.intl.t(copy.nameKey) : (manifest.name ?? copy.name),
                icon: copy.icon,
                type: this.intl.t(copy.typeKey),
                description: this.intl.t(copy.descriptionKey),
                capabilities: manifest.capabilities ?? [],
                configuredCount: configured.length,
                activeCount: active.length,
                primaryGateway: configured[0],
                connected: configured.length > 0,
            };
        });
    }

    get hasGatewayConnections() {
        return this.gateways.length > 0;
    }

    get warningCount() {
        return Number(this.summary.webhook_warnings ?? 0);
    }

    get statusTiles() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.hub.tile.active'),
                value: this.summary.active_gateways ?? 0,
                caption: this.intl.t('ledger.ui.gateway.hub.tile.active-caption'),
                icon: 'plug',
                accentClass: Number(this.summary.active_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-blue',
            },
            {
                label: this.intl.t('ledger.ui.gateway.hub.tile.live'),
                value: this.summary.live_gateways ?? 0,
                caption: this.intl.t('ledger.ui.gateway.hub.tile.live-caption'),
                icon: 'bolt',
                accentClass: Number(this.summary.live_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-blue' : 'ledger-gateway-kpi-accent-slate',
            },
            {
                label: this.intl.t('ledger.ui.gateway.hub.tile.webhook-issues'),
                value: this.warningCount,
                caption: this.warningCount > 0 ? this.intl.t('ledger.ui.gateway.hub.tile.webhook-issues-caption') : this.intl.t('ledger.ui.gateway.hub.tile.no-warnings'),
                icon: 'link',
                accentClass: this.warningCount > 0 ? 'ledger-gateway-kpi-accent-amber' : 'ledger-gateway-kpi-accent-green',
            },
            {
                label: this.intl.t('ledger.ui.gateway.hub.tile.sandbox'),
                value: this.summary.sandbox_gateways ?? 0,
                caption: this.intl.t('ledger.ui.gateway.hub.tile.sandbox-caption'),
                icon: 'flask',
                accentClass: Number(this.summary.sandbox_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-amber' : 'ledger-gateway-kpi-accent-slate',
            },
        ];
    }

    @action setupTable(table) {
        this.table = table;
    }

    @action refresh() {
        if (typeof this.args.refresh === 'function') {
            this.args.refresh();
        }

        return this.gatewayActions.refresh();
    }

    @action openDriver(driver) {
        if (driver.primaryGateway) {
            return this.gatewayActions.transition.view(driver.primaryGateway);
        }

        return this.gatewayActions.transition.create(driver.code);
    }
}
