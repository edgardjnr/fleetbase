import Model, { attr, hasMany } from '@ember-data/model';
import { computed } from '@ember/object';
import { inject as service } from '@ember/service';
import { localizeIamDescription, localizeIamType } from '@fleetbase/console/utils/localize-iam-record';
import { localizeIamName } from '@fleetbase/ember-ui/utils/localize-iam-name';
import { format, formatDistanceToNow } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';

export default class PolicyModel extends Model {
    @service intl;

    /** @ids */
    @attr('string') company_uuid;

    /** @relationships */
    @hasMany('permission') permissions;

    /** @attributes */
    @attr('string') name;
    @attr('string') type;
    @attr('string') service;
    @attr('string') guard_name;
    @attr('string') description;
    @attr('boolean') is_mutable;
    @attr('boolean') is_deletable;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return this.serialize();
    }

    /** Name for display: system (FLB managed) records are translated, user records are kept as typed. The stored name is never changed. */
    get localizedName() {
        return localizeIamName(this.intl, this.name, 'policy', this);
    }

    /** Description for display: system (FLB managed) records are translated, user records are kept as typed. */
    get localizedDescription() {
        return localizeIamDescription(this.intl, this, 'policy');
    }

    /** "FLB Managed" / "Organization Managed" for display. */
    get localizedType() {
        return localizeIamType(this.intl, this);
    }

    /** @computed */
    @computed('permissions') get permissionsArray() {
        return this.permissions.toArray();
    }

    @computed('updated_at') get updatedAgo() {
        return formatDistanceToNow(this.updated_at, dateFnsLocaleOptions());
    }

    @computed('updated_at') get updatedAt() {
        return format(this.updated_at, 'yyyy-MM-dd HH:mm');
    }

    @computed('created_at') get createdAgo() {
        return formatDistanceToNow(this.created_at, dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAt() {
        return format(this.created_at, 'yyyy-MM-dd HH:mm');
    }
}
