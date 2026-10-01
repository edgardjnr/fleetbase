import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

export default class GatewayDetailsComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service hostRouter;
    @service intl;

    @tracked diagnostics = null;
    @tracked lastActionResult = null;

    constructor() {
        super(...arguments);
        this.loadDiagnostics.perform();
    }

    get isTaler() {
        return this.args.resource?.driver === 'taler';
    }

    get gatewayId() {
        return this.args.resource?.id;
    }

    get section() {
        return this.args.section ?? 'overview';
    }

    get showOverview() {
        return this.section === 'overview';
    }

    get showSetup() {
        return this.section === 'setup';
    }

    get showDiagnostics() {
        return this.section === 'diagnostics';
    }

    get driverLabel() {
        const driver = this.args.resource?.driver;

        if (!driver) {
            return this.intl.t('ledger.ui.gateway.details.gateway');
        }

        if (driver === 'taler') {
            return 'GNU Taler';
        }

        if (driver === 'qpay') {
            return 'QPay';
        }

        return driver.charAt(0).toUpperCase() + driver.slice(1);
    }

    get diagnosticSummary() {
        return this.diagnostics?.diagnostics ?? {};
    }

    get gatewaySummary() {
        return this.diagnostics?.gateway ?? {};
    }

    get webhookStatus() {
        return this.diagnosticSummary.webhook_registration ?? (this.args.resource?.webhook_url ? 'configured' : 'not_configured');
    }

    get lastWebhookAt() {
        return this.diagnosticSummary.last_webhook_received_at;
    }

    get lastPaymentAt() {
        return this.diagnosticSummary.last_payment_event_at;
    }

    get lastRefundAt() {
        return this.diagnosticSummary.last_refund_event_at;
    }

    get lastSettlementAt() {
        return this.diagnosticSummary.last_settlement_seen_at;
    }

    get reconciliationStatus() {
        return this.diagnosticSummary.last_reconciliation_status;
    }

    get credentialStatus() {
        return this.diagnosticSummary.credential_status ?? 'not_checked';
    }

    get lastCredentialTestedAt() {
        return this.diagnosticSummary.last_credential_tested_at;
    }

    get lastCredentialTestMessage() {
        return this.diagnosticSummary.last_credential_test_message;
    }

    get lastWebhookRegistrationAt() {
        return this.diagnosticSummary.last_webhook_registration_at;
    }

    get lastTestOrderAt() {
        return this.diagnosticSummary.last_test_order_at;
    }

    get lastTestOrderId() {
        return this.diagnosticSummary.last_test_order_id;
    }

    get credentialTileValue() {
        if (this.credentialStatus === 'success' || this.credentialStatus === 'ok') {
            return this.intl.t('ledger.ui.gateway.details.verified');
        }

        if (this.credentialStatus === 'failed') {
            return this.intl.t('ledger.ui.gateway.details.failed');
        }

        return this.intl.t('ledger.ui.gateway.details.not-checked-title');
    }

    get credentialAccentClass() {
        if (this.credentialStatus === 'success' || this.credentialStatus === 'ok') {
            return 'ledger-gateway-kpi-accent-green';
        }

        if (this.credentialStatus === 'failed') {
            return 'ledger-gateway-kpi-accent-rose';
        }

        return 'ledger-gateway-kpi-accent-slate';
    }

    get webhookRegistrationResultMessage() {
        if (this.lastWebhookRegistrationAt) {
            return this.intl.t('ledger.ui.gateway.details.webhook-registered-msg');
        }

        if (this.webhookStatus === 'configured') {
            return this.intl.t('ledger.ui.gateway.details.webhook-configured-msg');
        }

        return this.intl.t('ledger.ui.gateway.details.webhook-none-msg');
    }

    get readinessItems() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.connection'),
                value: this.args.resource?.status === 'active' ? this.intl.t('ledger.ui.gateway.details.ready') : this.intl.t('ledger.ui.gateway.details.inactive'),
                status: this.args.resource?.status === 'active' ? 'ready' : 'inactive',
                icon: 'plug',
                caption: this.args.resource?.status === 'active' ? this.intl.t('ledger.ui.gateway.details.available-on-invoices') : this.intl.t('ledger.ui.gateway.details.not-used-at-checkout'),
                accentClass: this.args.resource?.status === 'active' ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-slate',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.environment'),
                value: this.args.resource?.environment === 'live' ? this.intl.t('ledger.ui.gateway.details.live') : this.intl.t('ledger.ui.gateway.details.sandbox'),
                status: this.args.resource?.environment ?? 'unknown',
                icon: 'server',
                caption: this.args.resource?.environment === 'live' ? this.intl.t('ledger.ui.gateway.details.production-payments') : this.intl.t('ledger.ui.gateway.details.testing-sandbox-use'),
                accentClass: this.args.resource?.environment === 'live' ? 'ledger-gateway-kpi-accent-blue' : 'ledger-gateway-kpi-accent-amber',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.webhook'),
                value: this.webhookStatus === 'configured' ? this.intl.t('ledger.ui.gateway.details.configured') : this.intl.t('ledger.ui.gateway.details.needs-setup-title'),
                status: this.webhookStatus === 'configured' ? 'configured' : 'needs setup',
                icon: 'link',
                caption: this.webhookStatus === 'configured' ? this.intl.t('ledger.ui.gateway.details.callback-ready') : this.intl.t('ledger.ui.gateway.details.registration-needed'),
                accentClass: this.webhookStatus === 'configured' ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-amber',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.credentials'),
                value: this.credentialTileValue,
                status: this.credentialStatus,
                icon: 'key',
                caption: this.lastCredentialTestedAt ? this.intl.t('ledger.ui.gateway.details.last-auth-test') : this.intl.t('ledger.ui.gateway.details.run-provider-check'),
                accentClass: this.credentialAccentClass,
            },
        ];
    }

    get providerStatusRows() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.driver'),
                value: this.driverLabel,
                icon: this.isTaler ? 'wallet' : 'plug',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.mode'),
                value: this.args.resource?.environment === 'live' ? this.intl.t('ledger.ui.gateway.details.live') : this.intl.t('ledger.ui.gateway.details.sandbox'),
                icon: this.args.resource?.environment === 'live' ? 'bolt' : 'flask',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.credentials'),
                value: this.credentialStatus === 'not_checked' ? this.intl.t('ledger.ui.gateway.details.not-checked') : this.credentialStatus,
                caption: this.lastCredentialTestMessage,
                date: this.lastCredentialTestedAt,
                icon: 'key',
                status: this.credentialStatus === 'success' || this.credentialStatus === 'ok' ? 'success' : this.credentialStatus === 'failed' ? 'danger' : 'warning',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.webhook'),
                value: this.webhookStatus === 'configured' ? this.intl.t('ledger.ui.gateway.details.configured') : this.intl.t('ledger.ui.gateway.details.needs-setup'),
                date: this.lastWebhookRegistrationAt ?? this.lastWebhookAt,
                icon: 'link',
                status: this.webhookStatus === 'configured' ? 'success' : 'warning',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.payment'),
                value: this.lastPaymentAt ? this.intl.t('ledger.ui.gateway.details.seen') : this.intl.t('ledger.ui.gateway.details.no-payment-yet'),
                date: this.lastPaymentAt,
                icon: 'money-bill-transfer',
                status: this.lastPaymentAt ? 'success' : 'default',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.refund'),
                value: this.lastRefundAt ? this.intl.t('ledger.ui.gateway.details.seen') : this.intl.t('ledger.ui.gateway.details.no-refund-yet'),
                date: this.lastRefundAt,
                icon: 'rotate-left',
                status: this.lastRefundAt ? 'success' : 'default',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.settlement'),
                value: this.reconciliationStatus ?? this.intl.t('ledger.ui.gateway.details.not-checked'),
                date: this.lastSettlementAt,
                icon: 'scale-balanced',
                status: this.reconciliationStatus ? 'success' : 'warning',
            },
        ];
    }

    get setupIdentityRows() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.name'),
                value: this.args.resource?.name,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.driver'),
                value: this.driverLabel,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.public-id'),
                value: this.args.resource?.public_id,
                mono: true,
                copyable: true,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.status'),
                value: this.args.resource?.status,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.environment'),
                value: this.args.resource?.environment ?? (this.args.resource?.is_sandbox ? 'sandbox' : 'live'),
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.created'),
                value: this.args.resource?.createdAt ?? this.args.resource?.created_at,
                date: true,
            },
        ];
    }

    get setupRoutingRows() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.return-url'),
                value: this.args.resource?.return_url,
                mono: true,
                copyable: true,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.ledger-webhook-url'),
                value: this.args.resource?.system_webhook_url,
                mono: true,
                copyable: true,
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.provider-callback'),
                value: this.args.resource?.webhook_url,
                displayValue:
                    this.args.resource?.webhook_url && this.args.resource?.webhook_url === this.args.resource?.system_webhook_url
                        ? this.intl.t('ledger.ui.gateway.details.same-as-ledger-url')
                        : this.args.resource?.webhook_url,
                caption:
                    this.args.resource?.webhook_url && this.args.resource?.webhook_url === this.args.resource?.system_webhook_url
                        ? this.intl.t('ledger.ui.gateway.details.provider-posts-events')
                        : null,
                mono: true,
                copyable: true,
            },
        ];
    }

    get setupConfigRows() {
        const configSummary = this.gatewaySummary.config_summary ?? [];

        if (configSummary.length) {
            return configSummary;
        }

        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.provider-credentials'),
                value: this.intl.t('ledger.ui.gateway.details.stored-encrypted'),
            },
        ];
    }

    get recentActivity() {
        return [
            this.activityItem('webhook', this.diagnostics?.last_webhook, 'link'),
            this.activityItem('payment', this.diagnostics?.last_payment, 'money-bill-transfer'),
            this.activityItem('refund', this.diagnostics?.last_refund, 'rotate-left'),
            this.activityItem('settlement', this.diagnostics?.last_settlement, 'scale-balanced', this.lastSettlementAt),
        ].filter(Boolean);
    }

    get diagnosticsResults() {
        return [
            {
                label: this.intl.t('ledger.ui.gateway.details.credential-test'),
                value: this.lastCredentialTestMessage ?? this.intl.t('ledger.ui.gateway.details.no-credential-test'),
                date: this.lastCredentialTestedAt,
                status: this.credentialStatus === 'success' || this.credentialStatus === 'ok' ? 'success' : this.credentialStatus === 'failed' ? 'danger' : 'warning',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.webhook-registration'),
                value: this.webhookRegistrationResultMessage,
                date: this.lastWebhookRegistrationAt,
                status: this.lastWebhookRegistrationAt ? 'success' : 'warning',
            },
            {
                label: this.intl.t('ledger.ui.gateway.details.test-order'),
                value: this.lastTestOrderId ? this.intl.t('ledger.ui.gateway.details.last-order', { id: this.lastTestOrderId }) : this.intl.t('ledger.ui.gateway.details.no-test-order'),
                date: this.lastTestOrderAt,
                status: this.lastTestOrderId ? 'success' : 'warning',
            },
        ];
    }

    get diagnosticActions() {
        if (!this.isTaler) {
            return [];
        }

        return [
            {
                key: 'test-credentials',
                title: this.intl.t('ledger.ui.gateway.details.test-credentials'),
                description: this.intl.t('ledger.ui.gateway.details.test-credentials-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.test-credentials-result'),
                icon: this.testCredentials.isRunning ? 'spinner' : 'check-circle',
                isLoading: this.testCredentials.isRunning,
                task: this.testCredentials,
                primary: true,
            },
            {
                key: 'register-webhook',
                title: this.intl.t('ledger.ui.gateway.details.register-webhook'),
                description: this.intl.t('ledger.ui.gateway.details.register-webhook-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.register-webhook-result'),
                icon: this.registerWebhook.isRunning ? 'spinner' : 'link',
                isLoading: this.registerWebhook.isRunning,
                task: this.registerWebhook,
                confirm: true,
                confirmTitle: this.intl.t('ledger.ui.gateway.details.register-webhook-confirm-title'),
                confirmBody: this.intl.t('ledger.ui.gateway.details.register-webhook-confirm-body'),
            },
            {
                key: 'create-test-order',
                title: this.intl.t('ledger.ui.gateway.details.create-test-order'),
                description: this.intl.t('ledger.ui.gateway.details.create-test-order-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.create-test-order-result'),
                icon: this.createTestOrder.isRunning ? 'spinner' : 'flask',
                isLoading: this.createTestOrder.isRunning,
                task: this.createTestOrder,
                confirm: true,
                confirmTitle: this.intl.t('ledger.ui.gateway.details.create-test-order-confirm-title'),
                confirmBody: this.intl.t('ledger.ui.gateway.details.create-test-order-confirm-body'),
            },
            {
                key: 'refresh',
                title: this.intl.t('ledger.ui.gateway.details.refresh-diagnostics'),
                description: this.intl.t('ledger.ui.gateway.details.refresh-diagnostics-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.refresh-diagnostics-result'),
                icon: this.loadDiagnostics.isRunning ? 'spinner' : 'rotate',
                isLoading: this.loadDiagnostics.isRunning,
                task: this.loadDiagnostics,
                secondary: true,
            },
        ];
    }

    get overviewActions() {
        return [
            ...this.diagnosticActions.filter((diagnosticAction) => diagnosticAction.key !== 'refresh'),
            {
                key: 'copy-webhook-url',
                title: this.intl.t('ledger.ui.gateway.details.copy-webhook-url'),
                description: this.intl.t('ledger.ui.gateway.details.copy-webhook-url-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.copy-webhook-url-result'),
                icon: 'copy',
                action: this.copySystemWebhookUrl,
                secondary: true,
            },
            {
                key: 'edit-settings',
                title: this.intl.t('ledger.ui.gateway.details.edit-settings'),
                description: this.intl.t('ledger.ui.gateway.details.edit-settings-desc'),
                expectedResult: this.intl.t('ledger.ui.gateway.details.edit-settings-result'),
                icon: 'pen-to-square',
                action: this.editGateway,
                secondary: true,
            },
        ];
    }

    activityItem(kind, record, icon, fallbackDate) {
        if (!record && !fallbackDate) {
            return null;
        }

        return {
            label: this.intl.t(`ledger.ui.gateway.details.${kind}`),
            icon,
            status: record?.status ?? record?.event_type ?? this.reconciliationStatus ?? 'seen',
            message: record?.message ?? record?.description ?? (kind === 'settlement' ? this.reconciliationStatus : null),
            date: record?.created_at ?? record?.createdAt ?? fallbackDate,
            reference: record?.gateway_reference_id ?? record?.id,
        };
    }

    @task({ restartable: true })
    *loadDiagnostics() {
        if (!this.gatewayId) return;

        try {
            this.diagnostics = yield this.fetch.get(`gateways/${this.gatewayId}/diagnostics`, {}, { namespace: 'ledger/int/v1' });
        } catch {
            this.diagnostics = null;
        }
    }

    @task({ drop: true })
    *testCredentials() {
        yield* this.runGatewayAction('test-credentials', this.intl.t('ledger.ui.gateway.details.credentials-accepted'));
    }

    @task({ drop: true })
    *createTestOrder() {
        yield* this.runGatewayAction('create-test-order', this.intl.t('ledger.ui.gateway.details.test-order-created'));
    }

    @task({ drop: true })
    *registerWebhook() {
        yield* this.runGatewayAction('register-webhook', this.intl.t('ledger.ui.gateway.details.webhook-registered'));
    }

    @action runDiagnosticAction(diagnosticAction) {
        if (diagnosticAction.action) {
            return diagnosticAction.action();
        }

        if (diagnosticAction.confirm) {
            return this.modalsManager.confirm({
                title: diagnosticAction.confirmTitle,
                body: diagnosticAction.confirmBody,
                acceptButtonText: diagnosticAction.title,
                confirm: () => diagnosticAction.task.perform(),
            });
        }

        return diagnosticAction.task.perform();
    }

    @action editGateway() {
        return this.hostRouter.transitionTo('console.ledger.payments.gateways.edit', this.args.resource);
    }

    @action copySystemWebhookUrl() {
        const url = this.args.resource?.system_webhook_url;

        if (!url) {
            return this.notifications.warning(this.intl.t('ledger.ui.gateway.details.no-system-webhook'));
        }

        copyToClipboard(url)
            .then(() => this.notifications.success(this.intl.t('ledger.ui.gateway.details.system-webhook-copied')))
            .catch(() => this.notifications.error(this.intl.t('ledger.ui.gateway.details.copy-webhook-failed')));
    }

    *runGatewayAction(action, successMessage) {
        if (!this.gatewayId) return;

        try {
            const result = yield this.fetch.post(`gateways/${this.gatewayId}/${action}`, {}, { namespace: 'ledger/int/v1' });
            this.lastActionResult = result;
            this.notifications.success(result?.message ?? successMessage);
            yield this.loadDiagnostics.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
