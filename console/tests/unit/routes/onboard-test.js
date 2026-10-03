import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupTest } from '@fleetbase/console/tests/helpers';

/**
 * Stubs the installation service. `result` is what checkOnboarding() resolves with, or an Error to reject with.
 */
function stubInstallation(owner, result) {
    class InstallationStub extends Service {
        checkOnboarding() {
            return result instanceof Error ? Promise.reject(result) : Promise.resolve(result);
        }
    }

    owner.register('service:installation', InstallationStub);
}

/**
 * Ember's built-in RouterService can't be replaced via owner.register in setupTest, so spy on
 * transitionTo on the instance the route actually holds. Returns the routes it was sent to.
 */
function spyTransitionTo(route) {
    const calls = [];

    Object.defineProperty(route.router, 'transitionTo', {
        configurable: true,
        value: (routeName) => {
            calls.push(routeName);
            return `${routeName}-transition`;
        },
    });

    return calls;
}

module('Unit | Route | onboard', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:onboard');
        assert.ok(route);
    });

    // Entregas: a organização é criada só pela central; /onboard só abre na primeira instalação.
    test('it sends the visitor to the login when the instance already has an organization', async function (assert) {
        stubInstallation(this.owner, { notConfigured: false, shouldOnboard: false });

        const route = this.owner.lookup('route:onboard');
        const calls = spyTransitionTo(route);
        const result = await route.beforeModel({});

        assert.deepEqual(calls, ['auth.login']);
        assert.strictEqual(result, 'auth.login-transition', 'the transition is returned so Ember waits on it');
    });

    test('it opens the onboarding on a first install, with no organization yet', async function (assert) {
        stubInstallation(this.owner, { notConfigured: false, shouldOnboard: true });

        const route = this.owner.lookup('route:onboard');
        const calls = spyTransitionTo(route);
        const result = await route.beforeModel({});

        assert.deepEqual(calls, [], 'no redirect');
        assert.strictEqual(result, undefined);
    });

    test('it hands over to the installer when Fleetbase is not configured', async function (assert) {
        stubInstallation(this.owner, { notConfigured: true, shouldOnboard: false, transition: 'install-transition' });

        const route = this.owner.lookup('route:onboard');
        const calls = spyTransitionTo(route);
        const result = await route.beforeModel({});

        assert.strictEqual(result, 'install-transition');
        assert.deepEqual(calls, [], 'the installer redirect is not replaced by one to the login');
    });

    test('it keeps the onboarding closed and returns to the login when the check fails', async function (assert) {
        stubInstallation(this.owner, new Error('network down'));

        const route = this.owner.lookup('route:onboard');
        const calls = spyTransitionTo(route);
        const result = await route.beforeModel({});

        assert.deepEqual(calls, ['auth.login']);
        assert.strictEqual(result, 'auth.login-transition');
    });
});
