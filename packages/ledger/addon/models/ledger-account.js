import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

export default class LedgerAccountModel extends Model {
    @attr('string') public_id;
    @attr('string') company_uuid;
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('string') balance;
    @attr('string') description;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

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
