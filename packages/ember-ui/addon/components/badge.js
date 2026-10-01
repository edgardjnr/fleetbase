import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

const BADGE_SIZE_CLASS = {
    xxs: 'status-badge-xxs',
    xs: 'status-badge-xs',
    sm: 'status-badge-sm',
    lg: 'status-badge-lg',
};

const BADGE_ICON_SIZE = {
    xxs: '2xs',
    xs: 'xs',
    sm: 'xs',
    lg: 'sm',
};

export default class BadgeComponent extends Component {
    @service intl;

    /**
     * Translated label for known status values (ember-ui.status.<value>).
     * The raw @status is kept untouched for the CSS color class. Returns null
     * when there is no translation, so the template falls back to the original text.
     */
    get translatedText() {
        const value = this.args.text ?? this.args.status;
        if (typeof value !== 'string' || !value.trim()) return null;
        const normalized = value.trim().toLowerCase().replace(/[\s_]+/g, '-');
        if (!/^[a-z0-9-]+$/.test(normalized)) return null;
        const key = `ember-ui.status.${normalized}`;
        return this.intl.exists(key) ? this.intl.t(key) : null;
    }

    get sizeClass() {
        return BADGE_SIZE_CLASS[this.args.size];
    }

    get iconSize() {
        return this.args.iconSize ?? BADGE_ICON_SIZE[this.args.size] ?? 'xs';
    }
}
