import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { inject as service } from '@ember/service';
import { capitalize } from '@ember/string';
import { pluralize } from 'ember-inflector';
import { format, formatDistanceToNow } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
import humanize from '@fleetbase/ember-core/utils/humanize';

export const parserPermissionName = function (permissionName, index = 0) {
    const parts = permissionName.split(' ');

    if (parts.length >= index + 1) {
        return parts[index];
    }

    return null;
};

export const getPermissionExtension = function (permissionName) {
    return parserPermissionName(permissionName);
};

export const getPermissionAction = function (permissionName) {
    return parserPermissionName(permissionName, 1);
};

export const getPermissionResource = function (permissionName) {
    return parserPermissionName(permissionName, 2);
};

const titleize = function (string) {
    if (typeof string !== 'string') {
        return '';
    }
    return humanize(string)
        .split(' ')
        .map((w) => capitalize(w))
        .join(' ');
};

const smartTitleize = function (string) {
    if (typeof string !== 'string') {
        return '';
    }

    let titleized = titleize(string);
    if (titleized === 'Iam') {
        titleized = titleized.toUpperCase();
    }

    return titleized;
};

/**
 * Permission model for handling and authorizing actions.
 * permission schema: {extension} {action} {resource}
 * action and resource can be wildcards
 *
 * @export
 * @class PermissionModel
 * @extends {Model}
 */
export default class PermissionModel extends Model {
    @service intl;

    /** @attributes */
    @attr('string') name;
    @attr('string') guard_name;
    @attr('string') service;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return {
            name: this.name,
            guard_name: this.guard_name,
            service: this.service,
            created_at: this.created_at,
            updated_at: this.updated_at,
        };
    }

    /** @computed */
    @computed('name') get serviceName() {
        return getPermissionExtension(this.name);
    }

    @computed('name') get extensionName() {
        return getPermissionExtension(this.name);
    }

    @computed('name') get actionName() {
        let action = getPermissionAction(this.name);

        if (action === '*') {
            return 'do anything';
        }

        if (action === 'see') {
            return 'Visibly See';
        }

        return titleize(action);
    }

    @computed('name') get resourceName() {
        return getPermissionResource(this.name);
    }

    @computed('actionName', 'name', 'resourceName', 'extensionName', 'intl.locale') get description() {
        const intl = this.intl;
        const action = getPermissionAction(this.name);
        const extension = smartTitleize(this.extensionName);
        const rawResource = this.resourceName;

        // translated resource name: resource.<plural> / resource.<singular> keys shared by the extensions
        let resource = rawResource ? pluralize(smartTitleize(rawResource)) : '';
        if (rawResource && intl) {
            const slug = String(rawResource).replace(/_/g, '-');
            const key = [`resource.${pluralize(slug)}`, `resource.${slug}`].find((k) => intl.exists(k));
            if (key) resource = intl.t(key).toLowerCase();
        }

        if (!intl) {
            return ['Permission', 'to', this.actionName, action === '*' && resource ? 'with' : '', resource, 'on', extension].filter(Boolean).join(' ');
        }

        if (action === '*') {
            return resource ? intl.t('console.ui.permission-text.full-resource', { resource, extension }) : intl.t('console.ui.permission-text.full', { extension });
        }

        const actionKey = `console.ui.permission-text.actions.${action}`;
        const actionText = intl.exists(actionKey) ? intl.t(actionKey) : this.actionName;
        return resource
            ? intl.t('console.ui.permission-text.action-resource', { action: actionText, resource, extension })
            : intl.t('console.ui.permission-text.action', { action: actionText, extension });
    }

    @computed('updated_at') get updatedAgo() {
        return formatDistanceToNow(this.updated_at, dateFnsLocaleOptions());
    }

    @computed('updated_at') get updatedAt() {
        return format(this.updated_at, 'PPP', dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAgo() {
        return formatDistanceToNow(this.created_at, dateFnsLocaleOptions());
    }

    @computed('created_at') get createdAt() {
        return format(this.created_at, 'yyyy-MM-dd HH:mm');
    }
}
