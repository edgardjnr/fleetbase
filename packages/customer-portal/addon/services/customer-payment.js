import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import config from 'ember-get-config';

let stripeJsPromise;

export default class CustomerPaymentService extends Service {
    @service intl;
    @tracked stripe;
    @tracked loaded = false;

    async initEmbeddedCheckout() {
        if (!this.stripe) {
            await this.loadAndInitialize();
        }

        if (typeof this.stripe.initEmbeddedCheckout === 'function') {
            return this.stripe.initEmbeddedCheckout(...arguments);
        }

        throw new Error(this.intl.t('customer-portal.ui.payment.not-initialized'));
    }

    getInstance() {
        return this.stripe;
    }

    createStripeInstance(options = {}) {
        const publishableKey = config.stripe?.publishableKey;

        if (!publishableKey) {
            throw new Error(this.intl.t('customer-portal.ui.payment.not-configured'));
        }

        if (typeof window === 'undefined' || typeof window.Stripe !== 'function') {
            throw new Error(this.intl.t('customer-portal.ui.payment.load-failed'));
        }

        this.stripe = window.Stripe(publishableKey, options);
        this.loaded = true;

        return this.stripe;
    }

    async loadAndInitialize(options = {}) {
        await this.load();

        return this.createStripeInstance(options);
    }

    async load() {
        if (typeof window !== 'undefined' && typeof window.Stripe === 'function') {
            this.loaded = true;
            return window.Stripe;
        }

        if (!config.stripe?.publishableKey) {
            this.loaded = false;
            throw new Error(this.intl.t('customer-portal.ui.payment.not-configured'));
        }

        if (stripeJsPromise) {
            await stripeJsPromise;
            this.loaded = typeof window.Stripe === 'function';
            return window.Stripe;
        }

        stripeJsPromise = new Promise((resolve, reject) => {
            const existingScript = document.querySelector('script[data-stripe-js="loaded"]');
            const script = existingScript ?? document.createElement('script');

            script.onload = () => {
                if (typeof window.Stripe === 'function') {
                    resolve(window.Stripe);
                } else {
                    reject(new Error(this.intl.t('customer-portal.ui.payment.load-failed')));
                }
            };
            script.onerror = () => {
                stripeJsPromise = null;
                reject(new Error(this.intl.t('customer-portal.ui.payment.load-failed-connection')));
            };

            if (!existingScript) {
                script.src = 'https://js.stripe.com/v3/';
                script.async = true;
                script.setAttribute('data-stripe-js', 'loaded');
                document.head.appendChild(script);
            }
        });

        await stripeJsPromise;
        this.loaded = typeof window.Stripe === 'function';

        return window.Stripe;
    }
}
