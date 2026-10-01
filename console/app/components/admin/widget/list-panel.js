import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AdminWidgetListPanelComponent extends Component {
    @service fetch;
    @service router;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get slug() {
        return this.args.options?.slug;
    }

    get title() {
        const key = `console.ui.widgets.admin.${this.slug}.title`;
        if (this.slug && this.intl.exists(key)) {
            return this.intl.t(key);
        }

        return this.args.options?.title ?? this.data?.title ?? this.intl.t('console.ui.widgets.admin.summary');
    }

    get subtitle() {
        return this.args.options?.subtitle ?? this.data?.subtitle;
    }

    get icon() {
        return this.args.options?.icon ?? this.data?.icon ?? 'list';
    }

    get items() {
        return this.data?.items ?? [];
    }

    get emptyText() {
        return this.data?.empty ?? this.intl.t('console.ui.widgets.admin.no-items');
    }

    get drilldownRoute() {
        return this.data?.route ?? this.args.options?.route;
    }

    get drilldownQueryParams() {
        return this.data?.queryParams ?? this.args.options?.queryParams ?? {};
    }

    get hasDrilldown() {
        return Boolean(this.drilldownRoute);
    }

    get endpoint() {
        return `metrics/admin/widgets/${this.slug}`;
    }

    @action openItem(item) {
        const route = item?.route;

        if (!route) {
            return;
        }

        const routeModels = item.routeModels ?? [];
        const queryParams = item.queryParams ?? {};

        return this.router.transitionTo(route, ...routeModels, { queryParams });
    }

    @action openDrilldown() {
        if (!this.hasDrilldown) {
            return;
        }

        return this.router.transitionTo(this.drilldownRoute, { queryParams: this.drilldownQueryParams });
    }

    @task *load() {
        if (!this.slug) {
            this.error = this.intl.t('console.ui.widgets.admin.missing-widget-slug');
            return;
        }

        try {
            this.data = yield this.fetch.get(this.endpoint);
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('console.ui.widgets.admin.load-widget-error');
        }
    }
}
