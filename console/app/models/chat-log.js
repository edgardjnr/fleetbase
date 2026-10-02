import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

export default class ChatLogModel extends Model {
    /** @ids */
    @attr('string') company_uuid;
    @attr('string') chat_channel_uuid;
    @attr('string') initiator_uuid;

    /** @attributes */
    @attr('string') content;
    @attr('string') resolved_content;
    @attr('string') event_type;
    @attr('string') status;
    @attr('array') meta;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    @computed('updated_at') get updatedAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }

        return formatDistanceToNow(this.updated_at, dateFnsLocaleOptions({ addSuffix: true }));
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }

        return formatDate(this.updated_at, 'PP HH:mm', dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAgo() {
        if (!isValidDate(this.created_at)) {
            return null;
        }

        return formatDistanceToNow(this.created_at, dateFnsLocaleOptions({ addSuffix: true }));
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) {
            return null;
        }

        return formatDate(this.created_at, 'PP HH:mm', dateFnsLocaleOptions());
    }
}
