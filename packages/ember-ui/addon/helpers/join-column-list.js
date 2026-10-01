import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

export default class JoinColumnListHelper extends Helper {
    @service intl;

    compute([selectedColumns, tableName]) {
        if (!selectedColumns || !selectedColumns.length) {
            return this.intl.t('ember-ui.join-column-list.no-columns');
        }

        return joinColumnList(selectedColumns, tableName);
    }
}

function joinColumnList(selectedColumns, tableName) {
    return selectedColumns
        .map((column) => {
            let columnStr = `${tableName}.${column.name}`;
            if (column.alias) {
                columnStr += ` AS ${column.alias}`;
            }
            return columnStr;
        })
        .join(', ');
}
