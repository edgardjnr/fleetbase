import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class TableEmptyStateComponent extends Component {
    @service docsPanel;
    @service intl;

    get isCompact() {
        return this.args.variant === 'compact';
    }

    get hasSearchOrFilter() {
        const searchQuery = this.args.context?.searchQuery;

        return Boolean(this.args.context?.isFiltered || (typeof searchQuery === 'string' && searchQuery.trim().length > 0));
    }

    get title() {
        if (this.hasSearchOrFilter && this.args.filteredTitle) {
            return this.args.filteredTitle;
        }

        return this.args.title ?? this.intl.t('ember-ui.table.no-records-yet');
    }

    get description() {
        if (this.hasSearchOrFilter && this.args.filteredDescription) {
            return this.args.filteredDescription;
        }

        return this.args.description;
    }

    get note() {
        if (this.hasSearchOrFilter && this.args.filteredNote) {
            return this.args.filteredNote;
        }

        return this.args.note;
    }

    get icon() {
        if (this.hasSearchOrFilter && this.args.filteredIcon) {
            return this.args.filteredIcon;
        }

        return this.args.icon ?? 'inbox';
    }

    get wrapperClass() {
        if (this.isCompact) {
            return 'next-table-empty-state next-table-empty-state-compact';
        }

        return 'next-table-empty-state';
    }

    get actionButtonSize() {
        return this.isCompact ? 'sm' : 'md';
    }

    get docsText() {
        return this.args.docsText ?? this.intl.t('ember-ui.table.read-guide');
    }

    get docsTitle() {
        return this.args.docsTitle ?? this.title ?? 'Documentation';
    }

    get docsSource() {
        return this.args.docsSource ?? 'table-empty-state';
    }

    // Entregas RestaurantePro: sem o botão de guia (abria a documentação da Fleetbase) em nenhuma tabela vazia
    get docsTarget() {
        return null;
    }

    @action openDocs() {
        const docsTarget = this.docsTarget;

        if (!docsTarget) {
            return;
        }

        if (this.docsPanel?.open) {
            return this.docsPanel.open(docsTarget, {
                title: this.docsTitle,
                source: this.docsSource,
            });
        }

        if (typeof window !== 'undefined') {
            return window.open(docsTarget, '_docs');
        }
    }
}
