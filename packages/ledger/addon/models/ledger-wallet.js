import Model, { attr } from '@ember-data/model';
import { inject as service } from '@ember/service';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

export default class LedgerWalletModel extends Model {
    @service intl;

    @attr('string') public_id;
    @attr('string') name;
    @attr('string') description;
    @attr('string') subject_uuid;
    @attr('string') subject_type;
    @attr('raw') subject;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('string') balance;
    @attr('string') formatted_balance;
    @attr('boolean') is_frozen;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

    // ── Owner helpers ──────────────────────────────────────────────────────

    /**
     * Display name of the wallet owner.
     * Tries name → display_name → full_name → email → phone in order.
     */
    @computed('subject')
    get ownerName() {
        const s = this.subject;
        if (!s) {
            return null;
        }
        return s.name || s.display_name || s.full_name || s.email || s.phone || null;
    }

    /**
     * Contact detail (email or phone) of the owner when the subject is a User.
     */
    @computed('subject')
    get ownerContact() {
        const s = this.subject;
        if (!s) {
            return null;
        }
        return s.email || s.phone || null;
    }

    @computed('is_frozen') get is_frozen_label() {
        return this.is_frozen ? this.intl.t('ledger.ui.models.yes') : this.intl.t('ledger.ui.models.no');
    }

    // ── Timestamp helpers ──────────────────────────────────────────────────

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
}
