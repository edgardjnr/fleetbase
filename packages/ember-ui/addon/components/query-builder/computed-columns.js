import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class QueryBuilderComputedColumnsComponent extends Component {
    @service intl;

    @service modalsManager;
    @tracked computedColumns = [];

    get typeLabels() {
        return {
            string: this.intl.t('ember-ui.query-builder.computed-columns.types.string'),
            integer: this.intl.t('ember-ui.query-builder.computed-columns.types.integer'),
            decimal: this.intl.t('ember-ui.query-builder.computed-columns.types.decimal'),
            date: this.intl.t('ember-ui.query-builder.computed-columns.types.date'),
            datetime: this.intl.t('ember-ui.query-builder.computed-columns.types.datetime'),
            boolean: this.intl.t('ember-ui.query-builder.computed-columns.types.boolean'),
        };
    }

    get typeIcons() {
        return {
            string: 'text',
            integer: 'hashtag',
            decimal: 'calculator',
            date: 'calendar',
            datetime: 'clock',
            boolean: 'toggle-on',
        };
    }

    constructor() {
        super(...arguments);

        // Initialize with provided computed columns
        if (this.args.computedColumns) {
            this.computedColumns = [...this.args.computedColumns];
        }
    }

    get hasComputedColumns() {
        return this.computedColumns.length > 0;
    }

    @action openEditor(computedColumn = null) {
        this.modalsManager.show('modals/query-builder-computed-column-editor', {
            title: computedColumn ? this.intl.t('ember-ui.query-builder.computed-columns.edit-title') : this.intl.t('ember-ui.query-builder.computed-columns.add'),
            acceptButtonText: computedColumn ? this.intl.t('ember-ui.query-builder.computed-columns.update') : this.intl.t('ember-ui.query-builder.computed-columns.add-button'),
            computedColumn,
            tableName: this.args.tableName ?? this.args.table?.name,
            keepOpen: true,
            confirm: async (modal) => {
                modal.startLoading();

                const editorComponent = modal.getOption('modalComponentInstance');
                if (editorComponent) {
                    const isValid = await editorComponent.validateExpression();
                    if (isValid) {
                        const computedColumn = editorComponent.save();
                        this.saveComputedColumn(computedColumn);
                        return modal.done();
                    }
                }

                modal.stopLoading();
            },
        });
    }

    @action addComputedColumn() {
        this.openEditor();
    }

    @action editComputedColumn(computedColumn) {
        this.openEditor(computedColumn);
    }

    @action saveComputedColumn(computedColumn) {
        // Check if we're editing an existing column
        const existingIndex = this.computedColumns.findIndex((col) => col.name === computedColumn.name);

        if (existingIndex >= 0) {
            // Update existing
            this.computedColumns[existingIndex] = computedColumn;
        } else {
            // Add new
            this.computedColumns = [...this.computedColumns, computedColumn];
        }

        // Notify parent component
        if (this.args.onChange) {
            this.args.onChange(this.computedColumns);
        }
    }

    @action removeComputedColumn(computedColumn) {
        this.computedColumns = this.computedColumns.filter((col) => col.name !== computedColumn.name);

        // Notify parent component
        if (this.args.onChange) {
            this.args.onChange(this.computedColumns);
        }
    }
}
