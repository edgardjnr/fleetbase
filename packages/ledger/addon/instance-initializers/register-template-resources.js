/**
 * register-template-resources
 *
 * Registers Ledger model classes as queryable resource types in the
 * TemplateBuilder query form. This runs once per application instance,
 * making Invoice, Transaction, Account, and Wallet available as data
 * sources when building invoice templates.
 */
export function initialize(appInstance) {
    const templateBuilder = appInstance.lookup('service:template-builder');
    if (!templateBuilder) {
        return;
    }
    templateBuilder.registerResourceTypes([
        {
            label: 'Invoice',
            labelKey: 'ledger.ui.template-resources.invoice',
            value: 'Fleetbase\\Ledger\\Models\\Invoice',
            icon: 'file-invoice-dollar',
        },
        {
            label: 'Transaction',
            labelKey: 'ledger.ui.template-resources.transaction',
            value: 'Fleetbase\\Ledger\\Models\\Transaction',
            icon: 'money-bill-transfer',
        },
        {
            label: 'Account',
            labelKey: 'ledger.ui.template-resources.account',
            value: 'Fleetbase\\Ledger\\Models\\Account',
            icon: 'building-columns',
        },
        {
            label: 'Wallet',
            labelKey: 'ledger.ui.template-resources.wallet',
            value: 'Fleetbase\\Ledger\\Models\\Wallet',
            icon: 'wallet',
        },
    ]);
}

export default { initialize };
