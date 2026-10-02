import EmberNotificationsService from 'ember-cli-notifications/services/notifications';
import { isArray } from '@ember/array';
import { getOwner } from '@ember/application';
import getWithDefault from '../utils/get-with-default';
import apiMessageKeys from '../utils/api-message-keys';

export default class NotificationsService extends EmberNotificationsService {
    serverError(error, fallbackMessage = 'Oops! Something went wrong with your request.', options = {}) {
        if (isArray(error.errors)) {
            const errors = getWithDefault(error, 'errors');
            const errorMessage = getWithDefault(errors, '0', fallbackMessage);

            return this.error(this.localizeServerMessage(errorMessage), options);
        }

        if (error instanceof Error) {
            const errorMessage = getWithDefault(error, 'message', fallbackMessage);
            return this.error(this.localizeServerMessage(errorMessage), options);
        }

        if (typeof error === 'string') {
            return this.error(this.localizeServerMessage(error), options);
        }

        return this.error(this.localizeServerMessage(fallbackMessage), options);
    }

    /**
     * Translate a known English API message (exact match, see utils/api-message-keys) into the
     * active locale. Unknown messages, and anything when no translation exists, are returned as-is.
     */
    localizeServerMessage(message) {
        if (typeof message !== 'string' || !Object.prototype.hasOwnProperty.call(apiMessageKeys, message.trim())) {
            return message;
        }

        try {
            const intl = getOwner(this)?.lookup('service:intl');
            const key = `console.ui.api-errors.${apiMessageKeys[message.trim()]}`;

            return intl && intl.exists(key) ? intl.t(key) : message;
        } catch {
            return message;
        }
    }

    invoke(type, message, ...params) {
        if (typeof message === 'function') {
            this[type](message(...params));
        } else {
            this[type](message);
        }
    }
}
