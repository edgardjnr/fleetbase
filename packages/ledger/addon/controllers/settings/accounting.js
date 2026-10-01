import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';

export default class SettingsAccountingController extends Controller {
    @service fetch;
    @service notifications;
    @service store;
    @service currentUser;
    @service intl;

    // ── Tracked settings fields ───────────────────────────────────────────────
    @tracked base_currency = null; // null = "use company default"
    @tracked fiscal_year_start_month = 1;
    @tracked auto_post_journal_entries = false;
    @tracked default_revenue_account_uuid = null;
    @tracked default_expense_account_uuid = null;
    @tracked default_ar_account_uuid = null;
    @tracked default_ap_account_uuid = null;

    // ── Account lists for selectors ───────────────────────────────────────────
    @tracked revenueAccounts = [];
    @tracked expenseAccounts = [];
    @tracked arAccounts = [];
    @tracked apAccounts = [];

    // ── Static options ────────────────────────────────────────────────────────
    currencies = getCurrency();

    get fiscalYearMonthOptions() {
        return [
            { label: this.intl.t('ledger.ui.settings.month.january'), value: 1 },
            { label: this.intl.t('ledger.ui.settings.month.february'), value: 2 },
            { label: this.intl.t('ledger.ui.settings.month.march'), value: 3 },
            { label: this.intl.t('ledger.ui.settings.month.april'), value: 4 },
            { label: this.intl.t('ledger.ui.settings.month.may'), value: 5 },
            { label: this.intl.t('ledger.ui.settings.month.june'), value: 6 },
            { label: this.intl.t('ledger.ui.settings.month.july'), value: 7 },
            { label: this.intl.t('ledger.ui.settings.month.august'), value: 8 },
            { label: this.intl.t('ledger.ui.settings.month.september'), value: 9 },
            { label: this.intl.t('ledger.ui.settings.month.october'), value: 10 },
            { label: this.intl.t('ledger.ui.settings.month.november'), value: 11 },
            { label: this.intl.t('ledger.ui.settings.month.december'), value: 12 },
        ];
    }

    constructor() {
        super(...arguments);
        this.loadAccounts.perform();
        this.getSettings.perform();
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    /**
     * The organisation's currency from the organisations list.
     * currentUser.organizations is @tracked and populated by loadOrganizations()
     * during promiseUser() — it is always available by the time any console
     * route is activated. Reading it in a getter is safe (no store.peekRecord,
     * no mutation). Falls back to 'USD' if the org record is not yet loaded.
     */
    get companyCurrency() {
        const companyId = this.currentUser.companyId;
        if (!companyId) return 'USD';
        const org = this.currentUser.organizations.find((o) => o.uuid === companyId || o.id === companyId);
        return org?.currency ?? 'USD';
    }

    /**
     * The effective base currency: the explicitly saved setting if present,
     * otherwise the organisation's default currency.
     */
    get effectiveCurrency() {
        return this.base_currency || this.companyCurrency;
    }

    get selectedCurrency() {
        return getCurrency(this.effectiveCurrency) ?? null;
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task *loadAccounts() {
        try {
            const allAccounts = yield this.store.query('ledger-account', { limit: 500, sort: 'name' });
            const accounts = allAccounts.toArray();
            this.revenueAccounts = accounts.filter((a) => a.type === 'revenue');
            this.expenseAccounts = accounts.filter((a) => a.type === 'expense');
            this.arAccounts = accounts.filter((a) => a.type === 'asset');
            this.apAccounts = accounts.filter((a) => a.type === 'liability');
        } catch {
            this.revenueAccounts = [];
            this.expenseAccounts = [];
            this.arAccounts = [];
            this.apAccounts = [];
        }
    }

    @task *getSettings() {
        try {
            const { accountingSettings } = yield this.fetch.get('settings/accounting-settings', {}, { namespace: 'ledger/int/v1' });
            if (accountingSettings) {
                // null means "not yet set — use company default"
                this.base_currency = accountingSettings.base_currency ?? null;
                this.fiscal_year_start_month = accountingSettings.fiscal_year_start_month ?? 1;
                this.auto_post_journal_entries = accountingSettings.auto_post_journal_entries ?? false;
                this.default_revenue_account_uuid = accountingSettings.default_revenue_account_uuid ?? null;
                this.default_expense_account_uuid = accountingSettings.default_expense_account_uuid ?? null;
                this.default_ar_account_uuid = accountingSettings.default_ar_account_uuid ?? null;
                this.default_ap_account_uuid = accountingSettings.default_ap_account_uuid ?? null;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post(
                'settings/accounting-settings',
                {
                    accountingSettings: {
                        // Persist null when no override chosen so backend knows
                        // to fall back to company default on future reads.
                        base_currency: this.base_currency || null,
                        fiscal_year_start_month: this.fiscal_year_start_month,
                        auto_post_journal_entries: this.auto_post_journal_entries,
                        default_revenue_account_uuid: this.default_revenue_account_uuid,
                        default_expense_account_uuid: this.default_expense_account_uuid,
                        default_ar_account_uuid: this.default_ar_account_uuid,
                        default_ap_account_uuid: this.default_ap_account_uuid,
                    },
                },
                { namespace: 'ledger/int/v1' }
            );
            this.notifications.success(this.intl.t('ledger.ui.settings.accounting.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action onSelectCurrency(currency) {
        // CurrencySelect passes the full currency object; store the ISO code.
        // Clearing the selection (null/undefined) resets to company default.
        this.base_currency = currency?.code ?? null;
    }

    @action onSelectFiscalYearMonth(option) {
        this.fiscal_year_start_month = option.value;
    }

    _accountId(account) {
        return account ? account.uuid || account.public_id || account.id : null;
    }

    @action onSelectRevenueAccount(account) {
        this.default_revenue_account_uuid = this._accountId(account);
    }

    @action onSelectExpenseAccount(account) {
        this.default_expense_account_uuid = this._accountId(account);
    }

    @action onSelectArAccount(account) {
        this.default_ar_account_uuid = this._accountId(account);
    }

    @action onSelectApAccount(account) {
        this.default_ap_account_uuid = this._accountId(account);
    }

    // ── Computed helpers (account selectors) ──────────────────────────────────

    _matchAccount(list, uuid) {
        if (!uuid) return null;
        return list.find((a) => a.uuid === uuid || a.public_id === uuid || a.id === uuid) ?? null;
    }

    get selectedRevenueAccount() {
        return this._matchAccount(this.revenueAccounts, this.default_revenue_account_uuid);
    }

    get selectedExpenseAccount() {
        return this._matchAccount(this.expenseAccounts, this.default_expense_account_uuid);
    }

    get selectedArAccount() {
        return this._matchAccount(this.arAccounts, this.default_ar_account_uuid);
    }

    get selectedApAccount() {
        return this._matchAccount(this.apAccounts, this.default_ap_account_uuid);
    }
}
