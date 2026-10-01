import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatDistanceStrict, isValid } from 'date-fns';

export default class IssueDetailsComponent extends Component {
    @service intl;
    @service hostRouter;
    @service issueActions;

    get resource() {
        return this.args.resource;
    }

    get tags() {
        return Array.isArray(this.resource?.tags) ? this.resource.tags.filter(Boolean) : [];
    }

    get order() {
        return this.resource?.order;
    }

    get files() {
        return this.resource?.files ?? [];
    }

    get hasFiles() {
        return this.files.length > 0;
    }

    get reporterName() {
        return this.resource?.reporter?.name || this.resource?.reporter_name || this.intl.t('fleet-ops.ui.issue.details.unknown-reporter');
    }

    get reporterInitial() {
        return this.reporterName?.charAt(0)?.toUpperCase() || '?';
    }

    get assigneeName() {
        return this.resource?.assignee?.name || this.resource?.assignee_name || this.intl.t('fleet-ops.ui.issue.details.unassigned');
    }

    get isResolved() {
        return ['resolved', 'completed', 'closed'].includes(this.resource?.status);
    }

    get isReopened() {
        return this.resource?.status === 're_opened';
    }

    get resolutionMeta() {
        return this.resource?.meta?.resolution ?? {};
    }

    get reopenHistory() {
        return Array.isArray(this.resource?.meta?.reopen_history) ? this.resource.meta.reopen_history : [];
    }

    get lastReopen() {
        return this.reopenHistory[this.reopenHistory.length - 1];
    }

    get resolutionIcon() {
        if (this.isResolved) {
            return 'circle-check';
        }

        if (this.isReopened) {
            return 'rotate-left';
        }

        return 'hourglass-half';
    }

    get resolutionTitle() {
        if (this.isResolved) {
            return this.intl.t('fleet-ops.ui.issue.details.issue-closed');
        }

        if (this.isReopened) {
            return this.intl.t('fleet-ops.ui.issue.details.issue-re-opened');
        }

        return this.intl.t('fleet-ops.ui.issue.details.awaiting-resolution');
    }

    get resolutionDescription() {
        if (this.isResolved) {
            return this.resolutionMeta.note || this.intl.t('fleet-ops.ui.issue.details.this-issue-has-been-closed-and');
        }

        if (this.isReopened) {
            return this.lastReopen?.note || this.intl.t('fleet-ops.ui.issue.details.this-issue-was-previously-closed-and');
        }

        return this.intl.t('fleet-ops.ui.issue.details.no-resolution-details-have-been-recorded');
    }

    get resolutionDate() {
        return this.resolutionMeta.closed_at || this.resource?.resolvedAt || this.resource?.resolved_at;
    }

    get resolutionActor() {
        return this.resolutionMeta.closed_by_name;
    }

    get resolutionNote() {
        return this.resolutionMeta.note;
    }

    get timeToResolve() {
        const createdAt = this.dateFromValue(this.resource?.created_at);
        const resolvedAt = this.dateFromValue(this.resource?.resolved_at);

        if (!createdAt || !resolvedAt) {
            return null;
        }

        return formatDistanceStrict(createdAt, resolvedAt);
    }

    get hasLocation() {
        const location = this.resource?.location;
        return Boolean(location?.latitude || location?.longitude || location?.coordinates);
    }

    dateFromValue(value) {
        if (!value) {
            return null;
        }

        const date = value instanceof Date ? value : new Date(value);

        return isValid(date) ? date : null;
    }

    @action viewOrder() {
        if (this.order) {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.order);
        }
    }

    @action viewVehicle() {
        return this.issueActions.viewVehicle(this.resource);
    }

    @action viewDriver() {
        return this.issueActions.viewDriver(this.resource);
    }
}
