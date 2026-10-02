import Component from '@glimmer/component';
import countryName from '../utils/country-name';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';
import { task } from 'ember-concurrency';

export default class CountrySelectComponent extends Component {
    @service fetch;
    @service intl;
    @tracked countries = [];
    @tracked selected;
    @tracked disabled = false;
    @tracked value;
    @tracked id = guidFor(this);

    get renderInPlace() {
        return this.args.renderInPlace ?? true;
    }

    constructor(owner, { value = null, disabled = false }) {
        super(...arguments);
        this.disabled = disabled;
        this.value = value;
        this.fetchCountries.perform(value);
    }

    @task *fetchCountries(value = null) {
        try {
            const countries = yield this.fetch.get(
                'lookup/countries',
                { columns: ['name', 'cca2', 'flag', 'emoji'] },
                { fromCache: true, expirationInterval: 1, expirationIntervalUnit: 'week' }
            );
            // show (and search) country names in the active language
            this.countries = Array.isArray(countries)
                ? countries.map((country) => ({ ...country, name: countryName(this.intl?.primaryLocale, country.cca2, country.name) }))
                : countries;
            this.selected = this.findCountry(value);
        } catch (error) {
            this.countries = [];
        }
    }

    @action changed(value) {
        const country = this.findCountry(value);

        if (country) {
            this.selectCountry(country);
        }
    }

    @action handleChange(el, [value]) {
        this.selected = this.findCountry(value);
    }

    @action selectCountry(country) {
        const { onChange } = this.args;
        this.selected = country;

        if (country && typeof onChange === 'function') {
            onChange(country.cca2, country);
        }
    }

    findCountry(iso2) {
        if (typeof iso2 === 'string') {
            return this.countries.find((country) => country.cca2 === iso2.toUpperCase());
        }

        return null;
    }
}
