import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ApplicationController extends Controller {
    @service fetch;
    @service intl;

    get navigationItems() {
        return [
            {
                label: this.intl.t('ledger.ui.app.nav.dashboard'),
                description: this.intl.t('ledger.ui.app.nav.dashboard-description'),
                icon: 'chart-simple',
                route: 'console.ledger.home',
                keywords: ['overview', 'metrics', 'finance dashboard'],
            },
            {
                label: this.intl.t('ledger.ui.app.nav.billing'),
                description: this.intl.t('ledger.ui.app.nav.billing-description'),
                icon: 'file-invoice-dollar',
                children: [
                    {
                        label: this.intl.t('ledger.ui.app.nav.invoices'),
                        description: this.intl.t('ledger.ui.app.nav.invoices-description'),
                        icon: 'file-invoice-dollar',
                        route: 'console.ledger.billing.invoices.index',
                        keywords: ['receivables', 'customers', 'payments due'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.invoice-templates'),
                        description: this.intl.t('ledger.ui.app.nav.invoice-templates-description'),
                        icon: 'file-code',
                        route: 'console.ledger.billing.invoice-templates.index',
                        keywords: ['templates', 'invoice design', 'documents'],
                    },
                ],
            },
            {
                label: this.intl.t('ledger.ui.app.nav.payments'),
                description: this.intl.t('ledger.ui.app.nav.payments-description'),
                icon: 'money-bill-transfer',
                children: [
                    {
                        label: this.intl.t('ledger.ui.app.nav.transactions'),
                        description: this.intl.t('ledger.ui.app.nav.transactions-description'),
                        icon: 'money-bill-transfer',
                        route: 'console.ledger.payments.transactions.index',
                        keywords: ['payments', 'charges', 'refunds', 'settlements'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.wallets'),
                        description: this.intl.t('ledger.ui.app.nav.wallets-description'),
                        icon: 'wallet',
                        route: 'console.ledger.payments.wallets.index',
                        keywords: ['balances', 'top ups', 'payouts'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.gateways'),
                        description: this.intl.t('ledger.ui.app.nav.gateways-description'),
                        icon: 'credit-card',
                        route: 'console.ledger.payments.gateways.index',
                        keywords: ['stripe', 'payment providers', 'checkout'],
                    },
                ],
            },
            {
                label: this.intl.t('ledger.ui.app.nav.accounting'),
                description: this.intl.t('ledger.ui.app.nav.accounting-description'),
                icon: 'calculator',
                children: [
                    {
                        label: this.intl.t('ledger.ui.app.nav.chart-of-accounts'),
                        description: this.intl.t('ledger.ui.app.nav.chart-of-accounts-description'),
                        icon: 'sitemap',
                        route: 'console.ledger.accounting.accounts.index',
                        keywords: ['accounts', 'coa', 'accounting'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.journal-entries'),
                        description: this.intl.t('ledger.ui.app.nav.journal-entries-description'),
                        icon: 'book',
                        route: 'console.ledger.accounting.journal.index',
                        keywords: ['journals', 'debits', 'credits'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.general-ledger'),
                        description: this.intl.t('ledger.ui.app.nav.general-ledger-description'),
                        icon: 'scroll',
                        route: 'console.ledger.accounting.general-ledger',
                        keywords: ['ledger', 'posted transactions', 'account activity'],
                    },
                ],
            },
            {
                label: this.intl.t('ledger.ui.app.nav.reports'),
                description: this.intl.t('ledger.ui.app.nav.reports-description'),
                icon: 'chart-line',
                children: [
                    {
                        label: this.intl.t('ledger.ui.app.nav.income-statement'),
                        description: this.intl.t('ledger.ui.app.nav.income-statement-description'),
                        icon: 'chart-line',
                        route: 'console.ledger.reports.income-statement',
                        keywords: ['profit and loss', 'pnl', 'revenue', 'expenses'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.balance-sheet'),
                        description: this.intl.t('ledger.ui.app.nav.balance-sheet-description'),
                        icon: 'scale-balanced',
                        route: 'console.ledger.reports.balance-sheet',
                        keywords: ['assets', 'liabilities', 'equity'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.trial-balance'),
                        description: this.intl.t('ledger.ui.app.nav.trial-balance-description'),
                        icon: 'list-check',
                        route: 'console.ledger.reports.trial-balance',
                        keywords: ['debits', 'credits', 'balances'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.cash-flow'),
                        description: this.intl.t('ledger.ui.app.nav.cash-flow-description'),
                        icon: 'water',
                        route: 'console.ledger.reports.cash-flow',
                        keywords: ['cash', 'inflow', 'outflow'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.ar-aging'),
                        description: this.intl.t('ledger.ui.app.nav.ar-aging-description'),
                        icon: 'clock',
                        route: 'console.ledger.reports.ar-aging',
                        keywords: ['receivables', 'overdue invoices', 'aging'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.wallet-summary'),
                        description: this.intl.t('ledger.ui.app.nav.wallet-summary-description'),
                        icon: 'wallet',
                        route: 'console.ledger.reports.wallet-summary',
                        keywords: ['wallet report', 'balances'],
                    },
                ],
            },
            {
                label: this.intl.t('ledger.ui.app.nav.settings'),
                description: this.intl.t('ledger.ui.app.nav.settings-description'),
                icon: 'gear',
                children: [
                    {
                        label: this.intl.t('ledger.ui.app.nav.invoice-settings'),
                        description: this.intl.t('ledger.ui.app.nav.invoice-settings-description'),
                        icon: 'file-invoice',
                        route: 'console.ledger.settings.invoice',
                        keywords: ['invoice defaults', 'numbering', 'template'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.payment-settings'),
                        description: this.intl.t('ledger.ui.app.nav.payment-settings-description'),
                        icon: 'gear',
                        route: 'console.ledger.settings.payment',
                        keywords: ['payments', 'gateway defaults'],
                    },
                    {
                        label: this.intl.t('ledger.ui.app.nav.accounting-settings'),
                        description: this.intl.t('ledger.ui.app.nav.accounting-settings-description'),
                        icon: 'calculator',
                        route: 'console.ledger.settings.accounting',
                        keywords: ['journal automation', 'posting', 'accounts'],
                    },
                ],
            },
        ];
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery) {
            return [];
        }

        try {
            const response = await this.fetch.get(
                'search',
                {
                    query: trimmedQuery,
                    limit,
                },
                {
                    namespace: 'ledger/int/v1',
                }
            );

            return response.results ?? [];
        } catch (_) {
            return [];
        }
    }
}
