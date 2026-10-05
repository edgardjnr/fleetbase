import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ConsoleAdminOrganizationsDetailsSettingsController extends Controller {
    @service router;
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;

    @action editOrganization() {
        this.modalsManager.show('modals/edit-organization', {
            title: this.intl.t('console.ui.account.organizations.edit-organization'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            organization: this.model,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.model.save();
                    this.notifications.success(this.intl.t('console.ui.admin.org.updated'));
                    return this.router.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action setStatus(status) {
        this.modalsManager.confirm({
            title: this.intl.t('console.ui.admin.org.update-status-title'),
            body: this.intl.t('console.ui.admin.org.update-status-body', {
                status: this.intl.exists(`console.ui.admin.org.state.${status}`) ? this.intl.t(`console.ui.admin.org.state.${status}`) : status,
            }),
            acceptButtonText: this.intl.t('console.ui.admin.org.update-status'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch(`companies/${this.model.uuid}/status`, { status });
                    this.notifications.success(this.intl.t('console.ui.admin.org.status-updated'));
                    return this.router.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action setOnboarding(completed) {
        this.modalsManager.confirm({
            title: completed ? this.intl.t('console.ui.admin.org.mark-onboarding-complete') : this.intl.t('console.ui.admin.org.mark-onboarding-incomplete'),
            body: completed ? this.intl.t('console.ui.admin.org.mark-onboarding-complete-body') : this.intl.t('console.ui.admin.org.mark-onboarding-incomplete-body'),
            acceptButtonText: completed ? this.intl.t('console.ui.admin.org.mark-complete') : this.intl.t('console.ui.admin.org.mark-incomplete'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch(`companies/${this.model.uuid}/onboarding`, { completed });
                    this.notifications.success(this.intl.t('console.ui.admin.org.onboarding-updated'));
                    return this.router.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
