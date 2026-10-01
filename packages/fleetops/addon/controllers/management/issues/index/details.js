import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexDetailsController extends Controller {
    @service intl;
    @service hostRouter;
    @service issueActions;

    @tracked tabs = [
        {
            route: 'management.issues.index.details.index',
            label: this.intl.t('fleet-ops.ui.controller.management-issues-index-details.overview'),
        },
    ];

    get isClosed() {
        return ['closed', 'resolved', 'completed'].includes(this.model?.status);
    }

    get actionButtons() {
        const workflowItems = [
            {
                label: this.intl.t('fleet-ops.ui.controller.management-issues-index-details.change-status'),
                icon: 'arrows-rotate',
                fn: () => this.issueActions.openStatusModal(this.model, { onSaved: () => this.refreshIssue() }),
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-issues-index-details.assign-issue'),
                icon: 'user-check',
                fn: () => this.issueActions.openAssignModal(this.model, { onSaved: () => this.refreshIssue() }),
            },
        ];

        if (this.isClosed) {
            workflowItems.push({
                label: this.intl.t('fleet-ops.ui.controller.management-issues-index-details.re-open-issue'),
                icon: 'rotate-left',
                fn: () => this.issueActions.confirmReopenIssue(this.model, { onSaved: () => this.refreshIssue() }),
            });
        } else {
            workflowItems.push({
                label: this.intl.t('fleet-ops.ui.controller.management-issues-index-details.close-issue'),
                icon: 'circle-check',
                class: 'text-green-600 dark:text-green-400',
                fn: () => this.issueActions.openCloseIssueModal(this.model, { onSaved: () => this.refreshIssue() }),
            });
        }

        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.edit', this.model),
            },
            {
                icon: 'ellipsis',
                type: 'default',
                items: workflowItems,
            },
        ];
    }

    refreshIssue() {
        return this.model?.reload?.();
    }
}
