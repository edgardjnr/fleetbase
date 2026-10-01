import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ModalsIssueAssignComponent extends Component {
    @service intl;
    @service notifications;

    @tracked options = {};
    @tracked issue;
    @tracked selectedAssignee;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.issue = options.issue;
        this.selectedAssignee = this.issue?.assignee;
        this.setupOptions();
    }

    setupOptions() {
        this.options.title = 'Assign Issue';
        this.options.acceptButtonText = 'Assign Issue';
        this.options.acceptButtonIcon = 'user-check';
        this.options.confirm = async (modal) => {
            modal.startLoading();

            try {
                if (this.selectedAssignee?.type === 'customer') {
                    this.notifications.warning(this.intl.t('fleet-ops.ui.modals.issue-assign.customers-cannot-be-assigned-to-issues'));
                    modal.stopLoading();
                    return;
                }

                this.issue.assignee = this.selectedAssignee;
                await this.issue.save();
                this.notifications.success(this.intl.t('fleet-ops.ui.modals.issue-assign.issue-assignment-updated'));

                if (typeof this.options.onSaved === 'function') {
                    await this.options.onSaved(this.issue);
                }

                modal.done();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
    }
}
