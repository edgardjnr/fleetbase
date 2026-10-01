import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import widgetText from '../../utils/widget-text';

/**
 * Single card in the widget picker.
 *
 *   <Dashboard::WidgetCard @widget={{w}} @addedCount={{count}}
 *                          @isAdding={{adding}} @onAdd={{fn}}
 *                          @onHover={{fn}} @onUnhover={{fn}} />
 */
export default class DashboardWidgetCardComponent extends Component {
    @service intl;

    /** Widget name, translated when the registry declared a translation key. */
    get displayName() {
        return widgetText(this.intl, this.args.widget, 'name');
    }

    /** Widget description, translated when the registry declared a translation key. */
    get displayDescription() {
        return widgetText(this.intl, this.args.widget, 'description');
    }


    get isAdded() {
        return (this.args.addedCount ?? 0) > 0;
    }

    get isDefault() {
        return Boolean(this.args.widget?.default);
    }

    /** Whichever icon the registry declared; falls back to a neutral puzzle. */
    get iconName() {
        return this.args.widget?.icon || 'puzzle-piece';
    }

    /** Avoid rendering an empty badge row when there's nothing to show. */
    get hasBadges() {
        return this.isDefault || this.isAdded;
    }

    get addLabel() {
        return this.isAdded ? 'add-another' : 'add';
    }

    get addedBadgeText() {
        const n = this.args.addedCount ?? 0;
        return n > 1 ? this.intl.t('ember-ui.dashboard.widget-card.on-dashboard-count', { count: n }) : this.intl.t('ember-ui.dashboard.widget-card.on-dashboard');
    }

    /** Compact inline "Added" indicator. Falls back to "Added" for 1, "Added ×N" for >1. */
    get addedShortBadge() {
        const n = this.args.addedCount ?? 0;
        return n > 1 ? this.intl.t('ember-ui.dashboard.widget-card.added-count', { count: n }) : this.intl.t('ember-ui.dashboard.widget-card.added');
    }

    /** Subtle Tailwind chip colour driven by the registry `category`. */
    get iconAccent() {
        const category = (this.args.widget?.category ?? '').toLowerCase();
        switch (category) {
            case 'kpi tiles':
            case 'kpi':
                return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
            case 'analytics':
                return 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300';
            case 'maps':
                return 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300';
            case 'legacy':
                return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
            default:
                return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
        }
    }
}
