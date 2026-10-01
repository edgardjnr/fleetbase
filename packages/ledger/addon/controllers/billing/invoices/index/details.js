import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoicesIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;
    @service invoiceActions;
    @service intl;

    @tracked overlay = null;
    @tracked refundRows = [];

    /**
     * Tab navigation for the details panel.
     *
     * "Line Items" has been removed — they are now displayed inline inside
     * the Invoice::Details component.  "Transactions" remains as its own tab
     * because it loads asynchronously from a separate endpoint.
     */
    get tabs() {
        return [
            { label: this.intl.t('ledger.ui.billing.details'), route: 'billing.invoices.index.details.index' },
            { label: this.intl.t('common.transactions'), route: 'billing.invoices.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const invoice = this.model;
        const buttons = [];

        // Preview — individual button, only when an invoice template is assigned.
        if (invoice?.template_uuid) {
            buttons.push({
                label: this.intl.t('ledger.ui.billing.preview'),
                icon: 'eye',
                type: 'default',
                helpText: this.intl.t('invoice.actions.preview-invoice', { number: invoice.number }),
                onClick: () => this.invoiceActions.previewInvoice(invoice),
            });
        }

        // Edit — individual button, available for open invoice statuses.
        if (!['paid', 'refunded', 'refund_pending', 'partial_refund_pending', 'void', 'cancelled'].includes(invoice?.status)) {
            buttons.push({
                label: this.intl.t('ledger.ui.billing.edit'),
                icon: 'pencil',
                type: 'default',
                helpText: this.intl.t('invoice.actions.edit'),
                onClick: () => this.hostRouter.transitionTo('console.ledger.billing.invoices.index.edit', invoice.id),
            });
        }

        // Dropdown — groups Send, Record Payment, Void, and Copy Invoice URL.
        const dropdownItems = [];

        // Send — draft invoices only.
        if (invoice?.status === 'draft') {
            dropdownItems.push({
                text: this.intl.t('invoice.actions.send'),
                icon: 'paper-plane',
                fn: () => this.sendInvoice(),
            });
        }

        // Record Payment — for open / overdue / partially-paid invoices.
        if (['sent', 'viewed', 'overdue', 'partial'].includes(invoice?.status)) {
            dropdownItems.push({
                text: this.intl.t('invoice.actions.record-payment'),
                icon: 'check-circle',
                fn: () => this.recordPayment(),
            });
        }

        if (this.hasRefunds) {
            dropdownItems.push({
                text: this.intl.t('ledger.ui.billing.view-refunds'),
                icon: 'receipt',
                fn: () => this.viewRefunds(),
            });
        }

        // Refund - paid or partially refunded invoices with remaining paid funds.
        if (this.canIssueRefund) {
            dropdownItems.push({
                text: this.intl.t('ledger.ui.billing.issue-refund'),
                icon: 'undo',
                class: 'text-red-500 hover:text-red-700',
                fn: () => this.issueRefund(),
            });
        }

        // Void — for any non-terminal status.
        if (!['paid', 'refunded', 'refund_pending', 'partial_refund_pending', 'void', 'cancelled'].includes(invoice?.status)) {
            dropdownItems.push({
                text: this.intl.t('invoice.actions.void'),
                icon: 'ban',
                class: 'text-red-500 hover:text-red-700',
                fn: () => this.voidInvoice(),
            });
        }

        // Separator before copy URL.
        if (dropdownItems.length > 0) {
            dropdownItems.push({ separator: true });
        }

        // Copy Invoice URL — always available.
        dropdownItems.push({
            text: this.intl.t('invoice.actions.copy-invoice-url'),
            icon: 'link',
            fn: () => this.invoiceActions.copyInvoiceUrl(invoice),
        });

        if (dropdownItems.length > 0) {
            buttons.push({
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: dropdownItems,
            });
        }

        return buttons;
    }

    get refundedAmount() {
        return Number(this.model?.meta?.refunded_amount ?? 0);
    }

    get remainingRefundableAmount() {
        return Math.max(0, Number(this.model?.amount_paid ?? 0) - this.refundedAmount);
    }

    get canIssueRefund() {
        const status = this.model?.status;

        return ['paid', 'partial', 'partial_refund_pending', 'refund_pending'].includes(status) && this.remainingRefundableAmount > 0;
    }

    get hasRefunds() {
        return Boolean(this.refundRows.length > 0 || this.model?.meta?.last_taler_refund_uri || this.refundedAmount > 0);
    }

    @action async sendInvoice() {
        const invoice = this.model;
        try {
            await this.fetch.post(`invoices/${invoice.id}/send`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success(this.intl.t('ledger.ui.billing.invoice-sent'));
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async recordPayment() {
        const invoice = this.model;
        const options = {
            title: this.intl.t('invoice.actions.record-payment-title', { number: invoice.number }),
            acceptButtonText: this.intl.t('invoice.actions.record-payment'),
            acceptButtonIcon: 'check-circle',
            invoice,
            amount: invoice.balance ?? 0,
            paymentMethod: 'bank_transfer',
            reference: '',
            paymentMethodOptions: [
                { label: this.intl.t('ledger.ui.record-payment.methods.bank-transfer'), value: 'bank_transfer' },
                { label: this.intl.t('ledger.ui.record-payment.methods.cash'), value: 'cash' },
                { label: this.intl.t('ledger.ui.record-payment.methods.cheque'), value: 'cheque' },
                { label: this.intl.t('ledger.ui.record-payment.methods.credit-card'), value: 'credit_card' },
                { label: this.intl.t('ledger.ui.record-payment.methods.debit-card'), value: 'debit_card' },
                { label: 'PayPal', value: 'paypal' },
                { label: 'Stripe', value: 'stripe' },
                { label: this.intl.t('ledger.ui.record-payment.methods.other'), value: 'other' },
            ],
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setPaymentMethod: (value) => {
                options.paymentMethod = value;
            },
            setReference: (event) => {
                options.reference = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning(this.intl.t('ledger.ui.record-payment.invalid-amount'));
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(
                        `invoices/${invoice.id}/record-payment`,
                        {
                            amount: options.amount,
                            payment_method: options.paymentMethod,
                            reference: options.reference || null,
                        },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success(this.intl.t('ledger.ui.record-payment.success'));
                    await invoice.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        };

        this.modalsManager.show('modals/record-payment', options);
    }

    @action async issueRefund() {
        const invoice = this.model;

        try {
            const result = await this.loadRefundOptions(invoice);
            this.refundRows = result.refunds ?? [];
            const refundOptions = (result.options ?? []).map((option) => {
                const gatewayName = option.gateway?.name ?? option.gateway?.driver ?? this.intl.t('ledger.ui.billing.gateway-fallback');
                const driver = option.gateway?.driver ? ` (${option.gateway.driver})` : '';

                return {
                    ...option,
                    label: `${gatewayName}${driver} - ${option.gateway_transaction_id}`,
                };
            });

            if (refundOptions.length === 0) {
                this.notifications.warning(this.intl.t('ledger.ui.billing.no-refundable-payments'));
                return;
            }

            const selectedOption = refundOptions[0];
            const options = {
                title: this.intl.t('ledger.ui.billing.issue-refund-title', { number: invoice.number }),
                acceptButtonText: this.intl.t('ledger.ui.billing.issue-refund'),
                acceptButtonIcon: 'undo',
                acceptButtonScheme: 'danger',
                invoice,
                summary: result.invoice,
                refundOptions,
                selectedGatewayTransactionId: selectedOption.gateway_transaction_id,
                refundMode: 'full',
                amount: selectedOption.refundable_amount,
                reason: '',
                confirm: async (modal) => {
                    const selected = refundOptions.find((option) => option.gateway_transaction_id === options.selectedGatewayTransactionId);

                    if (!selected) {
                        this.notifications.warning(this.intl.t('ledger.ui.billing.select-refundable-payment'));
                        return;
                    }

                    if (!options.amount || options.amount <= 0) {
                        this.notifications.warning(this.intl.t('ledger.ui.billing.refund-amount-positive'));
                        return;
                    }

                    if (options.amount > selected.refundable_amount) {
                        this.notifications.warning(this.intl.t('ledger.ui.billing.refund-amount-exceeds'));
                        return;
                    }

                    this.confirmRefund(invoice, options, selected, modal);
                },
            };

            this.modalsManager.show('modals/issue-refund', options);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    confirmRefund(invoice, options, selected, refundModal) {
        const amountLabel = `${options.amount} ${selected.currency ?? options.summary?.currency ?? invoice.currency}`;
        const gatewayLabel = selected.gateway?.name ?? selected.gateway?.driver ?? this.intl.t('ledger.ui.billing.selected-gateway');
        const talerNote = selected.requires_customer_action ? ` ${this.intl.t('ledger.ui.billing.taler-refund-note')}` : '';

        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.billing.confirm-refund-title', { number: invoice.number }),
            body: `${this.intl.t('ledger.ui.billing.confirm-refund-body', { amount: amountLabel, gateway: gatewayLabel })}${talerNote}`,
            confirm: async (confirmationModal) => {
                confirmationModal.startLoading();

                try {
                    const response = await this.refundInvoice(invoice, options);
                    const responseData = response.data ?? {};
                    const refundUrl = response.refund?.refund_url ?? responseData.refund_url ?? responseData.taler_refund_uri;

                    this.notifications.success(this.intl.t('ledger.ui.billing.refund-issued-success'));
                    await invoice.reload();
                    this.hostRouter.refresh();
                    confirmationModal.done();
                    refundModal.done();

                    if (response.refund) {
                        this.refundRows = [response.refund, ...this.refundRows.filter((refund) => refund.id !== response.refund.id)];
                    }

                    if (refundUrl) {
                        this.showRefundResult(response, refundUrl);
                    }
                } catch (error) {
                    this.notifications.serverError(error);
                    confirmationModal.stopLoading();
                }
            },
        });
    }

    refundInvoice(invoice, options) {
        return this.fetch.post(
            `invoices/${invoice.id}/refund`,
            {
                gateway_transaction_id: options.selectedGatewayTransactionId,
                amount: options.amount,
                reason: options.reason || null,
            },
            { namespace: 'ledger/int/v1' }
        );
    }

    loadRefundOptions(invoice) {
        return this.fetch.get(`invoices/${invoice.id}/refund-options`, {}, { namespace: 'ledger/int/v1' });
    }

    @action async viewRefunds() {
        const invoice = this.model;

        try {
            const result = await this.loadRefundOptions(invoice);
            this.refundRows = result.refunds ?? [];

            this.modalsManager.show('modals/refund-history', {
                title: this.intl.t('ledger.ui.billing.refunds-title', { number: invoice.number }),
                acceptButtonText: this.intl.t('common.done'),
                acceptButtonIcon: 'check',
                refunds: this.refundRows,
                customerEmail: invoice.customerEmail,
                sendRefundUri: (refund, email) => this.sendRefundUri(invoice, refund, email),
                verifyRefundStatus: (refund) => this.verifyRefundStatus(invoice, refund),
                confirm: (modal) => modal.done(),
            });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    showRefundResult(response, refundUrl) {
        this.modalsManager.show('modals/refund-result', {
            title: this.intl.t('ledger.ui.billing.refund-issued-title'),
            acceptButtonText: this.intl.t('common.done'),
            acceptButtonIcon: 'check',
            refundUrl,
            talerRefundUri: response.refund?.taler_refund_uri ?? response.data?.taler_refund_uri,
            refundStatus: response.data?.refund_status ?? response.status,
            walletStatus: response.data?.wallet_status,
            gatewayTransactionId: response.gateway_transaction_id,
            refund: response.refund ?? { id: response.gateway_transaction_id },
            customerEmail: this.model?.customerEmail,
            sendRefundUri: (refund, email) => this.sendRefundUri(this.model, refund, email),
            confirm: (modal) => modal.done(),
        });
    }

    async sendRefundUri(invoice, refund, email) {
        const refundId = refund?.id ?? refund?.uuid ?? refund?.gateway_transaction_id;

        if (!refundId) {
            this.notifications.warning(this.intl.t('ledger.ui.billing.refund-unresolved'));
            return;
        }

        try {
            const response = await this.fetch.post(
                `invoices/${invoice.id}/refunds/${refundId}/send-refund-uri`,
                {
                    email: email || null,
                },
                { namespace: 'ledger/int/v1' }
            );

            this.notifications.success(response?.message ?? this.intl.t('ledger.ui.billing.refund-uri-sent'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    async verifyRefundStatus(invoice, refund) {
        const refundId = refund?.id ?? refund?.uuid ?? refund?.gateway_transaction_id;

        if (!refundId) {
            this.notifications.warning(this.intl.t('ledger.ui.billing.refund-unresolved'));
            return;
        }

        try {
            const response = await this.fetch.post(`invoices/${invoice.id}/refunds/${refundId}/verify-status`, {}, { namespace: 'ledger/int/v1' });

            if (response?.refund) {
                this.refundRows = [response.refund, ...this.refundRows.filter((row) => row.id !== response.refund.id)];
            }

            if (response?.invoice) {
                await invoice.reload();
                this.hostRouter.refresh();
            }

            this.notifications.success(response?.result?.message ?? this.intl.t('ledger.ui.billing.refund-status-verified'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async voidInvoice() {
        const invoice = this.model;
        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.billing.void-title', { number: invoice.number }),
            body: this.intl.t('ledger.ui.billing.void-body'),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`invoices/${invoice.id}/void`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success(this.intl.t('ledger.ui.billing.invoice-voided'));
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
