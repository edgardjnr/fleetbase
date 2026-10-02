import Model, { attr } from '@ember-data/model';
import { inject as service } from '@ember/service';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

export default class LedgerJournalModel extends Model {
    @service intl;

    @attr('string') public_id;
    @attr('string') number;
    @attr('string') type;
    @attr('string') status;
    @attr('string') reference;
    @attr('string') memo;
    @attr('string') currency;
    @attr('string') amount;
    @attr('string') description;
    @attr('string') debit_account_uuid;
    @attr('string') credit_account_uuid;
    @attr('raw') debit_account;
    @attr('raw') credit_account;
    @attr('string') transaction_uuid;
    @attr('boolean') is_system_entry;
    @attr('raw') meta;
    @attr('date') entry_date;
    @attr('date') created_at;
    @attr('date') updated_at;

    get entry_source() {
        return this.is_system_entry ? this.intl.t('ledger.ui.models.journal-source.system') : this.intl.t('ledger.ui.models.journal-source.manual');
    }

    get type_label() {
        const labels = {
            general: this.intl.t('ledger.ui.models.journal-type.general'),
            standard: this.intl.t('ledger.ui.models.journal-type.standard'),
            adjusting: this.intl.t('ledger.ui.models.journal-type.adjusting'),
            closing: this.intl.t('ledger.ui.models.journal-type.closing'),
            reversing: this.intl.t('ledger.ui.models.journal-type.reversing'),
            opening: this.intl.t('ledger.ui.models.journal-type.opening'),
            wallet_transfer: this.intl.t('ledger.ui.models.journal-type.wallet-transfer'),
            wallet_deposit: this.intl.t('ledger.ui.models.journal-type.wallet-deposit'),
            wallet_withdrawal: this.intl.t('ledger.ui.models.journal-type.wallet-withdrawal'),
        };
        return labels[this.type] ?? this.type ?? null;
    }

    @computed('debit_account.name') get debit_account_name() {
        return this.debit_account?.name ?? null;
    }

    @computed('debit_account.code') get debit_account_code() {
        return this.debit_account?.code ?? null;
    }

    @computed('credit_account.name') get credit_account_name() {
        return this.credit_account?.name ?? null;
    }

    @computed('credit_account.code') get credit_account_code() {
        return this.credit_account?.code ?? null;
    }

    @computed('created_at') get createdAtAgo() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDistanceToNow(this.created_at, dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'PP HH:mm', dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAtShort() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'dd, MMM', dateFnsLocaleOptions());
    }

    @computed('updated_at') get updatedAtAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDistanceToNow(this.updated_at, dateFnsLocaleOptions());
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'PP HH:mm', dateFnsLocaleOptions());
    }

    @computed('updated_at') get updatedAtShort() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'dd, MMM', dateFnsLocaleOptions());
    }

    @computed('entry_date') get entryDateAgo() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDistanceToNow(this.entry_date, dateFnsLocaleOptions());
    }

    @computed('entry_date') get entryDate() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDate(this.entry_date, 'PP HH:mm', dateFnsLocaleOptions());
    }

    @computed('entry_date') get entryDateShort() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDate(this.entry_date, 'dd, MMM', dateFnsLocaleOptions());
    }
}
