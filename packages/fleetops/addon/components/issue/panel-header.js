import { inject as service } from '@ember/service';
import Component from '@glimmer/component';

export default class IssuePanelHeaderComponent extends Component {
    @service intl;
    get resource() {
        return this.args.resource;
    }

    get title() {
        return this.resource?.title || this.intl.t('fleet-ops.ui.issue.panel-header.issue-reported-on', { value: this.resource?.createdAt || 'unknown date' });
    }

    get tags() {
        return Array.isArray(this.resource?.tags) ? this.resource.tags.filter(Boolean) : [];
    }

    get subtitle() {
        return [this.resource?.type, this.resource?.category]
            .filter(Boolean)
            .map((value) => value.replace(/[-_]/g, ' '))
            .join(' / ');
    }
}
