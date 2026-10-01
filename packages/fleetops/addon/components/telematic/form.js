import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

const SENSITIVE_METADATA_KEYS = ['password', 'secret', 'session', 'token'];

export default class TelematicFormComponent extends Component {
    @service intl;
    @service fetch;
    @service notifications;
    @tracked providers = [];
    @tracked selectedProvider = this.args.resource?.provider_descriptor ?? null;
    @tracked connectionTestResult;
    @tracked showConnectionDiagnostics = false;
    @tracked lastConnectionTestStartedAt;
    @tracked lastConnectionTestCompletedAt;
    @tracked activeStep = 0;
    @tracked initialProviderApplied = false;

    get setupSteps() {
        return [
            {
                icon: 'plug',
                label: this.intl.t('fleet-ops.ui.telematic.form.provider'),
                complete: this.activeStep > 0 && this.isProviderStepValid,
                active: this.activeStep === 0,
            },
            {
                icon: 'key',
                label: this.intl.t('fleet-ops.ui.telematic.form.credentials'),
                complete: this.activeStep > 1 && this.areCredentialsValid,
                active: this.activeStep === 1,
            },
            {
                icon: 'circle-check',
                label: this.intl.t('fleet-ops.ui.telematic.form.test'),
                complete: this.activeStep > 2 && this.isTestStepValid,
                active: this.activeStep === 2,
            },
            {
                icon: 'link',
                label: this.intl.t('fleet-ops.ui.telematic.form.webhook'),
                complete: this.activeStep > 3 && this.isIntegrationStepValid,
                active: this.activeStep === 3,
            },
            {
                icon: 'clipboard-check',
                label: this.intl.t('fleet-ops.ui.telematic.form.review'),
                complete: false,
                active: this.activeStep === 4,
            },
        ];
    }

    get providerCards() {
        return this.providers.map((provider) => ({
            ...provider,
            selected: this.selectedProvider?.key === provider.key,
        }));
    }

    get hasCredentialValues() {
        const credentials = this.args.resource?.credentials ?? {};
        return Object.values(credentials).some((value) => value !== null && value !== undefined && value !== '');
    }

    get requiredCredentialFields() {
        return (this.selectedProvider?.required_fields ?? []).filter((field) => field.required);
    }

    get credentialFields() {
        return (this.selectedProvider?.required_fields ?? []).filter((field) => !field.advanced && !field.is_endpoint);
    }

    get advancedCredentialFields() {
        return (this.selectedProvider?.required_fields ?? []).filter((field) => field.advanced || field.is_endpoint);
    }

    get hasAdvancedCredentialFields() {
        return this.advancedCredentialFields.length > 0;
    }

    get missingCredentialFields() {
        const credentials = this.args.resource?.credentials ?? {};

        return this.requiredCredentialFields.filter((field) => {
            const value = credentials[field.name];
            return value === null || value === undefined || value === '';
        });
    }

    get isProviderStepValid() {
        return Boolean(this.selectedProvider);
    }

    get areCredentialsValid() {
        return this.isProviderStepValid && this.missingCredentialFields.length === 0;
    }

    get isTestStepValid() {
        return this.areCredentialsValid;
    }

    get isIntegrationStepValid() {
        return Boolean(this.args.resource?.name);
    }

    get isReviewStepValid() {
        return this.isProviderStepValid && this.areCredentialsValid && this.isIntegrationStepValid;
    }

    get isLastStep() {
        return this.activeStep === this.setupSteps.length - 1;
    }

    get canGoBack() {
        return this.activeStep > 0;
    }

    get primaryFooterLabel() {
        if (this.isLastStep) {
            return this.args.isSaving ? this.args.savingLabel : this.args.saveLabel;
        }

        return this.intl.t('fleet-ops.ui.telematic.form.continue');
    }

    get primaryFooterIcon() {
        return this.isLastStep ? 'check' : 'arrow-right';
    }

    get canUsePrimaryAction() {
        if (this.args.isSaving) {
            return false;
        }

        if (this.isLastStep) {
            return this.isReviewStepValid;
        }

        return this.canLeaveStep(this.activeStep);
    }

    get webhookUrl() {
        const url = this.selectedProvider?.webhook_url;
        const id = this.args.resource?.public_id;

        if (!url || !id) {
            return null;
        }

        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}telematic=${id}`;
    }

    get connectionState() {
        if (this.testConnection.isRunning) {
            return 'testing';
        }

        if (!this.connectionTestResult) {
            return 'idle';
        }

        return this.connectionTestResult.success ? 'success' : 'failed';
    }

    get connectionStateTitle() {
        if (this.connectionState === 'testing') {
            return this.intl.t('fleet-ops.ui.telematic.form.testing-provider-credentials');
        }

        if (this.connectionState === 'success') {
            return this.intl.t('fleet-ops.ui.telematic.form.connection-verified');
        }

        if (this.connectionState === 'failed') {
            return this.intl.t('fleet-ops.ui.telematic.form.connection-failed-2');
        }

        return this.intl.t('fleet-ops.ui.telematic.form.ready-to-test');
    }

    get connectionStateMessage() {
        if (this.connectionState === 'testing') {
            return this.intl.t('fleet-ops.ui.telematic.form.sending-the-configured-credentials-to-the');
        }

        if (this.connectionState === 'idle') {
            return this.intl.t('fleet-ops.ui.telematic.form.run-a-connection-test-to-verify');
        }

        if (this.isDuplicateConnectionMessage) {
            return null;
        }

        return this.connectionTestResult?.message;
    }

    get isDuplicateConnectionMessage() {
        const message = this.connectionTestResult?.message;

        if (!message) {
            return false;
        }

        return ['connection successful', 'connection verified'].includes(message.trim().toLowerCase());
    }

    get connectionMetadataEntries() {
        const metadata = this.connectionTestResult?.metadata ?? {};

        return Object.entries(metadata)
            .filter(([key, value]) => {
                if (value === null || value === undefined || value === '') {
                    return false;
                }

                return !this.isSensitiveMetadataKey(key);
            })
            .map(([key, value]) => ({
                label: this.formatMetadataLabel(key),
                value: this.formatDiagnosticValue(value),
            }));
    }

    get hasConnectionMetadata() {
        return this.connectionMetadataEntries.length > 0;
    }

    get connectionDiagnosticEntries() {
        const entries = [];
        const providerLabel = this.selectedProvider?.label ?? this.intl.t('fleet-ops.ui.telematic.form.no-provider-selected');
        const providerKey = this.selectedProvider?.key;
        const startedAt = this.lastConnectionTestStartedAt;
        const completedAt = this.lastConnectionTestCompletedAt;

        entries.push({
            time: this.formatDiagnosticTime(startedAt),
            tone: 'muted',
            text: this.intl.t('fleet-ops.ui.telematic.form.provider-selected', { providerLabel, value: providerKey ? ` (${providerKey})` : '' }),
        });

        if (this.areCredentialsValid) {
            entries.push({
                time: this.formatDiagnosticTime(startedAt),
                tone: 'muted',
                text: this.intl.t('fleet-ops.ui.telematic.form.credentials-prepared-required-fields-present', { length: this.requiredCredentialFields.length }),
            });
        } else {
            entries.push({
                time: this.formatDiagnosticTime(startedAt),
                tone: 'warning',
                text: this.intl.t('fleet-ops.ui.telematic.form.credentials-incomplete-required-fields-missing', { length: this.missingCredentialFields.length }),
            });
        }

        if (this.connectionState === 'idle') {
            entries.push({
                time: this.formatDiagnosticTime(),
                tone: 'muted',
                text: this.intl.t('fleet-ops.ui.telematic.form.credential-test-has-not-been-run'),
            });
            return entries;
        }

        entries.push({
            time: this.formatDiagnosticTime(startedAt),
            tone: 'info',
            text: this.intl.t('fleet-ops.ui.telematic.form.sending-provider-credential-test-request'),
        });

        if (this.connectionState === 'testing') {
            entries.push({
                time: this.formatDiagnosticTime(),
                tone: 'info',
                text: this.intl.t('fleet-ops.ui.telematic.form.awaiting-provider-response'),
            });
            return entries;
        }

        entries.push({
            time: this.formatDiagnosticTime(completedAt),
            tone: 'info',
            text: this.intl.t('fleet-ops.ui.telematic.form.provider-response-received'),
        });

        if (this.hasConnectionMetadata) {
            entries.push({
                time: this.formatDiagnosticTime(completedAt),
                tone: 'muted',
                text: this.intl.t('fleet-ops.ui.telematic.form.provider-metadata', { value: this.connectionMetadataEntries.map((entry) => `${entry.label}=${entry.value}`).join(', ') }),
            });
        }

        if (this.connectionState === 'success') {
            entries.push({
                time: this.formatDiagnosticTime(completedAt),
                tone: 'success',
                text: this.intl.t('fleet-ops.ui.telematic.form.connection-verified'),
            });
            return entries;
        }

        entries.push({
            time: this.formatDiagnosticTime(completedAt),
            tone: 'danger',
            text: this.intl.t('fleet-ops.ui.telematic.form.connection-failed', { value: this.connectionTestResult?.message ? `: ${this.formatDiagnosticValue(this.connectionTestResult.message)}` : '' }),
        });

        return entries;
    }

    get hasConnectionDiagnostics() {
        return this.connectionDiagnosticEntries.length > 0;
    }

    get connectionDiagnosticText() {
        return this.connectionDiagnosticEntries.map((entry) => `[${entry.time}] ${entry.text}`).join('\n');
    }

    formatMetadataLabel(key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    }

    formatDiagnosticValue(value) {
        const sanitizedValue = this.sanitizeDiagnosticValue(value);

        if (typeof sanitizedValue === 'object') {
            return JSON.stringify(sanitizedValue);
        }

        return String(sanitizedValue);
    }

    sanitizeDiagnosticValue(value, seen = new WeakSet()) {
        if (!value || typeof value !== 'object') {
            return value;
        }

        if (seen.has(value)) {
            return '[circular]';
        }

        seen.add(value);

        if (Array.isArray(value)) {
            return value.map((item) => this.sanitizeDiagnosticValue(item, seen));
        }

        return Object.entries(value).reduce((acc, [key, item]) => {
            if (!this.isSensitiveMetadataKey(key)) {
                acc[key] = this.sanitizeDiagnosticValue(item, seen);
            }

            return acc;
        }, {});
    }

    formatDiagnosticTime(date = new Date()) {
        if (!(date instanceof Date)) {
            return '--:--:--';
        }

        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    }

    isSensitiveMetadataKey(key) {
        return SENSITIVE_METADATA_KEYS.some((sensitiveKey) => key.toLowerCase().includes(sensitiveKey));
    }

    resetConnectionTest() {
        this.connectionTestResult = null;
        this.lastConnectionTestStartedAt = null;
        this.lastConnectionTestCompletedAt = null;
    }

    get credentialsActionButtons() {
        return [
            {
                size: 'xs',
                icon: 'plug',
                text: this.intl.t('fleet-ops.ui.telematic.form.test-connection'),
                onClick: () => this.testConnection.perform(),
                isLoading: this.testConnection.isRunning,
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.activeStep = this.selectedProvider ? 1 : 0;
        this.loadProviders.perform();
    }

    @action setCredential(field, { target: { value } }) {
        const credentials = this.args.resource.credentials ?? {};
        this.args.resource.set('credentials', {
            ...credentials,
            [field.name]: value,
        });
        this.resetConnectionTest();
    }

    @action selectProvider(provider) {
        this.applyProvider(provider);
        this.activeStep = 1;
    }

    applyProvider(provider) {
        this.selectedProvider = provider;
        this.resetConnectionTest();
        this.args.resource.setProperties({
            name: this.args.resource.name ?? provider.label,
            provider: provider.key,
            credentials: this.buildProviderCredentials(provider),
        });
    }

    buildProviderCredentials(provider) {
        return (provider.required_fields ?? []).reduce((acc, item) => {
            acc[item.name] = item.default_value ?? null;
            return acc;
        }, {});
    }

    @action goToStep(index) {
        if (!this.canReachStep(index)) {
            return;
        }

        this.activeStep = index;
    }

    @action nextStep() {
        if (!this.validateStep(this.activeStep)) {
            return;
        }

        this.activeStep = Math.min(this.activeStep + 1, this.setupSteps.length - 1);
    }

    @action previousStep() {
        this.activeStep = Math.max(this.activeStep - 1, 0);
    }

    @action primaryFooterAction() {
        if (this.isLastStep) {
            if (!this.validateStep(this.activeStep) || !this.isReviewStepValid) {
                return;
            }

            return this.args.onSave?.();
        }

        return this.nextStep();
    }

    @action toggleConnectionDiagnostics() {
        this.showConnectionDiagnostics = !this.showConnectionDiagnostics;
    }

    @action copyConnectionDiagnostics() {
        copyToClipboard(this.connectionDiagnosticText)
            .then(() => {
                this.notifications.success(this.intl.t('fleet-ops.ui.telematic.form.connection-diagnostics-copied'));
            })
            .catch(() => {
                this.notifications.error(this.intl.t('fleet-ops.ui.telematic.form.unable-to-copy-connection-diagnostics'));
            });
    }

    canReachStep(index) {
        if (index <= this.activeStep) {
            return true;
        }

        for (let step = 0; step < index; step++) {
            if (!this.canLeaveStep(step)) {
                return false;
            }
        }

        return true;
    }

    canLeaveStep(index) {
        if (index === 0) {
            return this.isProviderStepValid;
        }

        if (index === 1) {
            return this.areCredentialsValid;
        }

        if (index === 3) {
            return this.isIntegrationStepValid;
        }

        return true;
    }

    validateStep(index) {
        if (index === 0 && !this.isProviderStepValid) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.telematic.form.choose-a-provider-to-continue'));
            return false;
        }

        if (index === 1 && !this.areCredentialsValid) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.telematic.form.enter-the-required-provider-credentials-to'));
            return false;
        }

        if (index === 3 && !this.isIntegrationStepValid) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.telematic.form.enter-an-integration-name-to-continue'));
            return false;
        }

        if (index === 4 && !this.isReviewStepValid) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.telematic.form.complete-the-required-setup-steps-before'));
            return false;
        }

        return true;
    }

    applyInitialProvider() {
        if (this.initialProviderApplied || !this.args.initialProviderKey || this.selectedProvider) {
            return;
        }

        const provider = this.providers.find((candidate) => candidate.key === this.args.initialProviderKey);

        if (!provider) {
            return;
        }

        this.initialProviderApplied = true;
        this.applyProvider(provider);
        this.activeStep = 1;
    }

    @task *loadProviders() {
        try {
            const providers = yield this.fetch.get('telematics/providers');
            this.providers = providers;
            this.applyInitialProvider();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task *testConnection() {
        this.lastConnectionTestStartedAt = new Date();
        this.lastConnectionTestCompletedAt = null;

        try {
            const result = yield this.fetch.post(`telematics/${this.selectedProvider.key}/test-credentials`, this.getConnectionTestPayload());
            this.connectionTestResult = result;
            this.lastConnectionTestCompletedAt = new Date();
            this.updateResourceConnectionTestMeta(result);

            if (result.success) {
                this.notifications.success(this.intl.t('fleet-ops.ui.telematic.form.connection-successful'));
            } else {
                this.notifications.error(result.message);
            }
        } catch (error) {
            this.lastConnectionTestCompletedAt = new Date();
            this.connectionTestResult = {
                success: false,
                message: error.message || this.intl.t('fleet-ops.ui.telematic.form.connection-test-failed'),
            };
            this.updateResourceConnectionTestMeta(this.connectionTestResult);
            this.notifications.error(this.intl.t('fleet-ops.ui.telematic.form.connection-test-failed'));
        }
    }

    getConnectionTestPayload() {
        return {
            credentials: this.args.resource.credentials,
            telematic_id: this.args.resource?.id,
        };
    }

    updateResourceConnectionTestMeta(result) {
        const meta = this.args.resource?.meta ?? {};
        const testedAt = this.lastConnectionTestCompletedAt ?? new Date();

        this.args.resource?.set('status', result?.success ? 'connected' : 'error');
        this.args.resource?.set('meta', {
            ...meta,
            last_connection_test: testedAt.toISOString(),
            last_test_result: result?.success ? 'success' : 'failed',
            last_error: result?.success ? null : (result?.message ?? 'Connection test failed'),
            last_test_metadata: result?.metadata ?? {},
        });
    }
}
