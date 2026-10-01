import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConsoleAdminOrganizationsDetailsIndexController extends Controller {
    @service intl;

    translateState(value) {
        const key = `console.ui.admin.org.state.${value}`;
        return this.intl.exists(key) ? this.intl.t(key) : value;
    }

    get statusText() {
        return this.translateState(this.statusLabel);
    }

    get onboardingStatusText() {
        return this.translateState(this.organization?.onboarding_completed ? 'complete' : 'incomplete');
    }

    get organization() {
        return this.model;
    }

    get owner() {
        return this.resolveBelongsTo(this.organization?.owner);
    }

    get ownerName() {
        return this.owner?.name;
    }

    get ownerEmail() {
        return this.owner?.email;
    }

    get ownerPhone() {
        return this.owner?.phone;
    }

    get ownerState() {
        return this.owner ? this.intl.t('console.ui.admin.org.assigned') : this.intl.t('console.ui.admin.org.missing');
    }

    get statusLabel() {
        return this.organization?.statusLabel || this.organization?.status || 'active';
    }

    get onboardingState() {
        return this.organization?.onboarding_completed ? this.intl.t('console.ui.admin.org.onboarding-complete') : this.intl.t('console.ui.admin.org.onboarding-incomplete');
    }

    get metrics() {
        return [
            {
                label: this.intl.t('common.users'),
                value: this.organization?.users_count ?? 0,
                caption: this.intl.t('console.ui.admin.org.members'),
                icon: 'users',
                accentClass: 'admin-organization-kpi-accent-blue',
            },
            {
                label: this.intl.t('console.ui.admin.org.owner'),
                value: this.ownerState,
                caption: this.ownerEmail ?? this.intl.t('console.ui.admin.org.needs-assignment'),
                icon: this.owner ? 'user-check' : 'user-slash',
                accentClass: this.owner ? 'admin-organization-kpi-accent-green' : 'admin-organization-kpi-accent-amber',
            },
            {
                label: this.intl.t('console.ui.admin.org.onboarding'),
                value: this.onboardingState,
                caption: this.organization?.onboarding_completed ? this.intl.t('console.ui.admin.org.ready-for-operations') : this.intl.t('console.ui.admin.org.needs-review'),
                icon: this.organization?.onboarding_completed ? 'circle-check' : 'triangle-exclamation',
                accentClass: this.organization?.onboarding_completed ? 'admin-organization-kpi-accent-green' : 'admin-organization-kpi-accent-amber',
            },
            {
                label: this.intl.t('common.status'),
                value: this.statusText,
                caption: this.intl.t('console.ui.admin.org.platform-access'),
                icon: this.statusLabel === 'active' ? 'shield-check' : 'circle-exclamation',
                accentClass: this.statusLabel === 'active' ? 'admin-organization-kpi-accent-green' : 'admin-organization-kpi-accent-rose',
            },
        ];
    }

    resolveBelongsTo(record) {
        if (!record) {
            return null;
        }

        if (record.content) {
            return record.content;
        }

        if (record.isPending || record.isFulfilled === false || typeof record.then === 'function') {
            return null;
        }

        return record;
    }
}
